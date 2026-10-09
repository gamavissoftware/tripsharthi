<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Flow\FlowTriggers;
use App\Services\Flow\FlowValidator;
use App\Services\Travel\BookingService;
use App\Services\Travel\TravelFlowRecipes;
use App\Services\Travel\TravelTriggerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class TravelTriggerServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    // 2026-10-08 06:00 UTC = 11:30 IST
    private const NOW = 1_791_439_200;
    private const TODAY = '2026-10-08';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['travel_trigger_log', 'jobs', 'flows', 'flow_runs', 'travelers', 'booking_payments', 'booking_services', 'bookings',
            'itinerary_items', 'itinerary_days', 'itineraries', 'trips', 'deals', 'contacts', 'templates', 'messages', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'T', 'slug' => 't', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Rohit Sharma', 'wa_number' => '+919876543210', 'status' => 'new', 'source' => 'manual', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
    }

    private function svc(): TravelTriggerService { return new TravelTriggerService(self::NOW); }

    private function flow(string $trigger, array $config = [], string $status = 'active'): int
    {
        $db = db_connect();
        $db->table('flows')->insert(['tenant_id' => 1, 'name' => "f-$trigger-" . random_int(1, 99999), 'trigger_type' => $trigger, 'trigger_config' => $config ? json_encode($config) : null,
            'graph' => json_encode(['nodes' => [['id' => 't', 'type' => $trigger, 'position' => ['x' => 0, 'y' => 0], 'data' => []]], 'edges' => []]),
            'status' => $status, 'reentry_policy' => 'always', 'created_at' => '2026-01-01 00:00:00']);
        return (int) $db->insertID();
    }

    private function trip(array $o = []): int
    {
        $db = db_connect();
        $db->table('trips')->insert($o + ['tenant_id' => 1, 'contact_id' => 1, 'title' => 'Bali honeymoon', 'trip_type' => 'honeymoon', 'destination_text' => 'Bali', 'is_international' => 1,
            'start_date' => '2026-10-15', 'end_date' => '2026-10-21', 'nights' => 6, 'adults' => 2, 'children' => 0, 'status' => 'quoted', 'created_at' => '2026-09-01 00:00:00']);
        return (int) $db->insertID();
    }

    private function itinerary(int $tripId, string $status, string $sentAt): int
    {
        $db = db_connect();
        $db->table('itineraries')->insert(['tenant_id' => 1, 'trip_id' => $tripId, 'title' => 'Bali 6N', 'status' => $status, 'grand_total' => 5_172_930, 'share_token' => bin2hex(random_bytes(16)), 'sent_at' => $sentAt, 'created_at' => '2026-09-01 00:00:00']);
        return (int) $db->insertID();
    }

    private function booking(int $tripId, array $o = []): int
    {
        $db = db_connect();
        $db->table('bookings')->insert($o + ['tenant_id' => 1, 'trip_id' => $tripId, 'contact_id' => 1, 'booking_ref' => 'TP-2026-' . random_int(1000, 9999), 'title' => 'Bali', 'status' => 'confirmed',
            'travel_start' => '2026-10-15', 'travel_end' => '2026-10-21', 'is_international' => 1, 'total_amount' => 5_172_930, 'paid_amount' => 1_551_879, 'created_at' => '2026-09-01 00:00:00']);
        return (int) $db->insertID();
    }

    private function jobs(): array
    {
        return array_map(static fn ($r) => json_decode($r['payload'], true), db_connect()->table('jobs')->where('type', 'flow_start')->get()->getResultArray());
    }

    // ---- registry / recipes ---------------------------------------------------------------------

    public function testDbEnumMatchesTheTriggerRegistry(): void
    {
        $col = db_connect()->query("SHOW COLUMNS FROM flows LIKE 'trigger_type'")->getRowArray();
        preg_match_all("/'([a-z_]+)'/", (string) $col['Type'], $m);
        $this->assertEqualsCanonicalizing(FlowTriggers::all(), $m[1], 'flows.trigger_type ENUM drifted from FlowTriggers — add a migration.');
    }

    public function testEveryRecipeInstallsAsValidFlowAndIsIdempotent(): void
    {
        $r = new TravelFlowRecipes();
        $first = $r->install(1);
        $this->assertCount(count(TravelFlowRecipes::all()), $first);
        $again = $r->install(1);
        foreach ($again as $row) { $this->assertFalse($row['created']); }
        $this->assertSame(count($first), (int) db_connect()->table('flows')->countAllResults());

        $flows = db_connect()->table('flows')->get()->getResultArray();
        foreach ($flows as $flow) {
            $this->assertSame('draft', $flow['status']);   // installing never switches anything on
            // Until Meta approves the template the validator must refuse activation — and for no other reason.
            $v = (new FlowValidator())->validate($flow, json_decode($flow['graph'], true), 1);
            $this->assertNotEmpty($v['errors']);
            foreach ($v['errors'] as $e) { $this->assertStringContainsString('is not approved', $e, $flow['name']); }
        }
        db_connect()->table('templates')->update(['meta_status' => 'approved']);
        foreach ($flows as $flow) {                         // approved: fully valid, and no 24h-window policy warnings
            $v = (new FlowValidator())->validate($flow, json_decode($flow['graph'], true), 1);
            $this->assertSame([], $v['errors'], $flow['name']);
            $this->assertSame([], $v['warnings'], $flow['name']);
        }
        $this->assertSame(0, (int) db_connect()->table('messages')->countAllResults());
    }

    // ---- event-driven -----------------------------------------------------------------------------

    public function testQuoteViewedEnqueuesFlowWithCustomerSafeContextExactlyOnce(): void
    {
        $this->flow('quote_viewed');
        $itId = $this->itinerary($this->trip(), 'sent', '2026-10-06 10:00:00');
        $this->assertSame(1, $this->svc()->quoteEvent(1, $itId, 'quote_viewed'));
        $this->assertSame(0, $this->svc()->quoteEvent(1, $itId, 'quote_viewed'));   // second view: no new run

        $jobs = $this->jobs();
        $this->assertCount(1, $jobs);
        $ctx = $jobs[0]['context'];
        $this->assertSame('Bali', $ctx['trip_destination']);
        $this->assertSame('₹51,729', $ctx['quote_total']);
        $this->assertStringContainsString('/#/q/', $ctx['quote_link']);
        $this->assertStringNotContainsString('cost', implode(',', array_keys($ctx)));  // no cost/margin keys, ever
    }

    public function testInternationalFilterSkipsDomesticTrips(): void
    {
        $this->flow('booking_confirmed', ['international' => 'yes']);
        $dom = $this->booking($this->trip(['is_international' => 0]), ['is_international' => 0]);
        $intl = $this->booking($this->trip());
        $this->assertSame(0, $this->svc()->bookingEvent(1, $dom, 'booking_confirmed'));
        $this->assertSame(1, $this->svc()->bookingEvent(1, $intl, 'booking_confirmed'));
    }

    public function testTripTypeFilter(): void
    {
        $this->flow('booking_confirmed', ['trip_type' => 'family']);
        $this->assertSame(0, $this->svc()->bookingEvent(1, $this->booking($this->trip(['trip_type' => 'honeymoon'])), 'booking_confirmed'));
        $this->assertSame(1, $this->svc()->bookingEvent(1, $this->booking($this->trip(['trip_type' => 'family'])), 'booking_confirmed'));
    }

    public function testPaymentReceivedFiresOnceWhenMarkedPaidEvenIfMarkedTwice(): void
    {
        $this->flow('payment_received');
        $b = $this->booking($this->trip());
        db_connect()->table('booking_payments')->insert(['id' => 1, 'tenant_id' => 1, 'booking_id' => $b, 'label' => 'Balance', 'due_date' => '2026-10-10', 'amount' => 1_810_526, 'status' => 'pending', 'created_at' => '2026-09-01 00:00:00']);
        (new BookingService())->markPaid(1, 1, 'upi', 'UTR1');
        (new BookingService())->markPaid(1, 1, 'upi', 'UTR1');
        $jobs = $this->jobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('₹18,105', $jobs[0]['context']['payment_amount']);
    }

    public function testPaymentOverdueFiresAtTheMomentOfTheFlip(): void
    {
        $this->flow('payment_overdue');
        $b = $this->booking($this->trip());
        db_connect()->table('booking_payments')->insert(['id' => 1, 'tenant_id' => 1, 'booking_id' => $b, 'label' => 'Balance', 'due_date' => '2026-10-01', 'amount' => 100_000, 'status' => 'pending', 'created_at' => '2026-09-01 00:00:00']);
        $this->assertSame(1, (new BookingService())->markOverdue(1));
        $this->assertSame(0, (new BookingService())->markOverdue(1));       // already overdue: nothing more
        $this->assertCount(1, $this->jobs());
    }

    // ---- scheduled -----------------------------------------------------------------------------------

    public function testQuoteStaleHonoursDaysAndAudience(): void
    {
        $viewedFlow = $this->flow('quote_stale', ['days' => 2, 'audience' => 'viewed']);
        $trip = $this->trip();
        $this->itinerary($trip, 'viewed', '2026-10-05 10:00:00');                          // 3 days old, viewed -> due
        $this->itinerary($this->trip(), 'sent', '2026-10-05 10:00:00');                    // never opened -> not this flow
        $this->itinerary($this->trip(), 'viewed', '2026-10-07 10:00:00');                  // too fresh
        $this->itinerary($this->trip(['status' => 'booked']), 'viewed', '2026-10-01 10:00:00'); // already booked
        $this->assertSame(1, $this->svc()->scanQuoteStale());
        $this->assertSame(0, $this->svc()->scanQuoteStale());                              // claimed: never twice
        $this->assertNotNull($viewedFlow);
    }

    public function testDepartureSoonFiresOnExactDayAndNeverTwice(): void
    {
        $this->flow('departure_soon', ['days_before' => 7]);
        $due = $this->booking($this->trip(), ['travel_start' => '2026-10-15']);
        $this->booking($this->trip(), ['travel_start' => '2026-10-16']);
        $this->booking($this->trip(), ['travel_start' => '2026-10-15', 'status' => 'cancelled']);
        $this->assertSame(1, $this->svc()->scanDeparture());
        $this->assertSame(0, $this->svc()->scanDeparture());
        $ctx = $this->jobs()[0]['context'];
        $this->assertSame((string) $due, (string) $ctx['booking_id']);
        $this->assertSame('7', $ctx['booking_days_to_departure']);
    }

    public function testPassportExpiringFlagsOnlyInternationalTripsWithShortValidity(): void
    {
        $this->flow('passport_expiring', ['within_days' => 120]);
        $b = $this->booking($this->trip(), ['travel_start' => '2026-12-01', 'travel_end' => '2026-12-08']);
        $dom = $this->booking($this->trip(['is_international' => 0]), ['travel_start' => '2026-12-01', 'travel_end' => '2026-12-08', 'is_international' => 0]);
        $t = static fn (int $bk, string $name, string $exp) => db_connect()->table('travelers')->insert(['tenant_id' => 1, 'booking_id' => $bk, 'full_name' => $name, 'passport_expiry' => $exp, 'created_at' => '2026-09-01 00:00:00']);
        $t($b, 'Short Validity', '2027-03-01');     // < 6 months after 8 Dec 2026 -> flagged
        $t($b, 'Fine Validity', '2029-01-01');
        $t($dom, 'Domestic Trip', '2027-03-01');    // domestic: no passport needed
        $this->assertSame(1, $this->svc()->scanPassports());
        $this->assertSame(0, $this->svc()->scanPassports());
        $this->assertSame('Short Validity', $this->jobs()[0]['context']['traveler_name']);
    }

    public function testLifecycleStartsThenCompletesAndAnnouncesEachOnce(): void
    {
        $this->flow('trip_started');
        $this->flow('trip_completed');
        $trip = $this->trip();
        $b = $this->booking($trip, ['travel_start' => '2026-10-08', 'travel_end' => '2026-10-12']);

        $this->assertSame(1, $this->svc()->advanceLifecycle());                            // departure day
        $this->assertSame('travelling', db_connect()->table('bookings')->where('id', $b)->get()->getRowArray()['status']);
        $this->assertSame('travelling', db_connect()->table('trips')->where('id', $trip)->get()->getRowArray()['status']);
        $this->assertSame(0, $this->svc()->advanceLifecycle());                            // nothing new

        $later = new TravelTriggerService(self::NOW + 6 * 86400);                          // 14 Oct
        $this->assertSame(1, $later->advanceLifecycle());
        $this->assertSame('completed', db_connect()->table('bookings')->where('id', $b)->get()->getRowArray()['status']);
        $this->assertSame(0, $later->advanceLifecycle());
        $this->assertCount(2, $this->jobs());                                              // one started + one completed
    }

    public function testCronGapStillAnnouncesStartBeforeCompletion(): void
    {
        $this->flow('trip_started');
        $this->flow('trip_completed');
        $this->booking($this->trip(), ['travel_start' => '2026-09-20', 'travel_end' => '2026-09-25']); // cron never saw it start
        $this->assertSame(2, $this->svc()->advanceLifecycle());
    }

    public function testInactiveFlowsNeverEnqueue(): void
    {
        $this->flow('quote_viewed', [], 'draft');
        $this->assertSame(0, $this->svc()->quoteEvent(1, $this->itinerary($this->trip(), 'sent', '2026-10-06 10:00:00'), 'quote_viewed'));
    }
}
