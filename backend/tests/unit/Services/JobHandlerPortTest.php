<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Commands\FlowWork;
use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\ContactFieldValueModel;
use App\Models\LeadImportModel;
use App\Models\MessageModel;
use App\Models\TemplateModel;
use App\Services\Flow\JobDispatcher;
use App\Services\Leads\CampaignSender;
use App\Services\Leads\SegmentResolver;
use App\Services\Leads\VariableResolver;
use App\Services\Queue\CampaignSendHandler;
use App\Services\Queue\LeadImportHandler;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CampaignTestSchema;

/**
 * Proves the campaign_send and lead_import job handlers produce identical
 * results to the old direct-call path.
 *
 * ── Sprint 4 Phase 5 design contract ─────────────────────────────────
 * processBatch() bodies are VERBATIM from their origin sprints.
 * Handlers are thin wrappers that:
 *   1. Call processBatch() (unchanged)
 *   2. Re-enqueue for the next batch when status='processing'
 *   3. Stop when status='done'
 *
 * ── Crash / resume durability proof ──────────────────────────────────
 * The keyset cursor is committed inside processBatch() before the handler
 * returns. If the handler throws after that commit, a retry resumes from
 * the committed cursor — zero duplicate sends.
 * testCrashAfterBatchCommitNoduplicates() proves this by simulating
 * separate handler invocations (each = one worker "run") and asserting
 * total messages == total contacts.
 */
class JobHandlerPortTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
        $this->createCampaignSchema();
        $this->ensureJobsTable();
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_MOCK_MODE']);
        parent::tearDown();
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function ensureJobsTable(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, type TEXT NOT NULL, payload TEXT,
            run_at TEXT NOT NULL, status TEXT DEFAULT 'pending',
            attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 5,
            locked_at TEXT, locked_by TEXT, last_error TEXT,
            created_at TEXT, updated_at TEXT
        )");
        $db->query("DELETE FROM {$p}jobs");
    }

    private function fakeJob(string $type, array $payload): array
    {
        return [
            'id'           => 1,
            'tenant_id'    => 1,
            'type'         => $type,
            'payload'      => json_encode($payload),
            'attempts'     => 1,
            'max_attempts' => 5,
        ];
    }

    /** Small-batch sender (BATCH_SIZE=2) for multi-batch tests. */
    private function smallBatchSender(): CampaignSender
    {
        return new class(
            new CampaignModel(),
            new TemplateModel(),
            new ContactModel(),
            new MessageModel(),
            new ConversationModel(),
            new VariableResolver(),
            new SegmentResolver(),
            new WindowService(new ConversationModel()),
            new CloudApiClient('mock_phone', 'mock_token'),
        ) extends CampaignSender {
            public const BATCH_SIZE = 2;
        };
    }

    private function outboundMessageCount(): int
    {
        return (int) db_connect()->table('messages')->where('direction', 'out')->countAllResults();
    }

    private function getCampaign(int $id): array
    {
        return db_connect()->table('campaigns')->where('id', $id)->get()->getRowArray();
    }

    // ------------------------------------------------------------------
    // Handler produces same outcome as direct processBatch() call
    // ------------------------------------------------------------------

    public function testCampaignHandlerProducesSameOutcomeAsDirectCall(): void
    {
        // Setup: 3 contacts, all processed in one batch (BATCH_SIZE=500)
        for ($i = 1; $i <= 3; $i++) {
            $this->seedContact("+9199980000{$i}");
        }
        $tplId   = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId  = $this->seedCampaign($tplId);

        // ── Direct call baseline ──────────────────────────────────────
        $directResult = (new CampaignSender(
            new CampaignModel(), new TemplateModel(), new ContactModel(),
            new MessageModel(), new ConversationModel(), new VariableResolver(),
            new SegmentResolver(), new WindowService(new ConversationModel()),
            new CloudApiClient('mock_phone', 'mock_token'),
        ))->processBatch($campId, 1);

        $directSent   = $directResult['sent'];
        $directStatus = $directResult['status'];
        $directMsgs   = $this->outboundMessageCount();

        // ── Reset to pre-call state ───────────────────────────────────
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("UPDATE {$p}campaigns SET cursor=0, sent_count=0, failed_count=0, status='draft', total_contacts=0, stats=NULL WHERE id=?", [$campId]);
        $db->query("DELETE FROM {$p}messages");

        // ── Via handler ───────────────────────────────────────────────
        (new CampaignSendHandler())->handle($this->fakeJob('campaign_send', ['campaign_id' => $campId]), 1);

        $campaign = $this->getCampaign($campId);
        $this->assertSame($directStatus, $campaign['status'], 'Handler must produce the same campaign status as direct call');
        $this->assertSame($directSent,   (int) $campaign['sent_count'], 'Handler must send the same number of messages');
        $this->assertSame($directMsgs,   $this->outboundMessageCount(), 'Message count must match direct call');

        // No re-enqueue (all done in one batch)
        $jobCount = db_connect()->table('jobs')->where('type', 'campaign_send')->countAllResults();
        $this->assertSame(0, $jobCount, 'Handler must not re-enqueue when status=done');
    }

    // ------------------------------------------------------------------
    // Handler re-enqueues for next batch when processing
    // ------------------------------------------------------------------

    public function testCampaignHandlerReenqueuesWhenProcessing(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedContact("+9199970000{$i}");
        }
        $tplId  = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplId);

        // Inject small-batch sender (BATCH_SIZE=2)
        $handler = new CampaignSendHandler($this->smallBatchSender());
        $handler->handle($this->fakeJob('campaign_send', ['campaign_id' => $campId]), 1);

        // First batch: 2 contacts processed, status='processing', re-enqueue expected
        $campaign = $this->getCampaign($campId);
        $this->assertSame('processing', $campaign['status']);
        $this->assertSame(2, (int) $campaign['sent_count']);

        $job = db_connect()->table('jobs')->where('type', 'campaign_send')->get()->getRowArray();
        $this->assertNotNull($job, 'Handler must re-enqueue when status=processing');
        $payload = json_decode($job['payload'], true);
        $this->assertSame($campId, $payload['campaign_id']);
    }

    public function testCampaignHandlerDoesNotReenqueueWhenDone(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->seedContact("+9199960000{$i}");
        }
        $tplId  = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplId);

        // Default sender (BATCH_SIZE=500) → all 3 contacts done in one batch
        (new CampaignSendHandler())->handle($this->fakeJob('campaign_send', ['campaign_id' => $campId]), 1);

        $this->assertSame('done', $this->getCampaign($campId)['status']);
        $jobCount = db_connect()->table('jobs')->where('type', 'campaign_send')->countAllResults();
        $this->assertSame(0, $jobCount, 'No re-enqueue on done');
    }

    // ------------------------------------------------------------------
    // CRASH / RESUME DURABILITY PROOF
    //
    // The keyset cursor is committed inside processBatch() BEFORE the handler
    // returns.  If the handler throws after that commit (e.g. before re-enqueue),
    // the job queue retries the job.  On retry, processBatch() reads the committed
    // cursor and processes only the NEXT contacts — zero duplicate sends.
    //
    // This test simulates three sequential worker invocations over 5 contacts
    // with BATCH_SIZE=2:
    //   Invocation 1: "commit batch 1 + crash before re-enqueue" (direct processBatch call)
    //   Invocation 2: handler retry → resumes from cursor → batch 2
    //   Invocation 3: handler retry → batch 3 → done
    //
    // After all three: exactly 5 messages, not 10 or 15.
    // ------------------------------------------------------------------

    public function testCrashAfterBatchCommitNoDuplicates(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedContact("+9199950000{$i}");
        }
        $tplId  = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplId);

        $sender = $this->smallBatchSender();

        // ── Invocation 1: processBatch commits cursor, then "crashes" ─
        // (simulates: commit succeeds, but process dies before re-enqueue)
        $r1 = $sender->processBatch($campId, 1);
        $this->assertSame('processing', $r1['status']);
        $this->assertSame(2, $r1['sent']);
        $this->assertSame(2, $this->outboundMessageCount(), 'Batch 1: 2 messages committed');

        // cursor is now at max(id) of the first 2 contacts — verified implicitly

        // ── Invocation 2: worker retries the job (same job, cursor already advanced) ─
        $handler = new CampaignSendHandler($sender);
        $handler->handle($this->fakeJob('campaign_send', ['campaign_id' => $campId]), 1);
        $this->assertSame(4, $this->outboundMessageCount(), 'Batch 2 retry: 4 total, no duplicates from batch 1');

        // ── Invocation 3: final batch ─────────────────────────────────
        $handler->handle($this->fakeJob('campaign_send', ['campaign_id' => $campId]), 1);

        $this->assertSame(5, $this->outboundMessageCount(),
            '5 total messages for 5 contacts — keyset cursor prevents duplicates across retries');
        $this->assertSame('done', $this->getCampaign($campId)['status']);
    }

    // ------------------------------------------------------------------
    // Terminal failure → source row marked 'failed'
    // ------------------------------------------------------------------

    public function testTerminalFailureMarksCampaignRowFailed(): void
    {
        $tplId  = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplId, ['status' => 'processing']);

        $job = [
            'id'        => 99,
            'type'      => 'campaign_send',
            'tenant_id' => 1,
            'payload'   => json_encode(['campaign_id' => $campId]),
        ];

        // markSourceRowFailed is public static — no BaseCommand constructor needed
        FlowWork::markSourceRowFailed($job, 'simulated terminal error');

        $campaign = $this->getCampaign($campId);
        $this->assertSame('failed', $campaign['status'],
            'Terminal failure must set campaign.status=failed so frontend polling stops');
    }

    public function testTerminalFailureMarksImportRowFailed(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}lead_imports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER, original_filename TEXT, stored_filename TEXT,
            default_country_code TEXT, headers TEXT, mapping TEXT,
            total INTEGER DEFAULT 0, imported INTEGER DEFAULT 0,
            updated_count INTEGER DEFAULT 0, failed INTEGER DEFAULT 0,
            errors TEXT, cursor INTEGER DEFAULT 0,
            status TEXT DEFAULT 'pending',
            created_at TEXT, updated_at TEXT
        )");
        $db->table('lead_imports')->insert([
            'tenant_id' => 1, 'original_filename' => 'test.csv',
            'stored_filename' => 'test.csv', 'status' => 'processing',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $importId = (int) $db->insertID();

        FlowWork::markSourceRowFailed([
            'id'        => 100,
            'type'      => 'lead_import',
            'tenant_id' => 1,
            'payload'   => json_encode(['import_id' => $importId]),
        ], 'simulated terminal error');

        $row = $db->table('lead_imports')->where('id', $importId)->get()->getRowArray();
        $this->assertSame('failed', $row['status'],
            'Terminal failure must set import.status=failed so frontend polling stops');
    }

    // ------------------------------------------------------------------
    // Pause = skip silently, cursor preserved
    // ------------------------------------------------------------------

    public function testPausedCampaignSkipsWithoutSending(): void
    {
        $this->seedContact('+919994000001');
        $tplId  = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplId, ['status' => 'paused']);

        (new CampaignSendHandler())->handle($this->fakeJob('campaign_send', ['campaign_id' => $campId]), 1);

        $this->assertSame(0, $this->outboundMessageCount(), 'Paused campaign must not send');
        $this->assertSame('paused', $this->getCampaign($campId)['status'], 'Status must remain paused');

        $jobCount = db_connect()->table('jobs')->where('type', 'campaign_send')->countAllResults();
        $this->assertSame(0, $jobCount, 'No re-enqueue for paused campaign');
    }
}
