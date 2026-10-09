<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\BookingService;
use App\Services\Travel\FxService;
use App\Services\Travel\ItineraryService;
use App\Services\Travel\SupplierPayableService;
use App\Services\Travel\TravelReportService;
use App\Services\Travel\TripService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Exchange rates, foreign-currency costs, supplier payments with forex variance, and the customer's indicative equivalent. MySQL group. */
#[Group('mysql')]
final class MultiCurrencyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const NOW = 1_791_439_200;   // 2026-10-08
    private array $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['departure_seats', 'departures', 'supplier_payments', 'fx_rate_history', 'fx_rates', 'booking_payments', 'booking_services', 'travelers', 'bookings', 'itinerary_items', 'itinerary_days', 'itineraries', 'trips', 'deals', 'pipeline_stages', 'pipelines',
                  'activities', 'supplier_rates', 'suppliers', 'contacts', 'users', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('users')->insert(['id' => 9, 'tenant_id' => 1, 'name' => 'Anita', 'email' => 'a@x.test', 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Rohit', 'wa_number' => '+919876543210', 'status' => 'new', 'source' => 'manual', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $db->table('suppliers')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Bali Villas', 'type' => 'hotel', 'created_at' => '2026-01-01 00:00:00']);
        $this->feed = ['USD' => 0.012, 'EUR' => 0.0105, 'IDR' => 189.0];            // 1 INR = x
    }

    private function fx(?int $now = null): FxService
    {
        return new FxService(fn () => ['status' => 200, 'body' => json_encode(['base' => 'INR', 'rates' => $this->feed])], $now ?? self::NOW);
    }

    private function usd(float $rate = 83.5, float $buffer = 2.0): void { $this->fx()->setRate(1, 'USD', $rate, $buffer, 9); }

    // ---- rates ----------------------------------------------------------------------------------------------------------

    public function testRateRulesAndHistory(): void
    {
        $r = $this->fx()->setRate(1, 'USD', 83.5, 2.0, 9);
        $this->assertSame([83.5, 2.0, 85.17, 'manual'], [$r['rate'], $r['buffer_pct'], $r['cost_rate'], $r['source']]);
        foreach ([[0.0, 'rate'], [-5.0, 'rate']] as [$bad]) { try { $this->fx()->setRate(1, 'USD', $bad); $this->fail('accepted ' . $bad); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); } }
        try { $this->fx()->setRate(1, 'USD', 8.35); $this->fail('typo accepted'); } catch (\DomainException $e) { $this->assertStringContainsString('90% change', $e->getMessage()); }
        $this->assertSame(8.35, $this->fx()->setRate(1, 'USD', 8.35, null, 9, true)['rate']);              // confirmed deliberately
        foreach (['XYZ', 'INR'] as $bad) { try { $this->fx()->setRate(1, $bad, 1.0); $this->fail($bad); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); } }
        $this->assertSame(2, count($this->fx()->history(1, 'USD')));
        $this->assertSame([], $this->fx()->list(2));                                                          // tenant isolation
        $stale = $this->fx(self::NOW + 5 * 86400)->get(1, 'USD');
        $this->assertTrue($stale['stale']);
    }

    public function testAutoRefreshNeverTouchesManualRatesAndSkipsImplausibleJumps(): void
    {
        $this->fx()->setRate(1, 'EUR', 90.0, 2.0, 9);                                                          // manual: the agent's own bank rate
        $this->fx()->addAuto(1, 'USD', 9);
        $this->fx()->addAuto(1, 'IDR', 9);
        $this->feed = ['USD' => 0.0118, 'EUR' => 0.0105, 'IDR' => 5.0];                                       // IDR jumps 38x: a bad feed
        $r = $this->fx(self::NOW + 86400)->refresh(1);
        $this->assertSame(['USD'], $r['updated']);
        $this->assertArrayHasKey('IDR', $r['skipped']);
        $this->assertSame(90.0, $this->fx()->get(1, 'EUR')['rate'], 'manual rate must survive the refresh');
        $this->assertEqualsWithDelta(84.7457627, $this->fx()->get(1, 'USD')['rate'], 1e-5);
        $this->fx()->resumeAuto(1, 'EUR');
        $this->assertEqualsWithDelta(95.2380952, $this->fx()->get(1, 'EUR')['rate'], 1e-5);
        $down = new FxService(fn () => ['status' => 503, 'body' => ''], self::NOW);
        $this->assertNotNull($down->refresh(1)['error']);                                                      // an outage degrades quietly, rates stay
        $this->expectException(\DomainException::class);
        (new FxService(fn () => ['status' => 200, 'body' => json_encode(['rates' => ['USD' => 0.012]])], self::NOW))->addAuto(1, 'LKR', 9);   // no automatic source for LKR
    }

    // ---- quote lines ------------------------------------------------------------------------------------------------------

    private function itinerary(): array
    {
        $trip = (new TripService())->create(1, ['title' => 'Bali', 'contact_id' => 1, 'adults' => 2, 'is_international' => 1, 'start_date' => '2026-12-10', 'end_date' => '2026-12-16'], 9);
        $db = db_connect();
        $db->table('itineraries')->insert(['tenant_id' => 1, 'trip_id' => $trip['id'], 'title' => 'Bali 6N', 'status' => 'draft', 'markup_type' => 'percent', 'markup_value' => 15, 'gst_rate' => 5, 'tcs_rate' => 2, 'is_international' => 1, 'adults' => 2, 'children' => 0, 'nights' => 6,
            'share_token' => bin2hex(random_bytes(16)), 'created_at' => '2026-09-01 00:00:00']);
        return [(int) $trip['id'], (int) $db->insertID()];
    }

    public function testAForeignLineIsConvertedOnceAtTheLockedRateAndNeverLeaksToTheCustomer(): void
    {
        $this->usd();
        [, $itId] = $this->itinerary();
        $svc = new ItineraryService();
        $item = $svc->addItem(1, $itId, ['type' => 'hotel', 'title' => 'Ubud Villa', 'cost_currency' => 'usd', 'unit_cost_fx' => 15_000, 'nights' => 5, 'supplier_id' => 1, 'cost_amount' => 1]);   // a client-supplied INR total is ignored
        $this->assertSame(['USD', 15_000], [$item['cost_currency'], (int) $item['unit_cost_fx']]);
        $this->assertSame(1_277_550, (int) $item['unit_cost']);                                    // $150 x 83.5 x 1.02 = ₹12,775.50 per night
        $this->assertSame(6_387_750, (int) $item['cost_amount']);                                   // x 5 nights
        $this->assertSame(6_387_750, (int) db_connect()->table('itineraries')->where('id', $itId)->get()->getRowArray()['cost_total']);
        $blob = json_encode($svc->full(1, $itId, true));
        foreach (['cost_currency', 'unit_cost_fx', 'fx_rate', 'unit_cost', 'cost_amount', 'USD'] as $leak) { $this->assertStringNotContainsString($leak, $blob, "customer payload leaks $leak"); }
        $this->assertStringContainsString('unit_cost_fx', json_encode($svc->full(1, $itId, false)));   // the agent still sees it
    }

    public function testNoRateMeansNoGuessing(): void
    {
        [, $itId] = $this->itinerary();
        $this->expectException(\RuntimeException::class);
        (new ItineraryService())->addItem(1, $itId, ['type' => 'hotel', 'title' => 'Villa', 'cost_currency' => 'USD', 'unit_cost_fx' => 15_000]);
    }

    public function testRelockRepricesAtTodaysRateUnlessTheQuoteWasAccepted(): void
    {
        $this->usd(83.5, 2.0);
        [, $itId] = $this->itinerary();
        $svc = new ItineraryService();
        $svc->addItem(1, $itId, ['type' => 'transfer', 'title' => 'Airport transfer', 'cost_currency' => 'USD', 'unit_cost_fx' => 4_000]);
        $svc->addItem(1, $itId, ['type' => 'activity', 'title' => 'Local guide', 'unit_cost' => 500_000]);                                 // INR line: must not move
        $before = (int) db_connect()->table('itineraries')->where('id', $itId)->get()->getRowArray()['grand_total'];
        $this->fx()->setRate(1, 'USD', 86.0, null, 9);
        $this->assertSame((int) $before, (int) db_connect()->table('itineraries')->where('id', $itId)->get()->getRowArray()['grand_total'], 'the quote must not drift until someone re-locks it');
        $r = $svc->relockFx(1, $itId);
        $this->assertSame(1, $r['changed']);
        $this->assertGreaterThan($before, $r['new_total']);
        $guide = db_connect()->table('itinerary_items')->where('title', 'Local guide')->get()->getRowArray();
        $this->assertSame(500_000, (int) $guide['unit_cost']);
        db_connect()->table('itineraries')->where('id', $itId)->update(['status' => 'accepted']);
        $this->expectException(\DomainException::class);
        $svc->relockFx(1, $itId);
    }

    public function testAiPlansConvertAForeignRateCardInsteadOfReadingCentsAsRupees(): void
    {
        $this->usd(83.5, 0.0);
        [, $itId] = $this->itinerary();
        db_connect()->table('supplier_rates')->insert(['tenant_id' => 1, 'supplier_id' => 1, 'service_name' => 'Villa per night', 'service_type' => 'hotel', 'unit' => 'per_night', 'currency' => 'USD', 'cost_amount' => 10_000, 'created_at' => '2026-01-01 00:00:00']);
        $rate = db_connect()->table('supplier_rates')->get()->getRowArray();
        (new ItineraryService())->applyPlan(1, $itId, ['days' => [['day_no' => 1, 'title' => 'Arrive', 'items' => [['type' => 'hotel', 'title' => 'Villa', 'rate_id' => $rate['id'], 'nights' => 2]]]]], [$rate]);
        $item = db_connect()->table('itinerary_items')->get()->getRowArray();
        $this->assertSame(['USD', 10_000, 835_000, 1_670_000], [$item['cost_currency'], (int) $item['unit_cost_fx'], (int) $item['unit_cost'], (int) $item['cost_amount']]);   // $100 = ₹8,350 a night, NOT ₹100
    }

    public function testCustomerGetsAnIndicativeEquivalentAtTheMidRate(): void
    {
        $this->usd(83.5, 2.0);
        [, $itId] = $this->itinerary();
        $svc = new ItineraryService();
        $svc->addItem(1, $itId, ['type' => 'activity', 'title' => 'Tour', 'unit_cost' => 4_000_000]);
        db_connect()->table('itineraries')->where('id', $itId)->update(['display_currency' => 'USD']);
        $full = $svc->full(1, $itId, true);
        $total = (int) $full['grand_total'];
        $this->assertSame(\App\Services\Travel\Currency::fromInrPaise($total, 'USD', 83.5), $full['fx_display']['amount']);   // mid rate, NOT the buffered cost rate
        $this->assertStringContainsString('Indicative', $full['fx_display']['note']);
        $this->assertStringContainsString('You pay in Indian rupees', $full['fx_display']['note']);
        db_connect()->table('itineraries')->where('id', $itId)->update(['display_currency' => 'XXX']);
        $this->assertNull($svc->full(1, $itId, true)['fx_display']);                                                           // junk currency shows nothing
    }

    // ---- booking, supplier payments, variance ---------------------------------------------------------------------------------

    /** USD 1,500 villa, quoted at ₹85.17 (83.5 + 2%): ₹127,755 */
    private function bookedService(): array
    {
        $this->usd(83.5, 2.0);
        [, $itId] = $this->itinerary();
        (new ItineraryService())->addItem(1, $itId, ['type' => 'hotel', 'title' => 'Ubud Villa', 'cost_currency' => 'USD', 'unit_cost_fx' => 30_000, 'nights' => 5, 'supplier_id' => 1]);   // $300 x 5 = $1,500
        $b = (new BookingService())->createFromItinerary(1, $itId, [], 9);
        $svc = db_connect()->table('booking_services')->get()->getRowArray();
        return [(int) $b['id'], (int) $svc['id']];
    }

    public function testBookingCarriesTheForeignCostAndTheLockedRate(): void
    {
        [, $sid] = $this->bookedService();
        $s = db_connect()->table('booking_services')->where('id', $sid)->get()->getRowArray();
        $this->assertSame(['USD', 150_000, 0], [$s['cost_currency'], (int) $s['cost_fx'], (int) $s['paid_fx']]);
        $this->assertEqualsWithDelta(85.17, (float) $s['fx_rate'], 1e-6);
        $this->assertSame(12_775_500, (int) $s['cost_amount']);
    }

    public function testPaymentsAreTrackedInForeignCurrencyAndSettlementRealisesTheVariance(): void
    {
        [$bid, $sid] = $this->bookedService();
        $p = new SupplierPayableService(self::NOW);
        $r = $p->pay(1, $sid, ['fx_amount' => 1000, 'amount_rs' => 84_000], 9);                    // $1,000 cost the bank ₹84,000
        $this->assertSame([100_000, 50_000], [$r['fx']['paid'], $r['fx']['outstanding']]);
        $this->assertNull($r['fx']['variance']);                                                    // not settled yet
        $this->assertSame(8_400_000, $r['paid']);
        $this->assertSame(12_775_500, (int) db_connect()->table('booking_services')->where('id', $sid)->get()->getRowArray()['cost_amount']);   // still the quote until settled

        $r = $p->pay(1, $sid, ['fx_amount' => 500, 'amount_rs' => 42_900], 9);                      // the rest, at a worse rate (₹85.80)
        $this->assertSame(0, $r['fx']['outstanding']);
        $this->assertSame(12_690_000, $r['paid']);                                                  // ₹84,000 + ₹42,900 really paid... in paise: 8,400,000 + 4,290,000
        $row = db_connect()->table('booking_services')->where('id', $sid)->get()->getRowArray();
        $this->assertSame([12_690_000, 12_775_500, -85_500], [(int) $row['cost_amount'], (int) $row['cost_quoted_inr'], (int) $row['fx_variance']]);   // we paid ₹855 LESS than quoted
        $b = db_connect()->table('bookings')->where('id', $bid)->get()->getRowArray();
        $this->assertSame([12_690_000, 12_690_000], [(int) $b['cost_total'], (int) $b['supplier_paid']]);       // margin now uses what we really paid
        $this->assertSame([], $p->payables(1)['suppliers']);                                          // settled: nothing left to pay
    }

    public function testRemovingAPaymentRevertsTheServiceToItsQuotedCost(): void
    {
        [, $sid] = $this->bookedService();
        $p = new SupplierPayableService(self::NOW);
        $p->pay(1, $sid, ['fx_amount' => 1500, 'amount_rs' => 127_000], 9);
        $this->assertNotNull(db_connect()->table('booking_services')->where('id', $sid)->get()->getRowArray()['fx_variance']);
        $pid = (int) db_connect()->table('supplier_payments')->get()->getRowArray()['id'];
        $r = $p->delete(1, $pid, 9);
        $row = db_connect()->table('booking_services')->where('id', $sid)->get()->getRowArray();
        $this->assertSame([12_775_500, null, 150_000], [(int) $row['cost_amount'], $row['fx_variance'], $r['fx']['outstanding']]);
    }

    public function testForeignPaymentValidation(): void
    {
        [, $sid] = $this->bookedService();
        $p = new SupplierPayableService(self::NOW);
        foreach ([['fx_amount' => 1600, 'amount_rs' => 135_000], ['fx_amount' => 0, 'amount_rs' => 1000], ['fx_amount' => 100, 'amount_rs' => 0], ['amount_rs' => 84_000],
                  ['fx_amount' => 1000, 'amount_rs' => 8_400]] as $bad) {                              // last one: ₹8,400 for $1,000 = ₹8.40/USD, a dropped zero
            try { $p->pay(1, $sid, $bad); $this->fail('accepted ' . json_encode($bad)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(0, db_connect()->table('supplier_payments')->countAllResults());
    }

    public function testExposureShowsWhatIsOwedAbroadAtTodaysRate(): void
    {
        [, $sid] = $this->bookedService();
        (new SupplierPayableService(self::NOW))->pay(1, $sid, ['fx_amount' => 500, 'amount_rs' => 42_000], 9);
        $this->fx()->setRate(1, 'USD', 90.0, null, 9, true);                                              // the rupee weakened
        $pay = (new SupplierPayableService(self::NOW))->payables(1);
        $e = $pay['fx_exposure'][0];
        $this->assertSame(['USD', 100_000, 9_000_000, 1], [$e['currency'], $e['outstanding_fx'], $e['inr_estimate'], $e['services']]);     // $1,000 still owed = ₹90,000 now
        $this->assertSame(9_000_000, $pay['totals']['outstanding']);
        $this->assertSame(['USD', 100_000], [$pay['suppliers'][0]['items'][0]['fx']['currency'], $pay['suppliers'][0]['items'][0]['fx']['outstanding']]);
    }

    public function testReportShowsExposureAndRealisedVariance(): void
    {
        [, $sid] = $this->bookedService();
        (new SupplierPayableService(self::NOW))->pay(1, $sid, ['fx_amount' => 1500, 'amount_rs' => 130_000], 9);   // paid ₹2,445 MORE than quoted
        db_connect()->table('bookings')->update(['created_at' => '2026-10-02 10:00:00']);
        $r = (new TravelReportService(self::NOW))->report(1, '2026-10-01', '2026-10-31');
        $this->assertSame([1, 224_500], [$r['fx']['settled_services'], $r['fx']['realised_variance']]);
        $this->assertSame([], $r['fx']['exposure']);
    }

    public function testACurrencyStillInUseCannotBeRemoved(): void
    {
        $this->bookedService();
        try { $this->fx()->remove(1, 'USD', 9); $this->fail('removed a currency with unpaid services'); } catch (\DomainException $e) { $this->assertStringContainsString('unpaid supplier service', $e->getMessage()); }
        $this->fx()->setRate(1, 'EUR', 90.0, null, 9);
        $this->fx()->remove(1, 'EUR', 9);
        $this->assertNull($this->fx()->get(1, 'EUR'));
    }

    public function testQuotePdfDataCarriesTheIndicativeLineButNoCost(): void
    {
        $this->usd(83.5, 2.0);
        [, $itId] = $this->itinerary();
        (new ItineraryService())->addItem(1, $itId, ['type' => 'hotel', 'title' => 'Ubud Villa', 'cost_currency' => 'USD', 'unit_cost_fx' => 30_000, 'nights' => 2]);
        db_connect()->table('itineraries')->where('id', $itId)->update(['display_currency' => 'USD']);
        $data = (new \App\Services\Billing\Docs\QuoteDocument())->data(1, $itId, false);
        $this->assertStringStartsWith('$', $data['pricing']['fx']['formatted']);
        $blob = json_encode($data);
        foreach (['unit_cost_fx', 'cost_currency', 'fx_rate', 'cost_amount', 'margin'] as $leak) { $this->assertStringNotContainsString($leak, $blob, $leak); }
    }
}
