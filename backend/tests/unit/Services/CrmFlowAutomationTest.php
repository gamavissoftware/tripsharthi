<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Flow\FlowEngine;
use App\Services\Flow\FlowTriggerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase C: WhatsApp × CRM automation.
 *  - Deal events fire the right flow triggers (with stage matching).
 *  - CRM action executors (create_task / update_field / create_deal) mutate the
 *    right rows and respect dry-run (flow test mode).
 */
class CrmFlowAutomationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
        $this->now = mktime(12, 0, 0, 1, 15, 2026);
        $this->createCrmSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_MOCK_MODE']);
        parent::tearDown();
    }

    private function engine(): FlowEngine
    {
        return new FlowEngine(new \App\Services\WhatsApp\CloudApiClient('mock_phone', 'mock_token'), null, $this->now);
    }

    /** Run a single-action flow on a contact and return the contact id. */
    private function runAction(string $waNumber, string $type, array $data): int
    {
        $contactId = $this->seedContact($waNumber);
        $graph = $this->makeGraph(
            [['id' => 't', 'type' => 'lead_created', 'data' => []], ['id' => 'a', 'type' => $type, 'data' => $data]],
            [['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next']]
        );
        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'a');
        $run    = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine()->advance($run, 1);
        return $contactId;
    }

    // ── CRM action executors ───────────────────────────────────────────

    public function testCreateTaskAction(): void
    {
        $cid = $this->runAction('+919990000010', 'create_task', ['title' => 'Onboard customer', 'priority' => 'high', 'due_in_days' => 2]);

        $task = db_connect()->table('tasks')->where('related_type', 'contact')->where('related_id', $cid)->get()->getRowArray();
        $this->assertNotNull($task, 'create_task must open a task on the contact');
        $this->assertSame('Onboard customer', $task['title']);
        $this->assertSame('high', $task['priority']);
        $this->assertNotNull($task['due_at']);
    }

    public function testUpdateFieldAction(): void
    {
        $cid = $this->runAction('+919990000011', 'update_field', ['field' => 'lifecycle_stage', 'value' => 'customer']);

        $contact = db_connect()->table('contacts')->where('id', $cid)->get()->getRowArray();
        $this->assertSame('customer', $contact['lifecycle_stage']);
    }

    public function testUpdateFieldRejectsUnknownField(): void
    {
        // A non-whitelisted field must be ignored (no SQL injection vector, no write).
        $cid = $this->runAction('+919990000012', 'update_field', ['field' => 'wa_number', 'value' => 'hacked']);

        $contact = db_connect()->table('contacts')->where('id', $cid)->get()->getRowArray();
        $this->assertSame('+919990000012', $contact['wa_number'], 'wa_number must be untouched');
    }

    public function testCreateDealAction(): void
    {
        $cid = $this->runAction('+919990000013', 'create_deal', ['title' => 'Upsell deal', 'value_amount' => 500000]);

        $deal = db_connect()->table('deals')->where('primary_contact_id', $cid)->get()->getRowArray();
        $this->assertNotNull($deal, 'create_deal must open a deal for the contact');
        $this->assertSame('Upsell deal', $deal['title']);
        $this->assertSame(500000, (int) $deal['value_amount']);
        // Placed in the auto-provisioned default pipeline's first stage.
        $this->assertGreaterThan(0, (int) $deal['stage_id']);
    }

    public function testDryRunDoesNotMutate(): void
    {
        $contactId = $this->seedContact('+919990000014');
        $graph = $this->makeGraph(
            [['id' => 't', 'type' => 'lead_created', 'data' => []], ['id' => 'a', 'type' => 'create_task', 'data' => ['title' => 'Should not exist']]],
            [['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next']]
        );
        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'a');
        $run    = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $run['is_test'] = 1; // dry-run
        $this->engine()->advance($run, 1);

        $this->assertSame(0, db_connect()->table('tasks')->countAllResults(), 'flow TEST must not create real tasks');
    }

    // ── Deal triggers fire flows ───────────────────────────────────────

    public function testDealWonFiresMatchingFlow(): void
    {
        $contactId = $this->seedContact('+919990000020');
        $this->seedFlow($this->makeGraph([['id' => 't', 'type' => 'deal_won', 'data' => []]], []), 'deal_won', ['status' => 'active']);

        $n = FlowTriggerService::fire('deal_won', 1, $contactId, ['deal_id' => 5]);
        $this->assertSame(1, $n, 'deal_won must enqueue the active flow');
        $this->assertSame(1, db_connect()->table('jobs')->where('type', 'flow_start')->countAllResults());
    }

    public function testDealStageChangedMatchesConfiguredStage(): void
    {
        $contactId = $this->seedContact('+919990000021');
        // Flow only wants deals that move to stage 7.
        $this->seedFlow(
            $this->makeGraph([['id' => 't', 'type' => 'deal_stage_changed', 'data' => []]], []),
            'deal_stage_changed',
            ['status' => 'active', 'trigger_config' => json_encode(['stage_id' => 7])]
        );

        $miss = FlowTriggerService::fire('deal_stage_changed', 1, $contactId, ['stage_id' => 3]);
        $this->assertSame(0, $miss, 'A different stage must not match');

        $hit = FlowTriggerService::fire('deal_stage_changed', 1, $contactId, ['stage_id' => 7]);
        $this->assertSame(1, $hit, 'The configured stage must match');
    }
}
