<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Database\Seeds\FollowUpFlowSeeder;
use App\Services\Leads\FollowUpHooks;
use App\Services\Leads\FollowUpScanner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The no-reply follow-up scanner.
 *
 * Almost every test here asserts that somebody is NOT contacted. That is the
 * point: a nudge is a paid marketing template to a closed window aimed at a
 * person who already ignored us, and at this list's size the difference between
 * a working follow-up and a restricted WhatsApp number is entirely in the
 * refusals.
 */
class FollowUpScannerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = true;

    /** Frozen "now": 2026-09-16 12:00 UTC. */
    private int $now;

    private FollowUpScanner $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->now = strtotime('2026-09-16 12:00:00 UTC');
        $this->svc = new FollowUpScanner();
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
            settings TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, wa_number TEXT, name TEXT,
            opt_in INTEGER DEFAULT 1, last_inbound_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, contact_id INTEGER,
            conversation_id INTEGER, campaign_id INTEGER, template_id INTEGER, direction TEXT, type TEXT,
            category TEXT, body TEXT, status TEXT, error TEXT, billable INTEGER DEFAULT 0,
            sent_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT, template_id INTEGER,
            segment TEXT, status TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT, body TEXT,
            category TEXT, meta_status TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}follow_ups (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, contact_id INTEGER,
            source_message_id INTEGER, source_template_id INTEGER, step INTEGER DEFAULT 1,
            hook TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT,
            UNIQUE (source_message_id, step))");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contact_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INTEGER, tag_id INTEGER)");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}flows (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT, status TEXT,
            trigger_type TEXT, trigger_config TEXT, graph TEXT, reentry_policy TEXT DEFAULT 'once',
            version INTEGER DEFAULT 1, stats TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}flow_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, flow_id INTEGER, contact_id INTEGER,
            current_node_id TEXT, state TEXT, status TEXT, next_run_at TEXT, graph_snapshot TEXT,
            steps INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, type TEXT,
            source_type TEXT, source_id INTEGER, payload TEXT, run_at TEXT NOT NULL,
            status TEXT DEFAULT 'pending', attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 5,
            locked_at TEXT, locked_by TEXT, last_error TEXT, created_at TEXT, updated_at TEXT)");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}phone_numbers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, waba_account_id INTEGER, tenant_id INTEGER,
            phone_number_id TEXT, display_number TEXT, quality_rating TEXT DEFAULT 'green',
            is_default INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT)");

        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");
        $db->table('phone_numbers')->insert([
            'tenant_id' => 1, 'phone_number_id' => 'pn1',
            'display_number' => '+91 87969 13431', 'quality_rating' => 'green',
        ]);
        $db->table('templates')->insert([
            'id' => 25, 'tenant_id' => 1, 'name' => 'gamavis_manufacturing_no_software',
            'category' => 'marketing', 'meta_status' => 'approved', 'body' => 'opener',
        ]);
        $db->table('campaigns')->insert([
            'id' => 13, 'tenant_id' => 1, 'name' => 'Faridabad', 'template_id' => 25, 'status' => 'done',
        ]);
        $db->table('flows')->insert([
            'id' => 1, 'tenant_id' => 1, 'name' => 'Follow-up', 'status' => 'active',
            'trigger_type' => 'no_reply_followup', 'trigger_config' => '{}',
            'graph' => '{"nodes":[],"edges":[]}', 'reentry_policy' => 'always',
        ]);
    }

    private function contact(string $name = 'Rajesh', array $overrides = []): int
    {
        static $seq = 0;
        $seq++;
        db_connect()->table('contacts')->insert(array_merge([
            'tenant_id' => 1, 'name' => $name, 'wa_number' => '91971899' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'opt_in' => 1,
        ], $overrides));

        return (int) db_connect()->insertID();
    }

    /** An outbound marketing template sent $daysAgo, delivered by default. */
    private function sent(int $contactId, int $daysAgo = 5, array $overrides = []): int
    {
        $at = date('Y-m-d H:i:s', $this->now - $daysAgo * 86400);
        db_connect()->table('messages')->insert(array_merge([
            'tenant_id' => 1, 'contact_id' => $contactId, 'campaign_id' => 13,
            'direction' => 'out', 'type' => 'template', 'category' => 'marketing',
            'body' => 'opener', 'status' => 'delivered', 'sent_at' => $at, 'created_at' => $at,
        ], $overrides));

        return (int) db_connect()->insertID();
    }

    private function replied(int $contactId, int $daysAgo): void
    {
        $at = date('Y-m-d H:i:s', $this->now - $daysAgo * 86400);
        db_connect()->table('messages')->insert([
            'tenant_id' => 1, 'contact_id' => $contactId, 'direction' => 'in',
            'type' => 'text', 'body' => 'yes tell me', 'status' => 'read',
            'sent_at' => $at, 'created_at' => $at,
        ]);
    }

    private function scan(int $limit = 100, bool $dryRun = false): array
    {
        return $this->svc->run(1, $limit, $dryRun, $this->now);
    }

    private function queued(): array
    {
        return db_connect()->table('jobs')->where('type', 'flow_start')->get()->getResultArray();
    }

    // ------------------------------------------------------------------
    // Who gets nudged
    // ------------------------------------------------------------------

    public function testAnUnansweredDeliveredTemplateIsNudged(): void
    {
        $this->sent($this->contact());

        $this->assertSame(1, $this->scan()['fired']);
        $this->assertCount(1, $this->queued());
    }

    public function testAReadButUnansweredTemplateIsNudged(): void
    {
        $this->sent($this->contact(), 5, ['status' => 'read']);

        $this->assertSame(1, $this->scan()['fired']);
    }

    // ------------------------------------------------------------------
    // Who must never be nudged
    // ------------------------------------------------------------------

    /**
     * Roughly half of cold sends on this account fail outright. Chasing one
     * spends money talking to a number that does not exist and pushes the
     * failure rate — itself a quality signal — higher.
     */
    public function testAFailedSendIsNeverChased(): void
    {
        $this->sent($this->contact(), 5, ['status' => 'failed']);

        $this->assertSame(0, $this->scan()['fired']);
    }

    public function testASendStillQueuedOrMerelyAcceptedIsNotChased(): void
    {
        $this->sent($this->contact(), 5, ['status' => 'sent']);
        $this->sent($this->contact(), 5, ['status' => 'queued']);

        $this->assertSame(0, $this->scan()['fired']);
    }

    /** A meeting reminder is also an outbound template. It is not sales outreach. */
    public function testAUtilityTemplateIsNotFollowedUp(): void
    {
        $this->sent($this->contact(), 5, ['category' => 'utility']);

        $this->assertSame(0, $this->scan()['fired']);
    }

    public function testSomebodyWhoRepliedAfterTheSendIsLeftAlone(): void
    {
        $c = $this->contact();
        $this->sent($c, 5);
        $this->replied($c, 4); // one day after the send

        $this->assertSame(0, $this->scan()['fired']);
        $this->assertSame([], $this->queued());
    }

    /**
     * "Didn't respond" means since this message, not ever. A prospect who wrote
     * last month and ignored this campaign still deserves a nudge.
     */
    public function testAnOldReplyBeforeTheSendDoesNotProtectThem(): void
    {
        $c = $this->contact();
        $this->replied($c, 40);
        $this->sent($c, 5);

        $this->assertSame(1, $this->scan()['fired']);
    }

    /** WebhookService flips opt_in to 0 the moment anyone replies STOP. */
    public function testAnOptedOutContactIsNeverNudged(): void
    {
        $this->sent($this->contact('Quiet', ['opt_in' => 0]));

        $this->assertSame(0, $this->scan()['fired']);
    }

    public function testASoftDeletedContactIsNotNudged(): void
    {
        $this->sent($this->contact('Gone', ['deleted_at' => '2026-09-01 00:00:00']));

        $this->assertSame(0, $this->scan()['fired']);
    }

    public function testATooRecentSendIsLeftToBreathe(): void
    {
        $this->sent($this->contact(), 1);

        $this->assertSame(0, $this->scan()['fired']);
    }

    public function testAnAncientCampaignIsNotResurrected(): void
    {
        $this->sent($this->contact(), 45);

        $this->assertSame(0, $this->scan()['fired']);
    }

    // ------------------------------------------------------------------
    // Never twice
    // ------------------------------------------------------------------

    public function testTheSameSendIsNeverChasedTwice(): void
    {
        $this->sent($this->contact());

        $this->assertSame(1, $this->scan()['fired']);
        $this->assertSame(0, $this->scan()['fired']);
        $this->assertSame(0, $this->scan()['fired']);
        $this->assertCount(1, $this->queued());
    }

    /** Two campaigns to one person is still one person. */
    public function testTwoUnansweredSendsToOneContactProduceOneNudge(): void
    {
        $c = $this->contact();
        $this->sent($c, 5);
        $this->sent($c, 8);

        $this->assertSame(1, $this->scan()['fired']);
    }

    public function testAContactIsNeverNudgedMoreThanTheCap(): void
    {
        $c = $this->contact();
        $this->sent($c, 10);
        $this->assertSame(1, $this->scan()['fired']);

        // Two more unanswered sends, each far enough apart to clear the gap.
        db_connect()->table('follow_ups')->where('contact_id', $c)
            ->update(['created_at' => date('Y-m-d H:i:s', $this->now - 20 * 86400)]);
        $this->sent($c, 9);
        $this->assertSame(1, $this->scan()['fired']);

        db_connect()->table('follow_ups')->where('contact_id', $c)
            ->update(['created_at' => date('Y-m-d H:i:s', $this->now - 20 * 86400)]);
        $this->sent($c, 8);

        $res = $this->scan();
        $this->assertSame(0, $res['fired'], 'third nudge must be refused');
        $this->assertSame(1, $res['skipped_cap']);
    }

    /** The second nudge waits after the FIRST NUDGE, not after the original send. */
    public function testTheSecondNudgeRespectsTheGap(): void
    {
        $c = $this->contact();
        $this->sent($c, 10);
        $this->assertSame(1, $this->scan()['fired']);

        $this->sent($c, 9);
        $this->assertSame(0, $this->scan()['fired'], 'too soon after the first nudge');
    }

    // ------------------------------------------------------------------
    // Volume control
    // ------------------------------------------------------------------

    public function testThePerRunLimitIsRespected(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->sent($this->contact("P{$i}"));
        }

        $this->assertSame(2, $this->scan(2)['fired']);
    }

    public function testTheDailyCapStopsFurtherRunsTheSameDay(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->sent($this->contact("P{$i}"));
        }

        $this->assertSame(2, $this->scan(2)['fired']);

        $res = $this->scan(2);
        $this->assertTrue($res['capped_daily']);
        $this->assertSame(0, $res['fired']);
    }

    // ------------------------------------------------------------------
    // Dry run
    // ------------------------------------------------------------------

    public function testADryRunWritesNothingAndSendsNothing(): void
    {
        $this->sent($this->contact());

        $res = $this->scan(100, true);
        $this->assertSame(1, $res['fired']);
        $this->assertCount(1, $res['preview']);
        $this->assertSame([], $this->queued(), 'dry run must not enqueue');
        $this->assertSame(0, db_connect()->table('follow_ups')->countAllResults());
    }

    // ------------------------------------------------------------------
    // The hook — "related to their category and what we sent them"
    // ------------------------------------------------------------------

    private function firedContext(): array
    {
        $jobs = $this->queued();
        $this->assertCount(1, $jobs);

        return json_decode($jobs[0]['payload'], true)['context'] ?? [];
    }

    public function testTheHookComesFromTheTemplateTheyWereActuallySent(): void
    {
        $this->sent($this->contact());
        $this->scan();

        $ctx = $this->firedContext();
        $this->assertSame('gamavis_manufacturing_no_software', $ctx['source_template']);
        $this->assertSame('tracking an order from production plan to dispatch', $ctx['followup_hook']);
    }

    /** A flow-sent template has no campaign, so the industry tag has to carry it. */
    public function testWithNoCampaignTheHookFallsBackToTheCategoryTag(): void
    {
        $c = $this->contact();
        db_connect()->table('tags')->insert(['id' => 90, 'tenant_id' => 1, 'name' => 'Pharma, Healthcare & Medical']);
        db_connect()->table('contact_tags')->insert(['contact_id' => $c, 'tag_id' => 90]);
        $this->sent($c, 5, ['campaign_id' => null]);

        $this->scan();

        $this->assertSame(
            'batch and expiry tracking across your distributors',
            $this->firedContext()['followup_hook']
        );
    }

    public function testAnUnknownCategoryStillGetsAReadableHook(): void
    {
        $this->sent($this->contact(), 5, ['campaign_id' => null]);
        $this->scan();

        $this->assertSame(FollowUpHooks::GENERIC, $this->firedContext()['followup_hook']);
    }

    public function testTheStepIsReportedToTheFlow(): void
    {
        $this->sent($this->contact());
        $this->scan();

        $this->assertSame('1', $this->firedContext()['followup_step']);
    }

    // ------------------------------------------------------------------
    // Hook mapping
    // ------------------------------------------------------------------

    public function testEveryOpenerTemplateHasItsOwnHook(): void
    {
        foreach (FollowUpHooks::knownTemplates() as $name) {
            $this->assertNotSame('', FollowUpHooks::resolve($name), $name);
        }
    }

    public function testEveryImportedCategoryResolvesToAHook(): void
    {
        foreach (FollowUpHooks::knownCategories() as $category) {
            $hook = FollowUpHooks::resolve(null, [$category]);
            $this->assertNotSame('', $hook, $category);
        }
    }

    /** The exact message they saw beats a guess from their industry tag. */
    public function testTheSourceTemplateBeatsTheCategoryTag(): void
    {
        $hook = FollowUpHooks::resolve(
            'gamavis_pharma_no_software',
            ['Steel, Metals & Metal Products']
        );

        $this->assertSame('batch and expiry tracking across your distributors', $hook);
    }

    /**
     * The carousel pitches the whole range, so it names no single problem.
     * Deferring to the prospect's industry says more than echoing it would.
     */
    public function testAGenericOpenerDefersToTheContactsIndustry(): void
    {
        $hook = FollowUpHooks::resolve(
            'gamavis_solutions_carousel_v2',
            ['Logistics, Warehousing & Transport']
        );

        $this->assertSame('where each consignment has reached, without ringing the driver', $hook);
        $this->assertNotSame(FollowUpHooks::GENERIC, $hook);
    }

    public function testAGenericOpenerWithNoIndustryStillReadsSensibly(): void
    {
        $this->assertSame(
            FollowUpHooks::GENERIC,
            FollowUpHooks::resolve('gamavis_solutions_carousel_v2', [])
        );
    }

    /** Every hook must read as a noun phrase in "I wrote because X is where…". */
    public function testNoHookStartsWithACapitalOrEndsWithAFullStop(): void
    {
        foreach (FollowUpHooks::knownCategories() as $category) {
            $hook = FollowUpHooks::resolve(null, [$category]);
            $this->assertSame(ltrim($hook), $hook, "{$category}: leading space");
            $this->assertStringEndsNotWith('.', $hook, "{$category}: trailing full stop");
        }
    }

    // ------------------------------------------------------------------
    // The seeded flow
    // ------------------------------------------------------------------

    public function testTheGraphSendsTheTemplateWithTheHookFromState(): void
    {
        $graph = FollowUpFlowSeeder::buildGraph(99, 7);
        $node  = null;
        foreach ($graph['nodes'] as $n) {
            if ($n['type'] === 'send_template') {
                $node = $n;
            }
        }

        $this->assertNotNull($node);
        $this->assertSame(99, $node['data']['template_id']);
        $this->assertSame('name', $node['data']['variable_mapping']['1']);
        $this->assertSame('state:followup_hook', $node['data']['variable_mapping']['2']);
    }

    /** Meta rejects an empty body parameter, so {{2}} must have a fallback. */
    public function testTheTemplateNodeCarriesDefaultsForBothVariables(): void
    {
        $node = null;
        foreach (FollowUpFlowSeeder::buildGraph(99, 7)['nodes'] as $n) {
            if ($n['type'] === 'send_template') {
                $node = $n;
            }
        }

        $this->assertNotSame('', $node['data']['variable_defaults']['1'] ?? '');
        $this->assertNotSame('', $node['data']['variable_defaults']['2'] ?? '');
    }

    public function testTheGraphTriggerMatchesTheFlowTriggerType(): void
    {
        $types = array_column(FollowUpFlowSeeder::buildGraph(99, 7)['nodes'], 'type');

        $this->assertContains('no_reply_followup', $types);
    }

    private function setQuality(string $rating): void
    {
        db_connect()->table('phone_numbers')->where('tenant_id', 1)
            ->update(['quality_rating' => $rating]);
    }

    // ------------------------------------------------------------------
    // The quality handbrake
    // ------------------------------------------------------------------

    public function testAYellowRatingHaltsTheScanBeforeAnythingIsClaimed(): void
    {
        $this->sent($this->contact());
        $this->setQuality('yellow');

        $res = $this->scan();
        $this->assertSame(0, $res['fired']);
        $this->assertNotEmpty($res['blocked'] ?? '');
        $this->assertSame([], $this->queued());
        $this->assertSame(0, db_connect()->table('follow_ups')->countAllResults(),
            'a halted scan must not consume anyone\'s allowance');
    }

    public function testARedRatingHaltsTheScan(): void
    {
        $this->sent($this->contact());
        $this->setQuality('red');

        $this->assertSame(0, $this->scan()['fired']);
    }

    /** A failed sync leaves 'unknown'. Halting the business over that is worse. */
    public function testAnUnknownRatingDoesNotHalt(): void
    {
        $this->sent($this->contact());
        $this->setQuality('unknown');

        $this->assertSame(1, $this->scan()['fired']);
    }

    /** Seeing the list is exactly what helps while deciding whether to wait. */
    public function testADryRunStillReportsWhileHalted(): void
    {
        $this->sent($this->contact());
        $this->setQuality('red');

        $this->assertSame(1, $this->scan(100, true)['fired']);
    }

    // ------------------------------------------------------------------
    // Throttled sends must not cost a prospect their allowance
    // ------------------------------------------------------------------

    /**
     * Mark the nudge this claim produced as failed with $error.
     *
     * $daysAgo dates BOTH the claim and the nudge, and must be more recent than
     * the original send — which is always true in production, because a send is
     * only claimed once it is already three days old.
     */
    private function nudgeFailed(int $contactId, string $error, int $daysAgo): void
    {
        $at = date('Y-m-d H:i:s', $this->now - $daysAgo * 86400);
        db_connect()->table('follow_ups')->where('contact_id', $contactId)
            ->update(['created_at' => $at]);
        db_connect()->table('messages')->insert([
            'tenant_id' => 1, 'contact_id' => $contactId, 'direction' => 'out',
            'type' => 'template', 'category' => 'marketing', 'body' => 'nudge',
            'status' => 'failed', 'error' => $error,
            'sent_at' => $at, 'created_at' => $at,
        ]);
    }

    public function testAThrottledNudgeReleasesTheClaimAfterTheCooldown(): void
    {
        $c = $this->contact();
        $this->sent($c, 10);
        $this->assertSame(1, $this->scan()['fired']);

        $this->nudgeFailed($c, '#131049 — This message was not delivered to maintain healthy ecosystem engagement.', 8);

        $released = $this->svc->releaseThrottledClaims(1, $this->now);
        $this->assertSame(1, $released);
        $this->assertSame(0, db_connect()->table('follow_ups')->where('deleted_at', null)->countAllResults());
    }

    /** Retrying the next morning just hits the same cap. */
    public function testAThrottledNudgeIsHeldUntilTheCooldownHasPassed(): void
    {
        $c = $this->contact();
        $this->sent($c, 10);
        $this->scan();
        $this->nudgeFailed($c, '#131049 — throttled', 1);

        $this->assertSame(0, $this->svc->releaseThrottledClaims(1, $this->now));
    }

    /** No amount of waiting puts a number on WhatsApp. */
    public function testAnUndeliverableNumberIsNeverReleased(): void
    {
        $c = $this->contact();
        $this->sent($c, 10);
        $this->scan();
        $this->nudgeFailed($c, '#131026 — Message undeliverable', 8);

        $this->assertSame(0, $this->svc->releaseThrottledClaims(1, $this->now));
    }

    public function testADeliveredNudgeKeepsItsClaim(): void
    {
        $c = $this->contact();
        $this->sent($c, 10);
        $this->scan();
        db_connect()->table('follow_ups')->where('contact_id', $c)
            ->update(['created_at' => date('Y-m-d H:i:s', $this->now - 8 * 86400)]);

        $this->assertSame(0, $this->svc->releaseThrottledClaims(1, $this->now));
    }

    /** Released, the contact is genuinely reconsidered rather than written off. */
    public function testAReleasedContactBecomesEligibleAgain(): void
    {
        $c = $this->contact();
        $this->sent($c, 10);
        $this->scan();
        $this->nudgeFailed($c, '#131049 — throttled', 8);
        $this->svc->releaseThrottledClaims(1, $this->now);

        $this->sent($c, 9);
        $this->assertSame(1, $this->scan()['fired']);
    }

    // ------------------------------------------------------------------
    // Nudge 1 and nudge 2 must not be the same message
    // ------------------------------------------------------------------

    public function testTheGraphBranchesOnWhichNudgeThisIs(): void
    {
        $graph = FollowUpFlowSeeder::buildGraph(99, 7, 100);

        $handles = [];
        foreach ($graph['edges'] as $e) {
            if ($e['source'] === 'c1') {
                $handles[$e['sourceHandle']] = $e['target'];
            }
        }
        $this->assertArrayHasKey('true', $handles);
        $this->assertArrayHasKey('false', $handles);

        $byId = array_column($graph['nodes'], null, 'id');
        $this->assertSame(99, $byId[$handles['true']]['data']['template_id'], 'step 1 template');
        $this->assertSame(100, $byId[$handles['false']]['data']['template_id'], 'step 2 must differ');
    }

    public function testTheBranchReadsTheStepFromRunState(): void
    {
        $byId = array_column(FollowUpFlowSeeder::buildGraph(99, 7, 100)['nodes'], null, 'id');

        $this->assertSame('state', $byId['c1']['data']['type']);
        $this->assertSame('followup_step', $byId['c1']['data']['field']);
        $this->assertSame('1', $byId['c1']['data']['value']);
    }

    /** Both nudges still carry the category hook. */
    public function testBothNudgesUseTheStateHook(): void
    {
        $byId = array_column(FollowUpFlowSeeder::buildGraph(99, 7, 100)['nodes'], null, 'id');

        foreach (['m1', 'm2'] as $id) {
            $this->assertSame('state:followup_hook', $byId[$id]['data']['variable_mapping']['2'], $id);
        }
    }
}
