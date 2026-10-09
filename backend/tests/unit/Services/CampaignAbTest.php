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
 * Tests A/B variant bucketing + per-message variant stamping in CampaignSender.
 */
class CampaignAbTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    private static int $waSeq = 30000;

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

    private function makeSender(): CampaignSender
    {
        return new CampaignSender(
            new CampaignModel(), new TemplateModel(), new ContactModel(),
            new MessageModel(), new ConversationModel(),
            new VariableResolver(), new SegmentResolver(),
            new WindowService(new ConversationModel()),
            new CloudApiClient('mock_phone', 'mock_token'),
        );
    }

    private function nextWa(): string
    {
        self::$waSeq++;
        return '+9199955' . str_pad((string) self::$waSeq, 5, '0', STR_PAD_LEFT);
    }

    public function testAbSplitStampsVariantsAndUsesBothTemplates(): void
    {
        $tplA = $this->seedApprovedTemplate(['category' => 'utility', 'name' => 'tpl_a', 'body' => 'A body']);
        $tplB = $this->seedApprovedTemplate(['category' => 'utility', 'name' => 'tpl_b', 'body' => 'B body']);
        $campId = $this->seedCampaign($tplA, ['variant_template_id' => $tplB, 'ab_split' => 50]);

        // Insert contacts with explicit ids so bucketing (id % 100) is deterministic.
        // ids < 50 (mod 100) → variant B; ids >= 50 → variant A.
        $db = db_connect();
        foreach ([10, 20, 30, 60, 70, 80] as $id) {
            $db->table('contacts')->insert([
                'id' => $id, 'tenant_id' => 1, 'wa_number' => $this->nextWa(),
                'name' => "C{$id}", 'opt_in' => 1, 'status' => 'new', 'source' => 'manual',
                'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
            ]);
        }

        $result = $this->makeSender()->processBatch($campId, 1);
        $this->assertSame(6, $result['sent']);

        $rows = $db->table('messages')->where('campaign_id', $campId)->get()->getResultArray();
        $byVariant = ['A' => 0, 'B' => 0];
        $bodies = [];
        foreach ($rows as $m) {
            $byVariant[$m['variant']]++;
            $bodies[$m['variant']] = $m['body'];
        }

        $this->assertSame(3, $byVariant['B'], 'ids 10,20,30 → B');
        $this->assertSame(3, $byVariant['A'], 'ids 60,70,80 → A');
        $this->assertSame('A body', $bodies['A']);
        $this->assertSame('B body', $bodies['B']);
    }

    public function testNoVariantLeavesVariantNull(): void
    {
        $tplA = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplA); // no variant

        $this->seedContact($this->nextWa(), ['opt_in' => 1]);
        $this->makeSender()->processBatch($campId, 1);

        $row = db_connect()->table('messages')->where('campaign_id', $campId)->get()->getRowArray();
        $this->assertNull($row['variant']);
    }
}
