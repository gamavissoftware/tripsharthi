<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Crm\BookingService;
use App\Services\Crm\SlotService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Demo slot generation and booking.
 *
 * The two things that go wrong in booking systems are timezones and races:
 * a slot offered as "10:00" that lands at 15:30, and two people taking the
 * same slot. Both are covered here with a frozen clock.
 */
class SlotBookingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = true;

    private const TZ = 'Asia/Kolkata';

    /** Frozen "now": 2026-09-15 06:00 UTC = 11:30 IST. */
    private int $now;

    private SlotService $slots;
    private BookingService $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->now     = (new DateTimeImmutable('2026-09-15 06:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $this->slots   = new SlotService();
        $this->booking = new BookingService();
    }

    private function createTestSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;

        foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->getResultArray() as $row) {
            $db->query('DROP TABLE IF EXISTS "' . $row['name'] . '"');
        }

        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (
            id INTEGER PRIMARY KEY, name TEXT, slug TEXT, plan TEXT DEFAULT 'free',
            status TEXT DEFAULT 'active', mode TEXT DEFAULT 'saas',
            settings TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}business_hours (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            workdays TEXT, start_hour INTEGER, end_hour INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}meetings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            title TEXT, contact_id INTEGER, deal_id INTEGER, owner_id INTEGER,
            start_at TEXT, end_at TEXT, location TEXT, notes TEXT,
            status TEXT DEFAULT 'scheduled',
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            wa_number TEXT, name TEXT, owner_id INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}flows (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT,
            status TEXT, trigger_type TEXT, trigger_config TEXT, graph TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");
    }

    /** @return list<array> */
    private function slots(int $limit = 6): array
    {
        return $this->slots->available(1, $limit, self::TZ, $this->now);
    }

    private function istHour(array $slot): int
    {
        $start = new DateTimeImmutable($slot['start_utc'], new DateTimeZone('UTC'));
        return (int) $start->setTimezone(new DateTimeZone(self::TZ))->format('G');
    }

    // ------------------------------------------------------------------
    // Availability
    // ------------------------------------------------------------------

    public function testItOffersTheRequestedNumberOfSlots(): void
    {
        $this->assertCount(6, $this->slots(6));
        $this->assertCount(3, $this->slots(3));
    }

    public function testEverySlotFallsOnAWorkday(): void
    {
        foreach ($this->slots() as $slot) {
            $start = (new DateTimeImmutable($slot['start_utc'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(self::TZ));
            $this->assertLessThanOrEqual(5, (int) $start->format('N'), "{$slot['label']} is a weekend");
        }
    }

    public function testEverySlotSitsInsideBusinessHoursLocally(): void
    {
        foreach ($this->slots() as $slot) {
            $hour = $this->istHour($slot);
            $this->assertGreaterThanOrEqual(9, $hour, "{$slot['label']} starts before 09:00 IST");
            $this->assertLessThan(18, $hour, "{$slot['label']} starts at or after 18:00 IST");
        }
    }

    /** Nobody can take a call in ten minutes; the first slot must be hours out. */
    public function testTheFirstSlotRespectsLeadTime(): void
    {
        $first = $this->slots()[0];
        $startTs = (new DateTimeImmutable($first['start_utc'], new DateTimeZone('UTC')))->getTimestamp();

        $this->assertGreaterThanOrEqual($this->now + 2 * 3600, $startTs);
    }

    public function testSlotsAreInChronologicalOrderAndUnique(): void
    {
        $starts = array_column($this->slots(), 'start_utc');
        $sorted = $starts;
        sort($sorted);

        $this->assertSame($sorted, $starts);
        $this->assertSame($starts, array_values(array_unique($starts)));
    }

    /** WhatsApp truncates list row titles past 24 characters. */
    public function testLabelsFitAWhatsAppListRow(): void
    {
        foreach ($this->slots() as $slot) {
            $this->assertLessThanOrEqual(24, mb_strlen($slot['label']), "Label too long: {$slot['label']}");
        }
    }

    public function testAnAlreadyBookedSlotIsNotOfferedAgain(): void
    {
        $first = $this->slots()[0];

        db_connect()->table('meetings')->insert([
            'tenant_id' => 1, 'title' => 'Taken', 'contact_id' => 99,
            'start_at' => $first['start_utc'], 'end_at' => $first['end_utc'], 'status' => 'scheduled',
        ]);

        $after = array_column($this->slots(), 'start_utc');
        $this->assertNotContains($first['start_utc'], $after);
    }

    /** A cancelled meeting frees its slot again. */
    public function testACancelledMeetingDoesNotBlockTheSlot(): void
    {
        $first = $this->slots()[0];

        db_connect()->table('meetings')->insert([
            'tenant_id' => 1, 'title' => 'Cancelled', 'contact_id' => 99,
            'start_at' => $first['start_utc'], 'end_at' => $first['end_utc'], 'status' => 'canceled',
        ]);

        $this->assertContains($first['start_utc'], array_column($this->slots(), 'start_utc'));
    }

    // ------------------------------------------------------------------
    // Timezone — the classic booking bug
    // ------------------------------------------------------------------

    public function testTheStoredTimeIsUtcWhileTheLabelIsLocal(): void
    {
        $slot  = $this->slots()[0];
        $utc   = new DateTimeImmutable($slot['start_utc'], new DateTimeZone('UTC'));
        $local = $utc->setTimezone(new DateTimeZone(self::TZ));

        // IST is UTC+5:30, so the stored hour must differ from the shown hour.
        $this->assertSame(330 * 60, $local->getOffset());
        $this->assertStringContainsString($local->format('g:i A'), $slot['label']);
    }

    // ------------------------------------------------------------------
    // resolve()
    // ------------------------------------------------------------------

    public function testAnOfferedIdResolvesBackToTheSameWindow(): void
    {
        $slot     = $this->slots()[0];
        $resolved = $this->slots->resolve(1, $slot['id'], self::TZ, $this->now);

        $this->assertNotNull($resolved);
        $this->assertSame($slot['start_utc'], $resolved['start_utc']);
        $this->assertSame($slot['end_utc'], $resolved['end_utc']);
    }

    public function testRubbishIdsResolveToNull(): void
    {
        foreach (['', 'hello', 'slot:', 'slot:not-a-date', 'slot:2026-13-45T99:99', 'Show me a demo'] as $bad) {
            $this->assertNull($this->slots->resolve(1, $bad, self::TZ, $this->now), "Accepted: {$bad}");
        }
    }

    public function testAPastSlotNoLongerResolves(): void
    {
        $yesterday = (new DateTimeImmutable('@' . $this->now))
            ->setTimezone(new DateTimeZone(self::TZ))->modify('-1 day')->format('Y-m-d\TH:i');

        $this->assertNull($this->slots->resolve(1, 'slot:' . $yesterday, self::TZ, $this->now));
    }

    public function testAMidnightSlotIsRefusedEvenIfWellFormed(): void
    {
        $this->assertNull($this->slots->resolve(1, 'slot:2026-09-18T03:00', self::TZ, $this->now));
    }

    // ------------------------------------------------------------------
    // Booking
    // ------------------------------------------------------------------

    public function testBookingCreatesTheMeetingInUtc(): void
    {
        $slot   = $this->slots()[0];
        $result = $this->booking->book(1, 42, $slot['id'], 'Product demo', self::TZ, $this->now);

        $this->assertTrue($result['ok']);
        $row = db_connect()->table('meetings')->where('id', $result['meeting_id'])->get()->getRowArray();
        $this->assertSame($slot['start_utc'], $row['start_at']);
        $this->assertSame(42, (int) $row['contact_id']);
        $this->assertSame('scheduled', $row['status']);
    }

    public function testBookingAnInvalidSlotIsRefused(): void
    {
        $result = $this->booking->book(1, 42, 'slot:garbage', 'Demo', self::TZ, $this->now);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_slot', $result['reason']);
        $this->assertSame(0, db_connect()->table('meetings')->countAllResults());
    }

    /** Two people, one slot: the second must be refused, not double-booked. */
    public function testASlotCannotBeBookedTwiceByDifferentContacts(): void
    {
        $slot = $this->slots()[0];

        $first  = $this->booking->book(1, 42, $slot['id'], 'Demo', self::TZ, $this->now);
        $second = $this->booking->book(1, 77, $slot['id'], 'Demo', self::TZ, $this->now);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('slot_taken', $second['reason']);
        $this->assertSame(1, db_connect()->table('meetings')->countAllResults());
    }

    /** A double-tap must return the original booking, not "slot taken". */
    public function testTheSameContactTappingTwiceIsIdempotent(): void
    {
        $slot = $this->slots()[0];

        $first  = $this->booking->book(1, 42, $slot['id'], 'Demo', self::TZ, $this->now);
        $second = $this->booking->book(1, 42, $slot['id'], 'Demo', self::TZ, $this->now);

        $this->assertTrue($second['ok']);
        $this->assertSame($first['meeting_id'], $second['meeting_id']);
        $this->assertSame(1, db_connect()->table('meetings')->countAllResults());
    }

    public function testTheBookingInheritsTheLeadOwner(): void
    {
        db_connect()->table('contacts')->insert(['id' => 42, 'tenant_id' => 1, 'wa_number' => '+9199', 'owner_id' => 7]);

        $slot   = $this->slots()[0];
        $result = $this->booking->book(1, 42, $slot['id'], 'Demo', self::TZ, $this->now);

        $row = db_connect()->table('meetings')->where('id', $result['meeting_id'])->get()->getRowArray();
        $this->assertSame(7, (int) $row['owner_id'], 'An agent-scoped user must be able to see their own lead\'s demo');
    }

    public function testAnUnownedLeadStillBooksWithoutAnOwner(): void
    {
        db_connect()->table('contacts')->insert(['id' => 43, 'tenant_id' => 1, 'wa_number' => '+9198', 'owner_id' => null]);

        $slot   = $this->slots()[0];
        $result = $this->booking->book(1, 43, $slot['id'], 'Demo', self::TZ, $this->now);

        $this->assertTrue($result['ok']);
        $row = db_connect()->table('meetings')->where('id', $result['meeting_id'])->get()->getRowArray();
        $this->assertNull($row['owner_id']);
    }

    public function testSlotSelectionsAreRecognisedByPrefix(): void
    {
        $this->assertTrue(BookingService::isSlotSelection('slot:2026-09-17T10:00'));
        $this->assertFalse(BookingService::isSlotSelection('Show me a demo'));
        $this->assertFalse(BookingService::isSlotSelection(null));
    }
}
