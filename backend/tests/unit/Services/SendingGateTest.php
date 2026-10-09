<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ConversationModel;
use App\Services\Flow\FlowEngine;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\SendingGate;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * The quality handbrake.
 *
 * Its whole job is to be the difference between "a bad week" and "a restricted
 * number" while nobody is watching, so the tests that matter are the two edges:
 * it must stop marketing the moment Meta says quality has dropped, and it must
 * never stop a utility message, which is a promise already made to somebody who
 * booked a demo.
 */
class SendingGateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FlowTestSchema;

    protected $migrate = false;
    protected $refresh = true;

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

    private function setQuality(string $rating, int $id = 1): void
    {
        db_connect()->table('phone_numbers')->where('id', $id)
            ->update(['quality_rating' => $rating]);
    }

    private function gate(): SendingGate
    {
        return new SendingGate();
    }

    // ------------------------------------------------------------------
    // The rating itself
    // ------------------------------------------------------------------

    public function testGreenAllowsMarketing(): void
    {
        $this->setQuality('green');

        $this->assertTrue($this->gate()->allowsMarketing(1));
        $this->assertNull($this->gate()->blockedReason(1));
    }

    /** Waiting for red means waiting for the damage to be done. */
    public function testYellowBlocksMarketing(): void
    {
        $this->setQuality('yellow');

        $this->assertFalse($this->gate()->allowsMarketing(1));
        $this->assertStringContainsString('YELLOW', (string) $this->gate()->blockedReason(1));
    }

    public function testRedBlocksMarketing(): void
    {
        $this->setQuality('red');

        $this->assertFalse($this->gate()->allowsMarketing(1));
    }

    /**
     * 'unknown' is the column default and what a failed sync leaves behind.
     * Halting the business over a timed-out API call is the worse failure.
     */
    public function testUnknownDoesNotBlock(): void
    {
        $this->setQuality('unknown');

        $this->assertTrue($this->gate()->allowsMarketing(1));
    }

    /** Numbers share one WABA reputation, so the unhealthiest one governs. */
    public function testTheWorstNumberGovernsTheTenant(): void
    {
        db_connect()->table('phone_numbers')->insert([
            'id' => 2, 'waba_account_id' => 1, 'tenant_id' => 1,
            'phone_number_id' => 'pn2', 'display_number' => '+919999900001',
            'quality_rating' => 'red', 'is_default' => 0,
        ]);
        $this->setQuality('green', 1);

        $this->assertFalse($this->gate()->allowsMarketing(1));
        $this->assertStringContainsString('RED', (string) $this->gate()->blockedReason(1));
    }

    public function testAnotherTenantsRatingIsIrrelevant(): void
    {
        db_connect()->table('phone_numbers')->insert([
            'id' => 3, 'waba_account_id' => 1, 'tenant_id' => 2,
            'phone_number_id' => 'pn3', 'display_number' => '+919999900002',
            'quality_rating' => 'red', 'is_default' => 1,
        ]);
        $this->setQuality('green', 1);

        $this->assertTrue($this->gate()->allowsMarketing(1));
    }

    public function testATenantWithNoNumbersIsNotBlocked(): void
    {
        $this->assertTrue($this->gate()->allowsMarketing(999));
    }

    /** The reason is logged by every caller, so it has to say what to do. */
    public function testTheReasonNamesTheNumberAndSaysItRecoversByItself(): void
    {
        $this->setQuality('red');
        $reason = (string) $this->gate()->blockedReason(1);

        $this->assertStringContainsString('+919999900000', $reason);
        $this->assertStringContainsString('resumes automatically', $reason);
    }

    // ------------------------------------------------------------------
    // Enforcement inside a running flow
    // ------------------------------------------------------------------

    private function runTemplateNode(string $category): array
    {
        $wa        = '+919991030001';
        $contactId = $this->seedContact($wa);
        $tplId     = $this->seedApprovedTemplate(['category' => $category]);

        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created',  'data' => []],
                ['id' => 'm', 'type' => 'send_template', 'data' => ['template_id' => $tplId]],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'm', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'm');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        (new FlowEngine(
            new CloudApiClient('mock_phone', 'mock_token'),
            new WindowService(new ConversationModel(), $this->now),
            $this->now
        ))->advance($run, 1);

        return (array) db_connect()->table('messages')
            ->where('contact_id', $contactId)->get()->getRowArray();
    }

    public function testADegradedRatingStopsAMarketingTemplateMidFlow(): void
    {
        $this->setQuality('red');
        $msg = $this->runTemplateNode('marketing');

        $this->assertSame('failed', $msg['status']);
        $this->assertSame(0, (int) $msg['billable'], 'nothing was sent, so nothing is billable');
        $this->assertStringContainsString('Marketing paused', (string) $msg['error']);
    }

    /**
     * A meeting reminder is a promise already made to somebody who booked a
     * demo. Dropping it silently damages the very relationship the quality
     * rating exists to protect.
     */
    public function testADegradedRatingNeverStopsAUtilityTemplate(): void
    {
        $this->setQuality('red');
        $msg = $this->runTemplateNode('utility');

        $this->assertSame('sent', $msg['status']);
    }

    /**
     * The run advances rather than stopping. A halted run would strand every
     * contact on this node, still stranded when the rating recovered.
     */
    public function testTheRunStillCompletesSoContactsAreNotStranded(): void
    {
        $this->setQuality('red');
        $wa        = '+919991030002';
        $contactId = $this->seedContact($wa);
        $tplId     = $this->seedApprovedTemplate(['category' => 'marketing']);

        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created',  'data' => []],
                ['id' => 'm', 'type' => 'send_template', 'data' => ['template_id' => $tplId]],
                ['id' => 'g', 'type' => 'update_status', 'data' => ['status' => 'contacted']],
            ],
            [
                ['id' => 'e1', 'source' => 't', 'target' => 'm', 'sourceHandle' => 'next'],
                ['id' => 'e2', 'source' => 'm', 'target' => 'g', 'sourceHandle' => 'next'],
            ]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'm');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        (new FlowEngine(
            new CloudApiClient('mock_phone', 'mock_token'),
            new WindowService(new ConversationModel(), $this->now),
            $this->now
        ))->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('completed', $run['status']);

        $contact = db_connect()->table('contacts')->where('id', $contactId)->get()->getRowArray();
        $this->assertSame('contacted', $contact['status'], 'the rest of the graph must still run');
    }
}
