<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\CampaignModel;
use App\Models\ContactFieldValueModel;
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
 * Tests opt-in enforcement logic in CampaignSender.
 *
 * Marketing campaigns must skip opt_in=0 contacts.
 * Utility campaigns must send to ALL contacts regardless of opt_in.
 *
 * Uses WHATSAPP_MOCK_MODE so no real Meta API calls are made.
 */
class CampaignOptInTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    private static int $waSeq = 8000;

    protected function setUp(): void
    {
        parent::setUp();
        // Enable mock mode so CloudApiClient doesn't call Meta
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
        // CloudApiClient with mock mode will return fake success responses
        $mockClient = new CloudApiClient('mock_phone', 'mock_token');

        return new CampaignSender(
            new CampaignModel(),
            new TemplateModel(),
            new ContactModel(),
            new MessageModel(),
            new ConversationModel(),
            new VariableResolver(),
            new SegmentResolver(),
            new WindowService(new ConversationModel()),
            $mockClient, // inject mock client — no WABA lookup needed
        );
    }

    private function nextWa(): string
    {
        self::$waSeq++;
        return '+9199988' . str_pad((string) self::$waSeq, 5, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------
    // Marketing: opt_in=0 contacts must be skipped
    // ------------------------------------------------------------------

    public function testMarketingCampaignSkipsOptOutContacts(): void
    {
        $tplId  = $this->seedApprovedTemplate(['category' => 'marketing']);
        $campId = $this->seedCampaign($tplId);

        // 2 opted-in + 1 opted-out
        $this->seedContact($this->nextWa(), ['opt_in' => 1]);
        $this->seedContact($this->nextWa(), ['opt_in' => 1]);
        $this->seedContact($this->nextWa(), ['opt_in' => 0]); // must be skipped

        $result = $this->makeSender()->processBatch($campId, 1);

        $this->assertSame(2, $result['sent'],   'Only 2 opted-in contacts should be sent to');
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $result['stats']['skipped_opt_out'], 'Opt-out contact must be counted');
    }

    public function testMarketingCampaignSendsToAllOptInContacts(): void
    {
        $tplId  = $this->seedApprovedTemplate(['category' => 'marketing']);
        $campId = $this->seedCampaign($tplId);

        for ($i = 0; $i < 3; $i++) {
            $this->seedContact($this->nextWa(), ['opt_in' => 1]);
        }

        $result = $this->makeSender()->processBatch($campId, 1);
        $this->assertSame(3, $result['sent']);
        $this->assertSame(0, $result['stats']['skipped_opt_out']);
    }

    // ------------------------------------------------------------------
    // Utility: opt_in does NOT gate sends
    // ------------------------------------------------------------------

    public function testUtilityCampaignSendsToOptOutContacts(): void
    {
        $tplId  = $this->seedApprovedTemplate(['category' => 'utility']);
        $campId = $this->seedCampaign($tplId);

        $this->seedContact($this->nextWa(), ['opt_in' => 1]);
        $this->seedContact($this->nextWa(), ['opt_in' => 0]); // utility: should still receive

        $result = $this->makeSender()->processBatch($campId, 1);

        $this->assertSame(2, $result['sent'],   'Utility must send to both contacts');
        $this->assertSame(0, $result['stats']['skipped_opt_out']);
    }

    // ------------------------------------------------------------------
    // No contacts at all
    // ------------------------------------------------------------------

    public function testCampaignWithNoContactsCompletesImmediately(): void
    {
        $tplId  = $this->seedApprovedTemplate(['category' => 'marketing']);
        $campId = $this->seedCampaign($tplId);

        $result = $this->makeSender()->processBatch($campId, 1);

        $this->assertSame('done', $result['status']);
        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['total']);
    }
}
