<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\BookingService;
use App\Services\Travel\ItineraryService;
use App\Services\Travel\TripService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** The CRM deal behind a trip must carry the real price, and a cancelled booking must not leave the deal Won. MySQL group. */
#[Group('mysql')]
final class TripDealConsistencyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['departure_seats', 'departures', 'supplier_payments', 'booking_payments', 'booking_services', 'travelers', 'bookings', 'itinerary_items', 'itinerary_days', 'itineraries', 'trips', 'deals', 'pipeline_stages', 'pipelines',
                  'activities', 'suppliers', 'contacts', 'users', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'T1', 'slug' => 't1', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 9, 'tenant_id' => 1, 'name' => 'Anita', 'email' => 'a@x.test', 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Rohit', 'wa_number' => '+919876543210', 'status' => 'new', 'source' => 'manual', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
    }

    private function trip(): array
    {
        $trip = (new TripService())->create(1, ['title' => 'Goa', 'contact_id' => 1, 'adults' => 2, 'start_date' => '2027-12-10', 'end_date' => '2027-12-14'], 9);
        $db = db_connect();
        $db->table('itineraries')->insert(['tenant_id' => 1, 'trip_id' => $trip['id'], 'title' => 'Goa 4N', 'status' => 'draft', 'markup_type' => 'percent', 'markup_value' => 10, 'gst_rate' => 5, 'tcs_rate' => 2, 'is_international' => 0, 'adults' => 2, 'children' => 0, 'nights' => 4,
            'share_token' => bin2hex(random_bytes(16)), 'created_at' => '2026-09-01 00:00:00']);
        return [(int) $trip['id'], (int) $db->insertID(), (int) $trip['deal_id']];
    }

    private function deal(int $id): array { return db_connect()->table('deals')->where('id', $id)->get()->getRowArray(); }

    public function testTheDealCarriesTheQuotePriceThenTheBookingPrice(): void
    {
        [$tripId, $itId, $dealId] = $this->trip();
        $this->assertSame(0, (int) $this->deal($dealId)['value_amount'], 'no budget given: starts at 0');
        $svc = new ItineraryService();
        $svc->addItem(1, $itId, ['type' => 'hotel', 'title' => 'Resort', 'unit_cost' => 1_000_000, 'nights' => 1]);
        $sell = (int) db_connect()->table('itineraries')->where('id', $itId)->get()->getRowArray()['sell_subtotal'];
        $this->assertSame(1_100_000, $sell);
        $this->assertSame($sell, (int) $this->deal($dealId)['value_amount'], 'the deal follows the quote (price before GST)');
        $svc->addItem(1, $itId, ['type' => 'transfer', 'title' => 'Cab', 'unit_cost' => 100_000]);
        $this->assertSame(1_210_000, (int) $this->deal($dealId)['value_amount']);

        (new BookingService())->createFromItinerary(1, $itId, [], 9);
        $this->assertSame(1_210_000, (int) $this->deal($dealId)['value_amount'], 'the booking fixes the value');
        $svc->addItem(1, $itId, ['type' => 'other', 'title' => 'Later extra', 'unit_cost' => 500_000]);   // a later edit of a booked trip's quote
        $this->assertSame(1_210_000, (int) $this->deal($dealId)['value_amount'], 'a booked deal no longer follows quote edits');
    }

    public function testCancellingTheOnlyBookingMakesTheDealLostNotWon(): void
    {
        [$tripId, $itId, $dealId] = $this->trip();
        (new ItineraryService())->addItem(1, $itId, ['type' => 'hotel', 'title' => 'Resort', 'unit_cost' => 1_000_000, 'nights' => 1]);
        $b = (new BookingService())->createFromItinerary(1, $itId, [], 9);
        $this->assertSame('won', $this->deal($dealId)['status']);

        $out = (new BookingService())->cancel(1, (int) $b['id'], 'customer changed plans');
        $this->assertSame('cancelled', $out['status']);
        $this->assertSame('cancelled', db_connect()->table('trips')->where('id', $tripId)->get()->getRowArray()['status']);
        $deal = $this->deal($dealId);
        $this->assertSame('lost', $deal['status']);
        $this->assertStringContainsString('Booking cancelled', (string) $deal['lost_reason']);

        $this->expectException(\DomainException::class);
        (new BookingService())->cancel(1, (int) $b['id']);                    // cancelling twice is refused
    }

    public function testAnotherWorkspaceCannotCancelMyBooking(): void
    {
        [, $itId] = $this->trip();
        (new ItineraryService())->addItem(1, $itId, ['type' => 'hotel', 'title' => 'Resort', 'unit_cost' => 1_000_000, 'nights' => 1]);
        $b = (new BookingService())->createFromItinerary(1, $itId, [], 9);
        db_connect()->table('tenants')->insert(['id' => 2, 'name' => 'T2', 'slug' => 't2', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $this->expectException(\InvalidArgumentException::class);
        (new BookingService())->cancel(2, (int) $b['id']);
    }
}
