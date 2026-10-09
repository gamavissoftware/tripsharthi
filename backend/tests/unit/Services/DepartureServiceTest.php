<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\BookingService;
use App\Services\Travel\DeparturePricing;
use App\Services\Travel\DepartureService;
use App\Services\Travel\TripService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Pricing/state helpers are pure; inventory behaviour is MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class DepartureServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const NOW = 1_791_439_200;   // 2026-10-08 11:30 IST

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['departure_seats', 'departures', 'booking_payments', 'booking_services', 'travelers', 'bookings', 'itinerary_items', 'itinerary_days', 'itineraries', 'trips', 'deals', 'pipeline_stages', 'pipelines', 'activities', 'contacts', 'users', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('users')->insert(['id' => 9, 'tenant_id' => 1, 'name' => 'Anita', 'email' => 'a@x.test', 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Rohit', 'wa_number' => '+919876543210', 'status' => 'new', 'source' => 'manual', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
    }

    private function svc(?int $now = null): DepartureService { return new DepartureService($now ?? self::NOW); }

    private function dep(array $o = []): int
    {
        return (int) $this->svc()->save(1, $o + ['code' => 'BALI-' . random_int(1000, 9999), 'title' => 'Bali Group Tour', 'start_date' => '2026-12-10', 'end_date' => '2026-12-16', 'total_seats' => 10, 'min_pax' => 6,
            'price_pax_rs' => 50000, 'cost_pax_rs' => 40000, 'child_price_pct' => 75, 'single_supplement_rs' => 8000, 'single_supplement_cost_rs' => 6000, 'is_international' => 1, 'gst_rate' => 5], null, 9)['id'];
    }

    private function trip(): int
    {
        return (int) (new TripService())->create(1, ['title' => 'Group enquiry', 'contact_id' => 1, 'adults' => 2, 'is_international' => 1], 9)['id'];
    }

    // ---- pure ----------------------------------------------------------------------------------------------------

    public function testPricingStateAndRiskRules(): void
    {
        $d = ['price_pax' => 5_000_000, 'cost_pax' => 4_000_000, 'child_price_pct' => 75, 'single_supplement' => 800_000, 'single_supplement_cost' => 600_000];
        $q = DeparturePricing::quote($d, 2, 1, 1);
        $this->assertSame([5_000_000 * 2 + 3_750_000 + 800_000, 4_000_000 * 2 + 3_000_000 + 600_000, 3], [$q['sell'], $q['cost'], $q['seats']]);
        foreach ([[0, 0, 0], [2, 0, 3], [2, -1, 0]] as [$a, $c, $s]) { try { DeparturePricing::quote($d, $a, $c, $s); $this->fail('accepted bad counts'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); } }
        $this->assertSame(0, DeparturePricing::available(10, 8, 5));          // never negative
        $dep = ['status' => 'open', 'start_date' => '2026-12-10', 'end_date' => '2026-12-16', 'sell_cutoff' => null, 'min_pax' => 6];
        $this->assertSame('open', DeparturePricing::state($dep, 3, 7, '2026-10-08'));
        $this->assertSame('full', DeparturePricing::state($dep, 0, 10, '2026-10-08'));
        $this->assertSame('closed', DeparturePricing::state($dep, 3, 7, '2026-12-10'));
        $this->assertSame('closed', DeparturePricing::state(array_merge($dep, ['sell_cutoff' => '2026-10-01']), 3, 7, '2026-10-08'));
        $this->assertSame('completed', DeparturePricing::state($dep, 3, 7, '2026-12-20'));
        $this->assertTrue(DeparturePricing::atRisk(['min_pax' => 6, 'status' => 'open', 'start_date' => '2026-10-25'] , 3, '2026-10-08'));
        $this->assertFalse(DeparturePricing::atRisk(['min_pax' => 6, 'status' => 'open', 'start_date' => '2026-12-25'], 3, '2026-10-08'));   // not close yet
        $this->assertFalse(DeparturePricing::atRisk(['min_pax' => 6, 'status' => 'open', 'start_date' => '2026-10-25'], 6, '2026-10-08'));   // enough travellers
    }

    // ---- inventory -------------------------------------------------------------------------------------------------

    public function testReserveConsumesSeatsAndDraftsAnExactlyPricedItinerary(): void
    {
        $d = $this->dep(); $t = $this->trip();
        $r = $this->svc()->reserve(1, $d, $t, 2, 1, 1, 24, 9);
        $this->assertSame(3, $r['seats']);
        $view = $this->svc()->show(1, $d);
        $this->assertSame([10, 0, 3, 7], [$view['total_seats'], $view['confirmed'], $view['held'], $view['available']]);
        $it = db_connect()->table('itineraries')->where('id', $r['itinerary_id'])->get()->getRowArray();
        $this->assertSame($r['sell'], (int) $it['sell_subtotal']);                         // 2*50000 + 1*37500 + 8000 single
        $this->assertSame(14_550_000, (int) $it['sell_subtotal']);            // 2 adults 1,00,000 + child 37,500 + single 8,000 (rupees x 100)
        $this->assertSame((int) round($r['sell'] * 0.05), (int) $it['gst_amount']);
        $trip = db_connect()->table('trips')->where('id', $t)->get()->getRowArray();
        $this->assertSame(['2026-12-10', '2026-12-16'], [$trip['start_date'], $trip['end_date']]);
    }

    public function testTheLastSeatCannotBeOversoldAndAnEnquiryCannotHoldTwice(): void
    {
        $d = $this->dep(['total_seats' => 4, 'min_pax' => 0]);
        $a = $this->trip(); $b = $this->trip();
        $this->svc()->reserve(1, $d, $a, 3, 0, 0);
        try { $this->svc()->reserve(1, $d, $b, 2, 0, 0); $this->fail('oversold'); } catch (\DomainException $e) { $this->assertStringContainsString('Only 1 seat', $e->getMessage()); }
        $this->svc()->reserve(1, $d, $b, 1, 0, 0);
        $this->assertSame(0, $this->svc()->show(1, $d)['available']);
        $this->assertSame('full', $this->svc()->show(1, $d)['state']);
        try { $this->svc()->reserve(1, $d, $this->trip(), 1, 0, 0); $this->fail('sold a full departure'); } catch (\DomainException $e) { $this->assertStringContainsString('full', $e->getMessage()); }
        $d2 = $this->dep();
        $this->expectException(\DomainException::class);
        $this->svc()->reserve(1, $d2, $a, 1, 0, 0);                                          // trip $a already holds seats elsewhere
    }

    public function testExpiredHoldsFreeTheSeatsAndAreTidiedByTheCron(): void
    {
        $d = $this->dep();
        $this->svc()->reserve(1, $d, $this->trip(), 4, 0, 0, 1);                             // 1-hour hold
        $this->assertSame(6, $this->svc()->show(1, $d)['available']);
        $later = $this->svc(self::NOW + 2 * 3600);
        $this->assertSame(10, $later->show(1, $d)['available']);                             // lapsed -> not counted, no cron needed
        $this->assertSame(1, $later->expireHolds());
        $this->assertSame('hold expired', db_connect()->table('departure_seats')->get()->getRowArray()['released_reason']);
    }

    public function testBookingConfirmsSeatsAndCancellingReleasesThem(): void
    {
        $d = $this->dep(); $t = $this->trip();
        $r = $this->svc()->reserve(1, $d, $t, 2, 0, 0, 24, 9);
        $booking = (new BookingService())->createFromItinerary(1, $r['itinerary_id'], [], 9);
        $seat = db_connect()->table('departure_seats')->get()->getRowArray();
        $this->assertSame(['confirmed', (int) $booking['id']], [$seat['status'], (int) $seat['booking_id']]);
        $this->assertSame([2, 0, 8], [$this->svc()->show(1, $d)['confirmed'], $this->svc()->show(1, $d)['held'], $this->svc()->show(1, $d)['available']]);
        try { $this->svc()->release(1, (int) $seat['id']); $this->fail('released seats owned by a booking'); } catch (\DomainException) { $this->addToAssertionCount(1); }
        $this->assertSame(1, $this->svc()->releaseForBooking(1, (int) $booking['id']));
        $this->assertSame(10, $this->svc()->show(1, $d)['available']);
    }

    public function testBookingAfterAnExpiredHoldWhoseSeatsWereTakenIsRefused(): void
    {
        $d = $this->dep(['total_seats' => 4, 'min_pax' => 0]);
        $t1 = $this->trip();
        $r1 = $this->svc()->reserve(1, $d, $t1, 3, 0, 0, 1);
        $later = $this->svc(self::NOW + 2 * 3600);
        $later->reserve(1, $d, $this->trip(), 3, 0, 0, 24);                                  // someone else takes the freed seats
        $before = db_connect()->table('bookings')->countAllResults();
        try { (new BookingService())->createFromItinerary(1, $r1['itinerary_id'], [], 9); $this->fail('oversold via an expired hold'); }
        catch (\DomainException $e) { $this->assertStringContainsString('expired', $e->getMessage()); }
        $this->assertSame($before, db_connect()->table('bookings')->countAllResults(), 'no booking row may be left behind');
    }

    public function testShrinkingBelowSoldSeatsDuplicateCodesAndCancelRulesAreEnforced(): void
    {
        $d = $this->dep(['code' => 'DUP-1']); $this->svc()->reserve(1, $d, $this->trip(), 5, 0, 0);
        try { $this->svc()->save(1, ['title' => 'x', 'start_date' => '2026-12-10', 'end_date' => '2026-12-16', 'total_seats' => 4], $d); $this->fail('shrunk below held seats'); } catch (\DomainException) { $this->addToAssertionCount(1); }
        try { $this->dep(['code' => 'dup-1']); $this->fail('duplicate code'); } catch (\DomainException) { $this->addToAssertionCount(1); }
        foreach ([['total_seats' => 0], ['end_date' => '2026-12-01'], ['min_pax' => 99], ['gst_rate' => 7], ['title' => ' ']] as $bad) {
            try { $this->dep($bad); $this->fail('accepted ' . json_encode($bad)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $c = $this->svc()->setStatus(1, $d, 'cancelled');                                    // only HELD seats: allowed, and they are released
        $this->assertSame([0, 10], [$c['held'], $c['available']]);
    }

    public function testManifestAndCsvListConfirmedTravellersWithoutPrices(): void
    {
        $d = $this->dep(); $t = $this->trip();
        $r = $this->svc()->reserve(1, $d, $t, 2, 0, 1, 24, 9);
        $b = (new BookingService())->createFromItinerary(1, $r['itinerary_id'], [], 9);
        db_connect()->table('travelers')->insert(['tenant_id' => 1, 'booking_id' => $b['id'], 'full_name' => '=Rohit Sharma', 'pax_type' => 'adult', 'passport_expiry' => '2030-01-01', 'visa_status' => 'pending', 'created_at' => '2026-09-01 00:00:00']);
        $m = $this->svc()->manifest(1, $d);
        $this->assertSame([1, 1], [count($m['groups']), $m['named_travellers']]);
        $csv = $this->svc()->manifestCsv(1, $d);
        $this->assertStringContainsString("'=Rohit Sharma", $csv);                           // formula injection neutralised
        $this->assertStringContainsString('2030-01-01', $csv);
        foreach (['50000', '40000', 'price', 'cost'] as $leak) { $this->assertStringNotContainsStringIgnoringCase($leak, $csv); }
    }

    public function testOtherTenantsCannotSeeOrReserveYourDeparture(): void
    {
        $d = $this->dep();
        $this->assertSame([], $this->svc()->list(2));
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->show(2, $d);
    }
}
