<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\CampaignModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\TemplateModel;
use App\Services\Leads\CampaignSender;
use App\Services\Leads\SegmentResolver;
use App\Services\Leads\VariableResolver;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CampaignTestSchema;

/**
 * Tests the keyset cursor mechanics of CampaignSender.
 *
 * Uses a tiny batch size (2) over 5 contacts to verify:
 *   - Cursor advances to the max(id) of each batch
 *   - Status is 'processing' when batch == BATCH_SIZE
 *   - Status is 'done' when batch < BATCH_SIZE
 *   - Second batch starts from the saved cursor (no re-sends)
 *   - total_contacts is snapshotted correctly on first batch
 */
class CampaignBatchCursorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    private static int $waSeq = 9000;

    /** Tiny batch size for faster cursor testing */
    private const TEST_BATCH = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
        $this->createCampaignSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_MOCK_MODE']);
        parent::tearDown();
    }

    private function makeSender(int $batchSize = self::TEST_BATCH): CampaignSender
    {
        $sender = new class(
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
            public int $batchOverride = 2;
            public const BATCH_SIZE = 2; // override for test
        };
        return $sender;
    }

    private function nextWa(): string
    {
        self::$waSeq++;
        return '+9199977' . str_pad((string) self::$waSeq, 5, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------

    public function testFirstBatchSnapshotsTotalAndAdvancesCursor(): void
    {
        $tplId  = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplId);

        // Create 5 contacts
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->seedContact($this->nextWa());
        }

        $sender = $this->makeSender();
        $r1     = $sender->processBatch($campId, 1);

        // First batch: should process 2 contacts (BATCH_SIZE=2), cursor = id of 2nd contact
        $this->assertSame(2, $r1['sent']);
        $this->assertSame('processing', $r1['status']);
        $this->assertSame(5, $r1['total'], 'total_contacts snapshotted as 5');
        $this->assertSame($ids[1], $r1['cursor'], 'Cursor = max id of batch 1');
    }

    public function testSecondBatchStartsFromSavedCursor(): void
    {
        $tplId  = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplId);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->seedContact($this->nextWa());
        }

        $sender = $this->makeSender();
        $r1     = $sender->processBatch($campId, 1); // batch 1
        $r2     = $sender->processBatch($campId, 1); // batch 2

        $this->assertSame(2, $r2['sent'] - $r1['sent'],   '2 more sent in batch 2');
        $this->assertSame('processing', $r2['status']);
        $this->assertSame($ids[3], $r2['cursor'], 'Cursor = max id of batch 2 (index 3)');
    }

    public function testThirdBatchCompletesWhenFewContactsRemain(): void
    {
        $tplId  = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplId);

        for ($i = 0; $i < 5; $i++) {
            $this->seedContact($this->nextWa());
        }

        $sender = $this->makeSender();
        $sender->processBatch($campId, 1); // batch 1: 2 contacts
        $sender->processBatch($campId, 1); // batch 2: 2 contacts
        $r3 = $sender->processBatch($campId, 1); // batch 3: 1 contact (< BATCH_SIZE → done)

        $this->assertSame('done', $r3['status']);
        $this->assertSame(5, $r3['sent'], 'All 5 contacts sent across 3 batches');
    }

    public function testCursorPreventsDoubleSend(): void
    {
        $tplId  = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplId);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->seedContact($this->nextWa());
        }

        $sender = $this->makeSender();
        $sender->processBatch($campId, 1); // processes contacts 0,1
        $sender->processBatch($campId, 1); // processes contact 2 → done

        // Count messages sent — must be exactly 3, not 5 (no double send)
        $count = db_connect()->table('messages')
            ->where('direction', 'out')
            ->countAllResults();
        $this->assertSame(3, $count, 'Exactly 3 messages — no double-send from cursor');
    }

    // Stronger guarantee: even if the cursor is RESET to 0 (a crash mid-batch or
    // a stale-lock reclaim re-running from the start), the UNIQUE(campaign_id,
    // contact_id) reservation must prevent any contact from being sent twice.
    public function testResetCursorDoesNotDoubleSend(): void
    {
        $tplId  = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplId);
        for ($i = 0; $i < 3; $i++) {
            $this->seedContact($this->nextWa());
        }

        $sender = $this->makeSender();
        $sender->processBatch($campId, 1);
        $sender->processBatch($campId, 1); // all 3 sent, campaign done

        $afterFirst = db_connect()->table('messages')->where('direction', 'out')->countAllResults();
        $this->assertSame(3, $afterFirst);

        // Simulate a crash/reclaim: rewind the cursor and re-run from the start.
        db_connect()->table('campaigns')->where('id', $campId)
            ->update(['cursor' => 0, 'status' => 'processing']);
        $sender->processBatch($campId, 1);
        $sender->processBatch($campId, 1);

        $afterRerun = db_connect()->table('messages')->where('direction', 'out')->countAllResults();
        $this->assertSame(3, $afterRerun, 'Re-run must NOT re-send — still exactly 3 messages');
    }

    public function testSingleContactCompletesInOneBatch(): void
    {
        $tplId  = $this->seedApprovedTemplate(['category' => 'marketing']);
        $campId = $this->seedCampaign($tplId);
        $this->seedContact($this->nextWa(), ['opt_in' => 1]);

        $result = $this->makeSender()->processBatch($campId, 1);

        $this->assertSame('done', $result['status']);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['total']);
    }
}
