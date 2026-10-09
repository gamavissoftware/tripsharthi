<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\TravelReportService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class TravelReportServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const NOW = 1_791_439_200;   // 2026-10-08

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['booking_payments', 'booking_services', 'supplier_payments', 'bookings', 'itineraries', 'trips', 'destinations', 'contacts', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Anita', 'email' => 'a@x.test', 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'R', 'source' => 'meta_lead_ads', 'status' => 'new', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('destinations')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Bali', 'created_at' => '2026-01-01 00:00:00']);
    }

    private function trip(string $status, int $tenant = 1, string $at = '2026-10-02 10:00:00'): int
    {
        $db = db_connect();
        $db->table('trips')->insert(['tenant_id' => $tenant, 'contact_id' => $tenant === 1 ? 1 : null, 'owner_id' => $tenant === 1 ? 1 : null, 'destination_id' => $tenant === 1 ? 1 : null, 'title' => 'T', 'status' => $status, 'created_at' => $at]);
        return (int) $db->insertID();
    }

    private function booking(int $trip, array $o = [], int $tenant = 1): int
    {
        $db = db_connect();
        $db->table('bookings')->insert($o + ['tenant_id' => $tenant, 'trip_id' => $trip, 'booking_ref' => 'TP-' . random_int(1000, 9999), 'title' => 'B', 'status' => 'confirmed', 'subtotal' => 1_000_000, 'cost_total' => 800_000,
            'gst_amount' => 50_000, 'tcs_amount' => 0, 'total_amount' => 1_050_000, 'created_at' => '2026-10-03 10:00:00']);
        return (int) $db->insertID();
    }

    private function svc(): TravelReportService { return new TravelReportService(self::NOW); }

    public function testFunnelRevenueMarginAndBreakdowns(): void
    {
        $won = $this->trip('booked'); $this->booking($won);
        $this->trip('quoted'); $this->trip('lost'); $this->trip('enquiry');
        $r = $this->svc()->report(1, '2026-10-01', '2026-10-31');
        $this->assertSame([4, 2, 1, 1], [$r['funnel']['enquiries'], $r['funnel']['quoted'], $r['funnel']['booked'], $r['funnel']['lost']]);
        $this->assertSame(25.0, $r['funnel']['win_rate']);
        $this->assertSame(50.0, $r['funnel']['quote_to_book']);
        $this->assertSame([1, 1_000_000, 200_000, 20.0], [$r['money']['bookings'], $r['money']['revenue'], $r['money']['margin'], $r['money']['margin_pct']]);
        $this->assertSame('Bali', $r['destinations'][0]['name']);
        $this->assertSame(['meta_lead_ads', 4, 1, 1_000_000], [$r['sources'][0]['source'], $r['sources'][0]['enquiries'], $r['sources'][0]['booked'], $r['sources'][0]['revenue']]);
        $this->assertSame(['Anita', 1_000_000], [$r['agents'][0]['name'], $r['agents'][0]['revenue']]);
        $this->assertSame(1_000_000, $r['trend'][5]['revenue']);                      // last trend month = month of $to
    }

    public function testCancelledBookingsAreExcludedFromRevenueButCounted(): void
    {
        $t = $this->trip('booked');
        $this->booking($t); $this->booking($t, ['status' => 'cancelled']);
        $r = $this->svc()->report(1, '2026-10-01', '2026-10-31');
        $this->assertSame([1, 1], [$r['money']['bookings'], $r['cancelled']]);
    }

    public function testBookingsWithoutCostAreFlaggedAndRangeIsRespected(): void
    {
        $t = $this->trip('booked');
        $this->booking($t, ['cost_total' => 0]);
        $this->booking($t, ['created_at' => '2026-05-01 10:00:00']);                  // outside the range
        $r = $this->svc()->report(1, '2026-10-01', '2026-10-31');
        $this->assertSame([1, 1], [$r['money']['bookings'], $r['money']['bookings_without_cost']]);
    }

    public function testOtherTenantsDataNeverLeaksAndBadRangesRefused(): void
    {
        $this->booking($this->trip('booked', 2), [], 2);
        $r = $this->svc()->report(1, '2026-10-01', '2026-10-31');
        $this->assertSame([0, 0, []], [$r['funnel']['enquiries'], $r['money']['bookings'], $r['destinations']]);
        foreach ([['2026-10-31', '2026-10-01'], ['nope', '2026-10-01'], ['2020-01-01', '2026-10-01']] as [$f, $t]) {
            try { $this->svc()->report(1, $f, $t); $this->fail("accepted $f..$t"); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function testCashAndForecastIncludeOpenQuotesWeightedByWinRate(): void
    {
        $db = db_connect();
        $t = $this->trip('booked'); $b = $this->booking($t);
        $db->table('booking_payments')->insert(['tenant_id' => 1, 'booking_id' => $b, 'label' => 'x', 'amount' => 300_000, 'due_date' => '2026-10-01', 'status' => 'overdue', 'created_at' => '2026-10-01 00:00:00']);
        $db->table('booking_payments')->insert(['tenant_id' => 1, 'booking_id' => $b, 'label' => 'y', 'amount' => 500_000, 'due_date' => '2026-10-20', 'status' => 'pending', 'created_at' => '2026-10-01 00:00:00']);
        $db->table('itineraries')->insert(['tenant_id' => 1, 'trip_id' => $t, 'title' => 'Q', 'status' => 'sent', 'sell_subtotal' => 2_000_000, 'created_at' => '2026-10-04 00:00:00']);
        $this->trip('quoted');
        $r = $this->svc()->report(1, '2026-10-01', '2026-10-31');
        $this->assertSame([300_000, 500_000], [$r['cash']['receivable_overdue'], $r['cash']['receivable_upcoming']]);
        $this->assertSame(500_000, $r['forecast']['collections_30d']);
        $this->assertSame([1, 2_000_000], [$r['forecast']['open_quotes']['count'], $r['forecast']['open_quotes']['value']]);
        $this->assertSame((int) round(2_000_000 * $r['funnel']['quote_to_book'] / 100), $r['forecast']['open_quotes']['weighted_value']);
    }

    // ---- Ads -> bookings ----------------------------------------------------------------------------------------------------------------

    public function testAdsReportGroupsByFirstTouchCampaignAndComputesRoas(): void
    {
        $db = db_connect();
        foreach (['lead_attributions', 'ad_insights_daily', 'conversion_events'] as $t) { $db->table($t)->truncate(); }
        $db->table('contacts')->insert(['id' => 2, 'tenant_id' => 1, 'name' => 'S', 'source' => 'google_lead_forms', 'status' => 'new', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('contacts')->insert(['id' => 3, 'tenant_id' => 1, 'name' => 'T', 'source' => 'manual', 'status' => 'new', 'created_at' => '2026-01-01 00:00:00']);
        $att = static fn (int $contact, string $platform, string $campaign, int $tenant = 1) => ['tenant_id' => $tenant, 'contact_id' => $contact, 'touch' => 'first', 'platform' => $platform, 'campaign_id' => 'c' . $contact, 'campaign_name' => $campaign, 'touched_at' => '2026-10-01 00:00:00', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00'];
        $db->table('lead_attributions')->insert($att(1, 'meta', 'Bali Lead Form'));
        $db->table('lead_attributions')->insert($att(2, 'google', 'Goa Search'));
        $t1 = $this->trip('booked'); $this->booking($t1, ['subtotal' => 2_000_000, 'cost_total' => 1_500_000]);      // contact 1 -> Meta, revenue 20,000
        $t2 = $this->trip('enquiry');                                                                                      // same contact, another enquiry: counts as a lead of the same campaign
        $db->table('trips')->where('id', $t2)->update(['contact_id' => 2]);
        $t3 = $this->trip('quoted'); $db->table('trips')->where('id', $t3)->update(['contact_id' => 3]);                    // no attribution -> direct
        $this->trip('booked', 2);                                                                                          // another tenant: never counted
        $db->table('ad_insights_daily')->insert(['tenant_id' => 1, 'platform' => 'meta', 'campaign_external_id' => 'c1', 'day' => '2026-10-02', 'spend' => 500_000, 'impressions' => 1000, 'clicks' => 50, 'leads' => 3, 'conversations' => 0, 'synced_at' => '2026-10-03 00:00:00']);
        $db->table('ad_insights_daily')->insert(['tenant_id' => 1, 'platform' => 'meta', 'campaign_external_id' => 'c1', 'day' => '2025-01-01', 'spend' => 999_999, 'impressions' => 1, 'clicks' => 1, 'leads' => 0, 'conversations' => 0, 'synced_at' => '2026-10-03 00:00:00']);   // out of range
        $db->table('conversion_events')->insert(['tenant_id' => 1, 'contact_id' => 1, 'trip_id' => $t1, 'platform' => 'meta', 'event_name' => 'Purchase', 'event_id' => 'e1', 'value_amount' => 1, 'currency' => 'INR', 'event_time' => '2026-10-03 00:00:00', 'status' => 'sent', 'created_at' => '2026-10-03 00:00:00', 'updated_at' => '2026-10-03 00:00:00']);

        $r = (new TravelReportService())->ads(1, '2026-10-01', '2026-10-08');
        $by = array_column($r['campaigns'], null, 'campaign');
        $this->assertSame(['meta', 1, 1, 1, 2_000_000, 500_000], [$by['Bali Lead Form']['platform'], $by['Bali Lead Form']['leads'], $by['Bali Lead Form']['quoted'], $by['Bali Lead Form']['bookings'], $by['Bali Lead Form']['revenue'], $by['Bali Lead Form']['gross_margin']]);
        $this->assertSame(['google', 1, 0, 0], [$by['Goa Search']['platform'], $by['Goa Search']['leads'], $by['Goa Search']['quoted'], $by['Goa Search']['bookings']]);
        $this->assertSame(['direct', 1, 1], [$by['(no campaign)']['platform'], $by['(no campaign)']['leads'], $by['(no campaign)']['quoted']]);
        $this->assertSame(['leads' => 3, 'quoted' => 2, 'bookings' => 1, 'revenue' => 2_000_000], $r['totals']);
        $this->assertSame(500_000, $r['ad_spend']);                              // the 2025 row is outside the range
        $this->assertSame(4.0, (float) $r['roas']);                               // 20,000 revenue / 5,000 spend
        $this->assertSame(1, $r['conversion_feedback']['sent']);
        $this->expectException(\InvalidArgumentException::class);
        (new TravelReportService())->ads(1, '2026-10-09', '2026-10-01');
    }
}
