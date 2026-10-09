<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\FlowRunModel;
use App\Services\Flow\FlowEngine;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\WindowService;
use App\Models\ConversationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * Tests that FlowEngine enforces WhatsApp window policy (CLAUDE.md §1).
 */
class FlowWindowGateTest extends CIUnitTestCase
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

    private function engine(bool $windowOpen): FlowEngine
    {
        // Seed a conversation with the right window state
        $expiry = $windowOpen
            ? date('Y-m-d H:i:s', $this->now + 86400)
            : null;

        return new FlowEngine(
            new CloudApiClient('mock_phone', 'mock_token'),
            new WindowService(new ConversationModel(), $this->now),
            $this->now
        );
    }

    private function seedConversation(string $waNumber, bool $windowOpen): int
    {
        $expiry = $windowOpen ? date('Y-m-d H:i:s', $this->now + 86400) : null;
        db_connect()->table('conversations')->insert([
            'tenant_id'        => 1,
            'wa_number'        => $waNumber,
            'window_expires_at'=> $expiry,
            'unread_count'     => 0,
            'status'           => 'open',
            'created_at'       => date('Y-m-d H:i:s', $this->now),
            'updated_at'       => date('Y-m-d H:i:s', $this->now),
        ]);
        return (int) db_connect()->insertID();
    }

    // ------------------------------------------------------------------
    // send_freeform — closed window → stopped, message NOT sent
    // ------------------------------------------------------------------

    public function testSendFreeformWindowClosedStopsRun(): void
    {
        $wa = '+919991000001';
        $contactId = $this->seedContact($wa);
        $this->seedConversation($wa, false); // CLOSED

        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created', 'data' => []],
                ['id' => 'f', 'type' => 'send_freeform', 'data' => ['content' => 'Hello!']],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'f', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'f');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine(false)->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('stopped', $run['status'], 'Run must be stopped when window is closed');

        // Message logged as blocked (not sent)
        $blocked = db_connect()->table('messages')
            ->where('status', 'failed')
            ->where('contact_id', $contactId)
            ->get()->getResultArray();
        $this->assertNotEmpty($blocked, 'Blocked attempt must be logged');

        $sentMsgs = db_connect()->table('messages')
            ->where('status', 'sent')->where('contact_id', $contactId)->countAllResults();
        $this->assertSame(0, $sentMsgs, 'NO message must be sent when window is closed');

        // Log entry: blocked_window
        $log = db_connect()->table('flow_run_logs')
            ->where('flow_run_id', $runId)->where('result', 'blocked_window')
            ->get()->getRowArray();
        $this->assertNotNull($log, 'flow_run_logs must have a blocked_window entry');
    }

    // ------------------------------------------------------------------
    // send_freeform — closed window + window_closed edge → routes to fallback
    // ------------------------------------------------------------------

    public function testSendFreeformWindowClosedWithFallbackEdgeRoutes(): void
    {
        $wa = '+919991000002';
        $contactId = $this->seedContact($wa);
        $this->seedConversation($wa, false); // CLOSED

        $templateId = $this->seedApprovedTemplate(['category' => 'utility']);

        $graph = $this->makeGraph(
            [
                ['id' => 't',   'type' => 'lead_created',  'data' => []],
                ['id' => 'f',   'type' => 'send_freeform', 'data' => ['content' => 'Hi!']],
                ['id' => 'tpl', 'type' => 'send_template', 'data' => ['template_id' => $templateId]],
            ],
            [
                ['id' => 'e1', 'source' => 't',  'target' => 'f',   'sourceHandle' => 'next'],
                // window_closed fallback edge from the freeform node
                ['id' => 'e2', 'source' => 'f',  'target' => 'tpl', 'sourceHandle' => 'window_closed'],
            ]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'f');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine(false)->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        // Run must complete (fallback template sent), NOT stopped
        $this->assertSame('completed', $run['status'],
            'Run must complete via the fallback template, not stop');

        // The template must have been sent
        $sentTemplate = db_connect()->table('messages')
            ->where('type', 'template')->where('status', 'sent')
            ->where('contact_id', $contactId)->countAllResults();
        $this->assertGreaterThan(0, $sentTemplate, 'Fallback template must be sent');
    }

    // ------------------------------------------------------------------
    // send_freeform — open window → message sent, run advances
    // ------------------------------------------------------------------

    public function testSendFreeformWindowOpenSendsAndAdvances(): void
    {
        $wa = '+919991000003';
        $contactId = $this->seedContact($wa);
        $this->seedConversation($wa, true); // OPEN

        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created',  'data' => []],
                ['id' => 'f', 'type' => 'send_freeform', 'data' => ['content' => 'Hello!']],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'f', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'f');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine(true)->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('completed', $run['status']);

        $msg = db_connect()->table('messages')
            ->where('status', 'sent')->where('contact_id', $contactId)->get()->getRowArray();
        $this->assertNotNull($msg);
        $this->assertSame('free_form', $msg['category']);
        $this->assertSame('0', (string)$msg['billable'], 'Free-form is never billable');
    }

    // ------------------------------------------------------------------
    // node data shapes — seeded graphs carry real arrays, not JSON strings
    // ------------------------------------------------------------------

    /**
     * The builder writes variable_mapping/variable_defaults as JSON strings, but
     * seeded and imported graphs carry them as arrays. json_decode() on an array
     * is a TypeError, which killed the run outright — the shipped, ACTIVE
     * "Meta Lead Ads — Welcome Flow" failed on every lead for this reason.
     */
    public function testSendTemplateAcceptsArrayVariableDataFromSeededGraphs(): void
    {
        $wa        = '+919991000009';
        $contactId = $this->seedContact($wa);
        $this->seedConversation($wa, false);

        $templateId = $this->seedApprovedTemplate(['category' => 'utility']);

        $graph = $this->makeGraph(
            [
                ['id' => 't',   'type' => 'lead_created', 'data' => []],
                ['id' => 'tpl', 'type' => 'send_template', 'data' => [
                    'template_id'       => $templateId,
                    // arrays, exactly as the seeder writes them
                    'variable_map'      => [],
                    'variable_defaults' => [],
                ]],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'tpl', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'tpl');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine(false)->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('completed', $run['status'],
            'array-shaped node data must not crash the run');

        $sent = db_connect()->table('messages')
            ->where('type', 'template')->where('contact_id', $contactId)->countAllResults();
        $this->assertGreaterThan(0, $sent, 'the template must still be sent');
    }

    // ------------------------------------------------------------------
    // send_template — not gated by window, always sends
    // ------------------------------------------------------------------

    public function testSendTemplateWindowClosedSendsAnyway(): void
    {
        $wa = '+919991000004';
        $contactId  = $this->seedContact($wa);
        $this->seedConversation($wa, false); // CLOSED — should not block template

        $templateId = $this->seedApprovedTemplate(['category' => 'utility']);

        $graph = $this->makeGraph(
            [
                ['id' => 't',   'type' => 'lead_created', 'data' => []],
                ['id' => 'tpl', 'type' => 'send_template', 'data' => ['template_id' => $templateId]],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'tpl', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'tpl');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine(false)->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('completed', $run['status'], 'Template send is never blocked by window');

        $msg = db_connect()->table('messages')
            ->where('type', 'template')->where('status', 'sent')
            ->where('contact_id', $contactId)->get()->getRowArray();
        $this->assertNotNull($msg);
        // Utility outside window = billable=1
        $this->assertSame('1', (string)$msg['billable']);
    }

    public function testSendTemplateUtilityWindowOpenIsFree(): void
    {
        $wa = '+919991000005';
        $contactId  = $this->seedContact($wa);
        $this->seedConversation($wa, true); // OPEN

        $templateId = $this->seedApprovedTemplate(['category' => 'utility']);

        $graph = $this->makeGraph(
            [
                ['id' => 't',   'type' => 'lead_created', 'data' => []],
                ['id' => 'tpl', 'type' => 'send_template', 'data' => ['template_id' => $templateId]],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'tpl', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'tpl');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine(true)->advance($run, 1);

        $msg = db_connect()->table('messages')
            ->where('type', 'template')->where('status', 'sent')
            ->where('contact_id', $contactId)->get()->getRowArray();
        $this->assertNotNull($msg);
        // Utility INSIDE window = billable=0 (per BillableComputer)
        $this->assertSame('0', (string)$msg['billable']);
    }

    // ------------------------------------------------------------------
    // Fix #3: marketing template + opt_in=false → skipped, run advances
    // ------------------------------------------------------------------

    public function testMarketingTemplateSkipsOptOutContact(): void
    {
        $wa = '+919991000006';
        $contactId  = $this->seedContact($wa, ['opt_in' => 0]); // OPT-OUT
        $this->seedConversation($wa, false);

        $templateId = $this->seedApprovedTemplate(['category' => 'marketing']);

        $graph = $this->makeGraph(
            [
                ['id' => 't',   'type' => 'lead_created', 'data' => []],
                ['id' => 'tpl', 'type' => 'send_template', 'data' => ['template_id' => $templateId]],
                ['id' => 'tag', 'type' => 'add_tag',       'data' => ['tag_id' => 50]],
            ],
            [
                ['id' => 'e1', 'source' => 't',   'target' => 'tpl', 'sourceHandle' => 'next'],
                ['id' => 'e2', 'source' => 'tpl', 'target' => 'tag', 'sourceHandle' => 'next'],
            ]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'tpl');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine(false)->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        // Run must COMPLETE (not stop) — skipping opt-out doesn't end the flow
        $this->assertSame('completed', $run['status'],
            'Opt-out skip must advance run, not stop it');

        $sent = db_connect()->table('messages')
            ->where('status', 'sent')->where('contact_id', $contactId)->countAllResults();
        $this->assertSame(0, $sent, 'Marketing template must NOT be sent to opt-out contact');

        // add_tag still ran (run continued after skip)
        $tagged = db_connect()->table('contact_tags')
            ->where('contact_id', $contactId)->where('tag_id', 50)->countAllResults();
        $this->assertSame(1, $tagged, 'Flow must continue after marketing opt-out skip');
    }

    // ------------------------------------------------------------------
    // Fix #4: no conversation = window treated as closed
    // ------------------------------------------------------------------

    public function testSendFreeformNoConversationTreatsWindowAsClosed(): void
    {
        $wa = '+919991000007';
        $contactId = $this->seedContact($wa);
        // DO NOT seed a conversation — fresh lead, never messaged

        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created',  'data' => []],
                ['id' => 'f', 'type' => 'send_freeform', 'data' => ['content' => 'Hi!']],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'f', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'f');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine(false)->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('stopped', $run['status'],
            'No conversation = window closed = run stopped');

        $sentMsgs = db_connect()->table('messages')
            ->where('status', 'sent')->where('contact_id', $contactId)->countAllResults();
        $this->assertSame(0, $sentMsgs);
    }
}
