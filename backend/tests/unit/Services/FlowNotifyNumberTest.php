<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MessageModel;
use App\Services\Flow\FlowEngine;
use App\Services\WhatsApp\CloudApiClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * A lead tapping "Book Free Demo" on a carousel is worth nothing if nobody
 * notices. Every other send node targets the contact; notify_number targets the
 * business's own phone, and has to say WHO tapped and WHICH card they tapped.
 *
 * The card is the hard part: every card's button carries the same visible label,
 * so only the quick-reply payload (card{i}_btn{j}) distinguishes them. That
 * payload reaches the flow through the trigger context, and label_map turns it
 * into something a human can read at a glance.
 */
class FlowNotifyNumberTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FlowTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    private const ALERT_TO = '+919718991797';

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
        $this->now = mktime(12, 0, 0, 1, 15, 2026);
        $this->createFlowSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_MOCK_MODE']);
        parent::tearDown();
    }

    public function testAlertNamesTheCustomerAndTheCardTheyTapped(): void
    {
        $detail = $this->runAlert('card2_btn0');

        $this->assertStringContainsString(self::ALERT_TO, $detail, 'must target the business number, not the contact');
        $this->assertStringContainsString('Rajesh Kumar', $detail);
        $this->assertStringContainsString('Operations & Inventory', $detail, 'card2 must map to its own label');
    }

    public function testEachCardResolvesToItsOwnLabel(): void
    {
        $this->assertStringContainsString('CRM & ERP Overview', $this->runAlert('card0_btn0'));
        $this->assertStringContainsString('All-in-one CRM', $this->runAlert('card1_btn0'));
    }

    /**
     * A new card added to the template without updating label_map must still
     * produce a usable alert rather than a blank one — Meta rejects an empty
     * body parameter outright, which would lose the lead entirely.
     */
    public function testUnmappedCardFallsBackInsteadOfSendingBlank(): void
    {
        $detail = $this->runAlert('card9_btn0');

        $this->assertStringContainsString('Gamavis Carousel', $detail);
    }

    public function testMissingContactNameDoesNotProduceAnEmptyParameter(): void
    {
        $detail = $this->runAlert('card0_btn0', ['name' => '']);

        $this->assertStringNotContainsString('| |', $detail, 'an empty parameter would be rejected by Meta');
        $this->assertMatchesRegularExpression('/:\s*-\s*\|/', $detail, 'blank name must become a placeholder');
    }

    /**
     * The alert belongs to the business, not to the customer's thread. Writing
     * it there would make the Inbox read as though the customer was messaged.
     */
    public function testAlertIsNotLoggedIntoTheCustomerConversation(): void
    {
        $this->runAlert('card0_btn0', [], false);

        $this->assertSame(0, (new MessageModel())->withoutTenantScope()->countAllResults());
    }

    /**
     * An alert template that is not approved must not break the branch the
     * customer is on — they still get their reply.
     */
    public function testUnapprovedAlertTemplateDoesNotAbortTheRun(): void
    {
        $detail = $this->runAlert('card0_btn0', [], true, 'pending');

        $this->assertStringContainsString('not approved', $detail);
    }


    /**
     * A run parked on send_interactive resumes carrying last_button_id. The
     * alert must describe the button just tapped, not the message that started
     * the run — otherwise "Yes, connect me" would be reported as the greeting.
     */
    public function testAlertDescribesTheButtonJustTappedNotTheOriginalTrigger(): void
    {
        $detail = $this->runAlert('card0_btn0', [], true, 'approved', 'card2_btn0');

        $this->assertStringContainsString('Operations & Inventory', $detail);
        $this->assertStringNotContainsString('CRM & ERP Overview', $detail);
    }

    // ── harness ───────────────────────────────────────────────────────

    /** @return string the node's execution log detail */
    /**
     * A lead-ad submission is the whole point of the alert: company, the
     * form's answers and the source must reach the owner in one message.
     */
    public function testAlertCanCarryCompanyAnswersAndSource(): void
    {
        $db = db_connect();
        $db->table('accounts')->insert(['tenant_id' => 1, 'name' => 'Sharma Textiles', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
        $accountId = (int) $db->insertID();
        $db->table('custom_fields')->insert(['tenant_id' => 1, 'label' => 'What type of business do you operate?', 'field_key' => 'what_type_of_business_do_you_operate', 'type' => 'text', 'created_at' => '2026-01-01']);
        $cf1 = (int) $db->insertID();
        $db->table('custom_fields')->insert(['tenant_id' => 1, 'label' => 'Budget', 'field_key' => 'budget', 'type' => 'text', 'restricted' => 1, 'created_at' => '2026-01-01']);
        $cf2 = (int) $db->insertID();

        $detail = $this->runAlert('card0_btn0', [
            'name' => 'Asha Patel', 'source' => 'meta_lead_ads', 'account_id' => $accountId, 'job_title' => 'Director',
        ], true, 'approved', null, [
            '{{contact.company}}', '{{custom.what_type_of_business_do_you_operate}}', '{{lead.answers}}', '{{lead.source}}', '{{contact.job_title}}', '{{slot}}',
        ], static function (int $contactId) use ($db, $cf1, $cf2): void {
            $db->table('contact_field_values')->insert(['contact_id' => $contactId, 'custom_field_id' => $cf1, 'value' => 'Manufacturing']);
            $db->table('contact_field_values')->insert(['contact_id' => $contactId, 'custom_field_id' => $cf2, 'value' => '5 lakh']);
        }, ['booked_slot' => 'Fri 26 Sep, 11:00 AM']);

        $this->assertStringContainsString('Sharma Textiles', $detail);
        $this->assertStringContainsString('Manufacturing', $detail);
        $this->assertStringContainsString('What type of business do you operate: Manufacturing', $detail, 'answers carry the question label');
        $this->assertStringNotContainsString('5 lakh', $detail, 'owner-only (restricted) fields never leave the CRM');
        $this->assertStringContainsString('Meta Lead Ads', $detail);
        $this->assertStringContainsString('Director', $detail);
        $this->assertStringContainsString('Fri 26 Sep, 11:00 AM', $detail);
    }

    public function testASentenceParameterResolvesTheTokensInsideIt(): void
    {
        $detail = $this->runAlert('card0_btn0', [], true, 'approved', null,
            ['{{contact.name}}', 'booked a call for {{slot}} — tapped {{button_title}}'], null,
            ['booked_slot' => 'Fri 26 Sep, 11:00 AM']);

        $this->assertStringContainsString('booked a call for Fri 26 Sep, 11:00 AM — tapped Book Free Demo', $detail);
    }

    public function testUnknownTokensStillProduceANonEmptyParameter(): void
    {
        $detail = $this->runAlert('card0_btn0', ['account_id' => null], true, 'approved', null,
            ['{{contact.company}}', '{{custom.nope}}', '{{lead.answers}}', '{{slot}}']);

        $this->assertStringNotContainsString('| |', $detail);
        $this->assertSame(0, preg_match('/:\s*\|/', $detail), 'every blank becomes a placeholder');
    }

    private function runAlert(
        string $payload,
        array $contactOverrides = [],
        bool $dryRun = true,
        string $templateStatus = 'approved',
        ?string $lastButtonId = null,
        ?array $params = null,
        ?callable $afterContact = null,
        array $extraState = [],
    ): string {
        static $seq = 0;
        $seq++;
        $contactId  = $this->seedContact(
            sprintf('+9190000000%02d', $seq),
            array_merge(['name' => 'Rajesh Kumar'], $contactOverrides)
        );
        if ($afterContact !== null) {
            $afterContact($contactId);
        }
        $templateId = $this->seedApprovedTemplate([
            'name'        => 'demo_request_alert',
            'category'    => 'utility',
            'body'        => 'Demo request from {{1}} ({{2}}) — interested in {{3}}',
            'meta_status' => $templateStatus,
        ]);

        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'keyword_reply', 'data' => []],
                ['id' => 'n', 'type' => 'notify_number', 'data' => [
                    'to'            => self::ALERT_TO,
                    'template_id'   => $templateId,
                    'params'        => $params ?? ['{{contact.name}}', '{{contact.wa_number}}', '{{label}}'],
                    'label_map'     => [
                        'card0_btn0' => 'CRM & ERP Overview',
                        'card1_btn0' => 'All-in-one CRM',
                        'card2_btn0' => 'Operations & Inventory',
                    ],
                    'label_default' => 'Gamavis Carousel',
                ]],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'n', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph, 'keyword_reply');
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'n', [
            'state'   => json_encode(array_filter([
                'button_id'      => $payload,
                'button_title'   => 'Book Free Demo',
                'last_button_id' => $lastButtonId,
            ] + $extraState, static fn ($v) => $v !== null)),
            'is_test' => $dryRun ? 1 : 0,
        ]);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        (new FlowEngine(new CloudApiClient('mock_phone', 'mock_token'), null, $this->now))->advance($run, 1);

        $log = db_connect()->table('flow_run_logs')
            ->where('flow_run_id', $runId)->where('node_id', 'n')
            ->get()->getRowArray();

        return (string) ($log['detail'] ?? '');
    }
}
