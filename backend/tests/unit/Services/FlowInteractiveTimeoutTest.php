<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Flow\FlowEngine;
use App\Services\WhatsApp\CloudApiClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * Tests the interactive-reply TIMEOUT path: when a run parked on a
 * send_interactive node is resumed by the 24h timer (no tap arrived), it must
 * route to the node's 'fallback' edge — not re-execute the send node forever.
 */
class FlowInteractiveTimeoutTest extends CIUnitTestCase
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

    private function engine(): FlowEngine
    {
        return new FlowEngine(new CloudApiClient('mock_phone', 'mock_token'), null, $this->now);
    }

    /** A run waiting on a button node, resumed by the timeout (no tap). */
    private function waitingRun(array $graph): array
    {
        $contactId = $this->seedContact('+9199900' . random_int(10000, 99999));
        $flowId    = $this->seedFlow($graph);
        $runId     = $this->seedRun($flowId, $contactId, $graph, 'b', [
            'status' => 'waiting',
            'state'  => json_encode(['waiting_for' => 'interactive_reply', 'button_node_id' => 'b']),
        ]);
        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        return [$run, $runId, $contactId];
    }

    public function testTimeoutTakesFallbackEdge(): void
    {
        $graph = $this->makeGraph(
            [
                ['id' => 'b', 'type' => 'send_interactive', 'data' => ['interactive_type' => 'button', 'body' => 'Pick one', 'buttons' => [['id' => 'y', 'title' => 'Yes']]]],
                ['id' => 't', 'type' => 'add_tag',          'data' => ['tag_id' => 77]],
            ],
            [['id' => 'e_fb', 'source' => 'b', 'target' => 't', 'sourceHandle' => 'fallback']]
        );

        [$run, $runId, $contactId] = $this->waitingRun($graph);
        $this->engine()->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('completed', $run['status']);

        $tagged = db_connect()->table('contact_tags')
            ->where('contact_id', $contactId)->where('tag_id', 77)->countAllResults();
        $this->assertSame(1, $tagged, 'timeout must follow the fallback edge into add_tag');

        // The send node must NOT have re-executed (no new outbound interactive message).
        $sent = db_connect()->table('messages')
            ->where('contact_id', $contactId)->where('type', 'interactive')->countAllResults();
        $this->assertSame(0, $sent, 'timeout must not re-send the interactive message');
    }

    public function testTimeoutWithoutFallbackCompletes(): void
    {
        $graph = $this->makeGraph(
            [['id' => 'b', 'type' => 'send_interactive', 'data' => ['interactive_type' => 'button', 'body' => 'Pick one', 'buttons' => [['id' => 'y', 'title' => 'Yes']]]]],
            [] // no fallback edge
        );

        [$run, $runId, $contactId] = $this->waitingRun($graph);
        $this->engine()->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('completed', $run['status']);
        $state = json_decode($run['state'], true);
        $this->assertTrue($state['interactive_timed_out'] ?? false);
        $this->assertArrayNotHasKey('waiting_for', $state, 'waiting state must be cleared');
    }
}
