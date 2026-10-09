<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Database\Seeds\MeetingReminderSeeder;
use App\Services\Crm\MeetingLinkService;
use App\Services\Crm\MeetingReminderService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The 30-minutes-before meeting reminder.
 *
 * Two failure modes are worth more than the rest put together: reminding twice
 * (which gets a WhatsApp number reported) and reminding about a meeting that
 * has already happened (which is what a cron outage would otherwise cause).
 * Both are pinned here against a frozen clock.
 */
class MeetingReminderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = true;

    private const TZ = 'Asia/Kolkata';

    /** Frozen "now": 2026-09-17 04:30 UTC = 10:00 IST. */
    private int $now;

    private MeetingReminderService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->now = (new DateTimeImmutable('2026-09-17 04:30:00', new DateTimeZone('UTC')))->getTimestamp();
        $this->svc = new MeetingReminderService();
        MeetingLinkService::flushCache();
        unset($_ENV['MEETING_VIDEO_LINK']);
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
        $db->query("CREATE TABLE IF NOT EXISTS {$p}meetings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            title TEXT, contact_id INTEGER, deal_id INTEGER, owner_id INTEGER,
            start_at TEXT, end_at TEXT, location TEXT, notes TEXT,
            status TEXT DEFAULT 'scheduled', reminder_sent INTEGER DEFAULT 0,
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
            reentry_policy TEXT DEFAULT 'once', version INTEGER DEFAULT 1, stats TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}flow_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, flow_id INTEGER,
            contact_id INTEGER, current_node_id TEXT, state TEXT, status TEXT,
            next_run_at TEXT, graph_snapshot TEXT, steps INTEGER DEFAULT 0,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, type TEXT,
            source_type TEXT, source_id INTEGER, payload TEXT,
            run_at TEXT NOT NULL, status TEXT DEFAULT 'pending',
            attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 5,
            locked_at TEXT, locked_by TEXT, last_error TEXT,
            created_at TEXT, updated_at TEXT
        )");

        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (2, 'Other', 'other')");
        $db->table('contacts')->insert([
            'id' => 1, 'tenant_id' => 1, 'wa_number' => '919718991797', 'name' => 'Rajesh',
        ]);

        // An active flow listening on the trigger, so fire() has somewhere to go.
        $db->table('flows')->insert([
            'id' => 1, 'tenant_id' => 1, 'name' => 'Reminder', 'status' => 'active',
            'trigger_type' => 'meeting_reminder', 'trigger_config' => '{}',
            'graph' => '{"nodes":[],"edges":[]}', 'reentry_policy' => 'always',
        ]);
    }

    /** Insert a meeting $minutes from the frozen now. */
    private function meetingIn(int $minutes, array $overrides = []): int
    {
        $start = (new DateTimeImmutable('@' . ($this->now + $minutes * 60)))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        db_connect()->table('meetings')->insert(array_merge([
            'tenant_id'  => 1,
            'title'      => 'Product demo',
            'contact_id' => 1,
            'start_at'   => $start,
            'status'     => 'scheduled',
        ], $overrides));

        return (int) db_connect()->insertID();
    }

    private function scan(int $tenantId = 1): array
    {
        return $this->svc->run($tenantId, $this->now, self::TZ);
    }

    /** @return list<array> flow_start jobs queued so far. */
    private function queued(): array
    {
        return db_connect()->table('jobs')->where('type', 'flow_start')->get()->getResultArray();
    }

    private function reminderSent(int $id): int
    {
        $row = db_connect()->table('meetings')->select('reminder_sent')->where('id', $id)->get()->getRowArray();

        return (int) ($row['reminder_sent'] ?? 0);
    }

    // ------------------------------------------------------------------
    // The band
    // ------------------------------------------------------------------

    public function testAMeetingInsideTheBandFires(): void
    {
        $this->meetingIn(20);

        $this->assertSame(1, $this->scan()['fired']);
        $this->assertCount(1, $this->queued());
    }

    public function testAMeetingExactlyAtTheLeadTimeFires(): void
    {
        $this->meetingIn(MeetingReminderService::LEAD_MINUTES);

        $this->assertSame(1, $this->scan()['fired']);
    }

    public function testAMeetingBeyondTheBandIsLeftAlone(): void
    {
        $id = $this->meetingIn(45);

        $this->assertSame(0, $this->scan()['fired']);
        $this->assertSame([], $this->queued());
        // Crucially it is NOT claimed — it must still fire 15 minutes from now.
        $this->assertSame(0, $this->reminderSent($id));
    }

    /**
     * The cron was down overnight. On the next run it must not apologise to
     * everyone whose meeting it slept through.
     */
    public function testAMeetingThatAlreadyStartedNeverFires(): void
    {
        $this->meetingIn(-10);
        $this->meetingIn(-600);

        $this->assertSame(0, $this->scan()['fired']);
        $this->assertSame([], $this->queued());
    }

    public function testAMeetingStartingThisVerySecondDoesNotFire(): void
    {
        $this->meetingIn(0);

        $this->assertSame(0, $this->scan()['fired']);
    }

    // ------------------------------------------------------------------
    // Once, and only once
    // ------------------------------------------------------------------

    public function testTheMeetingIsClaimedSoASecondScanSendsNothing(): void
    {
        $id = $this->meetingIn(20);

        $this->assertSame(1, $this->scan()['fired']);
        $this->assertSame(1, $this->reminderSent($id));

        // The cron fires again a minute later, and again, and again.
        $this->assertSame(0, $this->scan()['fired']);
        $this->assertSame(0, $this->scan()['fired']);
        $this->assertCount(1, $this->queued());
    }

    public function testAnAlreadyRemindedMeetingIsIgnored(): void
    {
        $this->meetingIn(20, ['reminder_sent' => 1]);

        $this->assertSame(0, $this->scan()['fired']);
    }

    // ------------------------------------------------------------------
    // What does not deserve a reminder
    // ------------------------------------------------------------------

    public function testACompletedMeetingIsNotReminded(): void
    {
        $this->meetingIn(20, ['status' => 'completed']);

        $this->assertSame(0, $this->scan()['fired']);
    }

    public function testACanceledMeetingIsNotReminded(): void
    {
        $this->meetingIn(20, ['status' => 'canceled']);

        $this->assertSame(0, $this->scan()['fired']);
    }

    public function testASoftDeletedMeetingIsNotReminded(): void
    {
        $this->meetingIn(20, ['deleted_at' => '2026-09-16 00:00:00']);

        $this->assertSame(0, $this->scan()['fired']);
    }

    /**
     * An internal meeting has nobody to message. It is filtered out in SQL, so
     * it is never even considered — and in particular is never claimed, since
     * writing reminder_sent on a row we will always exclude anyway is a write
     * for nothing.
     */
    public function testAMeetingWithNoContactIsNeverSelected(): void
    {
        $id = $this->meetingIn(20, ['contact_id' => null]);

        $res = $this->scan();
        $this->assertSame(0, $res['due']);
        $this->assertSame(0, $res['fired']);
        $this->assertSame([], $this->queued());
        $this->assertSame(0, $this->reminderSent($id));
    }

    public function testAnotherTenantsMeetingIsInvisible(): void
    {
        $this->meetingIn(20, ['tenant_id' => 2]);

        $this->assertSame(0, $this->scan(1)['fired']);
    }

    // ------------------------------------------------------------------
    // What the flow is handed
    // ------------------------------------------------------------------

    /** @return array<string,mixed> the context of the single queued flow_start. */
    private function firedContext(): array
    {
        $jobs = $this->queued();
        $this->assertCount(1, $jobs);

        return json_decode($jobs[0]['payload'], true)['context'] ?? [];
    }

    /** 04:30 UTC + 20 min = 04:50 UTC = 10:20 IST. The customer must see 10:20. */
    public function testTheTimeHandedToTheFlowIsInTheBusinessTimezone(): void
    {
        $this->meetingIn(20);
        $this->scan();

        $this->assertSame('Thu 17 Sep, 10:20 AM', $this->firedContext()['meeting_time']);
    }

    public function testTheContextCarriesTheMeetingId(): void
    {
        $id = $this->meetingIn(20);
        $this->scan();

        $this->assertSame($id, $this->firedContext()['meeting_id']);
    }

    /**
     * A meeting created nine minutes before it starts must not be announced as
     * "about 30 minutes" — the copy reads the real gap, not the nominal one.
     */
    public function testTheMinutesReportedAreTheRealGapNotTheLeadTime(): void
    {
        $this->meetingIn(9);
        $this->scan();

        $this->assertSame('9', $this->firedContext()['meeting_minutes']);
    }

    public function testEachMeetingFiresIndependently(): void
    {
        $this->meetingIn(10);
        $this->meetingIn(25);

        $this->assertSame(2, $this->scan()['fired']);
        $this->assertCount(2, $this->queued());
    }

    // ------------------------------------------------------------------
    // The seeded flow
    // ------------------------------------------------------------------

    /**
     * The whole point of the flow: 30 minutes before a meeting booked days ago
     * the window is shut, and a graph that only wires 'open' would deliver
     * nothing at all. Both branches must lead somewhere.
     */
    public function testTheGraphAnswersBothSidesOfTheWindowCheck(): void
    {
        $graph   = MeetingReminderSeeder::buildGraph(7);
        $handles = [];
        foreach ($graph['edges'] as $e) {
            if ($e['source'] === 'w1') {
                $handles[$e['sourceHandle']] = $e['target'];
            }
        }

        $this->assertArrayHasKey('open', $handles);
        $this->assertArrayHasKey('closed', $handles);

        $types = array_column($graph['nodes'], 'type', 'id');
        $this->assertSame('send_freeform', $types[$handles['open']]);
        $this->assertSame('send_template', $types[$handles['closed']]);
    }

    public function testTheClosedBranchUsesTheApprovedTemplateWithTheNameVariable(): void
    {
        $graph = MeetingReminderSeeder::buildGraph(7);
        $node  = null;
        foreach ($graph['nodes'] as $n) {
            if ($n['type'] === 'send_template') {
                $node = $n;
            }
        }

        $this->assertNotNull($node);
        $this->assertSame(7, $node['data']['template_id']);
        $this->assertSame(['1' => 'name'], $node['data']['variable_mapping']);
    }

    public function testTheTriggerNodeMatchesTheFlowTriggerType(): void
    {
        $types = array_column(MeetingReminderSeeder::buildGraph(7)['nodes'], 'type');

        $this->assertContains('meeting_reminder', $types);
    }

    /** The free-form copy must read its time from run state, never print UTC. */
    public function testTheFreeformCopyUsesTheStateTokens(): void
    {
        $content = '';
        foreach (MeetingReminderSeeder::buildGraph(7)['nodes'] as $n) {
            if ($n['type'] === 'send_freeform') {
                $content = $n['data']['content'];
            }
        }

        $this->assertStringContainsString('{{meeting.time}}', $content);
        $this->assertStringContainsString('{{contact.name}}', $content);
    }

    // ------------------------------------------------------------------
    // The video-call link the confirmation has been promising
    // ------------------------------------------------------------------

    private function link(): ?string
    {
        MeetingLinkService::flushCache();

        return (new MeetingLinkService())->forTenant(1);
    }

    public function testWithNothingConfiguredThereIsNoLink(): void
    {
        $this->assertNull($this->link());
    }

    public function testAnEnvLinkIsUsed(): void
    {
        $_ENV['MEETING_VIDEO_LINK'] = 'https://meet.google.com/abc-defg-hij';

        $this->assertSame('https://meet.google.com/abc-defg-hij', $this->link());
    }

    /** A tenant that set its own link must not be overridden by the env default. */
    public function testATenantSettingBeatsTheEnvFallback(): void
    {
        $_ENV['MEETING_VIDEO_LINK'] = 'https://meet.google.com/env-fallback';
        db_connect()->table('tenants')->where('id', 1)
            ->update(['settings' => json_encode(['meeting_link' => 'https://zoom.us/j/123'])]);

        $this->assertSame('https://zoom.us/j/123', $this->link());
    }

    /** Never paste something unvalidated into a customer's chat. */
    public function testANonUrlIsTreatedAsUnconfigured(): void
    {
        $_ENV['MEETING_VIDEO_LINK'] = 'ask me on the call';

        $this->assertNull($this->link());
    }

    /**
     * The flow is handed our own /meet/{id} redirect, never the raw Meet code:
     * the code rotates, and a click on our URL is what tells the owner somebody
     * is waiting in the lobby.
     */
    public function testTheFlowIsHandedTheTrackedJoinUrlNotTheRawLink(): void
    {
        $_ENV['MEETING_VIDEO_LINK'] = 'https://meet.google.com/abc-defg-hij';
        MeetingLinkService::flushCache();

        $id = $this->meetingIn(20);
        $this->scan();

        $link = $this->firedContext()['meeting_link'];
        $this->assertStringEndsWith("/meet/{$id}", $link);
        $this->assertStringNotContainsString('meet.google.com', $link);
    }

    /** Per-meeting when we know the meeting; bare for the static template button. */
    public function testTheJoinUrlCarriesTheMeetingIdWhenThereIsOne(): void
    {
        $_ENV['MEETING_VIDEO_LINK'] = 'https://meet.google.com/abc-defg-hij';
        MeetingLinkService::flushCache();
        $svc = new MeetingLinkService();

        $this->assertStringEndsWith('/meet/42', (string) $svc->joinUrlFor(1, 42));
        $this->assertStringEndsWith('/meet', (string) $svc->joinUrlFor(1, 0));
    }

    /** A reminder must never offer a "join" that goes nowhere. */
    public function testThereIsNoJoinUrlWhenNoLinkIsConfigured(): void
    {
        MeetingLinkService::flushCache();

        $this->assertNull((new MeetingLinkService())->joinUrlFor(1, 42));
    }

    /** Unconfigured must arrive as empty, so the copy leaves the token visible. */
    public function testWithNoLinkTheContextCarriesAnEmptyString(): void
    {
        $this->meetingIn(20);
        $this->scan();

        $this->assertSame('', $this->firedContext()['meeting_link']);
    }

    public function testTheReminderCopyOffersTheLink(): void
    {
        $content = '';
        foreach (MeetingReminderSeeder::buildGraph(7)['nodes'] as $n) {
            if ($n['type'] === 'send_freeform') {
                $content = $n['data']['content'];
            }
        }

        $this->assertStringContainsString('{{meeting.link}}', $content);
    }

    // ------------------------------------------------------------------
    // The owner heads-up
    // ------------------------------------------------------------------

    /**
     * With a free Google account nobody enters the call until the host admits
     * them, so a reminder that only reaches the customer leaves them knocking
     * on a door with nobody behind it.
     */
    public function testBothBranchesTellTheOwnerItIsStarting(): void
    {
        $graph = MeetingReminderSeeder::buildGraph(7, 9, '+919718991797');

        $byId = array_column($graph['nodes'], null, 'id');
        $this->assertArrayHasKey('a1', $byId);
        $this->assertSame('notify_number', $byId['a1']['type']);
        $this->assertSame('+919718991797', $byId['a1']['data']['to']);
        $this->assertSame(9, $byId['a1']['data']['template_id']);

        $intoAlert = [];
        foreach ($graph['edges'] as $e) {
            if ($e['target'] === 'a1') {
                $intoAlert[] = $e['source'];
            }
        }
        sort($intoAlert);
        $this->assertSame(['m1', 'm2'], $intoAlert, 'free-form and template must both alert');
    }

    /** No number configured means no dangling node pointing nowhere. */
    public function testWithNoAlertNumberTheGraphHasNoAlertNode(): void
    {
        $graph = MeetingReminderSeeder::buildGraph(7, 9, '');

        $this->assertArrayNotHasKey('a1', array_column($graph['nodes'], null, 'id'));
        foreach ($graph['edges'] as $e) {
            $this->assertNotSame('a1', $e['target']);
        }
    }

    public function testTheAlertNamesTheContactAndTheirNumber(): void
    {
        $byId   = array_column(MeetingReminderSeeder::buildGraph(7, 9, '+919718991797')['nodes'], null, 'id');
        $params = $byId['a1']['data']['params'];

        $this->assertSame('{{contact.name}}', $params[0]);
        $this->assertSame('{{contact.wa_number}}', $params[2]);
        $this->assertStringContainsString('30 minutes', $params[1]);
    }
}
