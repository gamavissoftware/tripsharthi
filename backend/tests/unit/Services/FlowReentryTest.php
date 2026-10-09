<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Flow\FlowStartHandler;
use App\Services\Flow\JobDispatcher;
use App\Services\WhatsApp\CloudApiClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * Tests reentry_policy enforcement in FlowStartHandler.
 */
class FlowReentryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FlowTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
        $this->createFlowSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_MOCK_MODE']);
        parent::tearDown();
    }

    private function handler(): FlowStartHandler
    {
        return new FlowStartHandler(
            new CloudApiClient('mock_phone', 'mock_token')
        );
    }

    private function makeSimpleGraph(): array
    {
        return $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created', 'data' => []],
                ['id' => 'a', 'type' => 'add_tag',      'data' => ['tag_id' => 1]],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next']]
        );
    }

    private function fakeJob(int $flowId, int $contactId): array
    {
        return [
            'id'        => 1,
            'tenant_id' => 1,
            'type'      => 'flow_start',
            'payload'   => json_encode(['flow_id' => $flowId, 'contact_id' => $contactId, 'context' => []]),
            'attempts'  => 0,
        ];
    }

    // ------------------------------------------------------------------
    // once policy: blocks second enrollment when active run exists
    // ------------------------------------------------------------------

    public function testOncePolicyBlocksReenrollmentWhenRunIsActive(): void
    {
        $contactId = $this->seedContact('+919992000001');
        $graph     = $this->makeSimpleGraph();
        $flowId    = $this->seedFlow($graph, 'lead_created', ['reentry_policy' => 'once']);

        // Seed an existing RUNNING run
        db_connect()->table('flow_runs')->insert([
            'tenant_id'       => 1,
            'flow_id'         => $flowId,
            'contact_id'      => $contactId,
            'current_node_id' => 'a',
            'status'          => 'running',
            'graph_snapshot'  => json_encode($graph),
            'steps_executed'  => 0,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        // Try to start a new run
        $this->handler()->handle($this->fakeJob($flowId, $contactId), 1);

        // Only 1 run (the pre-seeded one)
        $runCount = db_connect()->table('flow_runs')
            ->where('flow_id', $flowId)->where('contact_id', $contactId)->countAllResults();
        $this->assertSame(1, $runCount, 'once policy must block re-enrollment when a run is active');
    }

    public function testOncePolicyBlocksReenrollmentWhenRunIsWaiting(): void
    {
        $contactId = $this->seedContact('+919992000002');
        $graph     = $this->makeSimpleGraph();
        $flowId    = $this->seedFlow($graph, 'lead_created', ['reentry_policy' => 'once']);

        db_connect()->table('flow_runs')->insert([
            'tenant_id'       => 1,
            'flow_id'         => $flowId,
            'contact_id'      => $contactId,
            'current_node_id' => 'a',
            'status'          => 'waiting',    // parked on a delay
            'graph_snapshot'  => json_encode($graph),
            'steps_executed'  => 1,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->handler()->handle($this->fakeJob($flowId, $contactId), 1);

        $runCount = db_connect()->table('flow_runs')
            ->where('flow_id', $flowId)->where('contact_id', $contactId)->countAllResults();
        $this->assertSame(1, $runCount, 'once policy must also block when existing run is waiting');
    }

    // ------------------------------------------------------------------
    // once policy must not strand a run whose first advance() threw
    // ------------------------------------------------------------------

    /**
     * Attempt 1 of a flow_start job inserts the run and can then throw
     * mid-advance (Meta timeout, missing WABA). Attempt 2 used to hit the
     * reentry guard, return "already enrolled", and leave the run stuck in
     * 'running' forever — under reentry_policy=once the contact could never be
     * enrolled again, so the lead silently never received the flow.
     */
    public function testRetryResumesARunLeftRunningByAFailedFirstAdvance(): void
    {
        $contactId = $this->seedContact('+919992000101');
        $graph     = $this->makeSimpleGraph();
        $flowId    = $this->seedFlow($graph, 'lead_created', ['reentry_policy' => 'once']);

        db_connect()->table('flow_runs')->insert([
            'tenant_id'       => 1,
            'flow_id'         => $flowId,
            'contact_id'      => $contactId,
            'current_node_id' => 'a',
            'status'          => 'running',   // stranded: never advanced
            'graph_snapshot'  => json_encode($graph),
            'steps_executed'  => 0,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->handler()->handle($this->fakeJob($flowId, $contactId), 1);

        $run = db_connect()->table('flow_runs')
            ->where('flow_id', $flowId)->where('contact_id', $contactId)
            ->get()->getRowArray();

        $this->assertNotSame('running', $run['status'],
            'a stranded run must be resumed by the job retry, not left running forever');
        $this->assertGreaterThan(0, (int) $run['steps_executed'],
            'resuming must actually execute the pending node');
    }

    public function testRetryDoesNotDisturbARunParkedOnADelay(): void
    {
        $contactId = $this->seedContact('+919992000102');
        $graph     = $this->makeSimpleGraph();
        $flowId    = $this->seedFlow($graph, 'lead_created', ['reentry_policy' => 'once']);

        db_connect()->table('flow_runs')->insert([
            'tenant_id'       => 1,
            'flow_id'         => $flowId,
            'contact_id'      => $contactId,
            'current_node_id' => 'a',
            'status'          => 'waiting',   // parked, has its own scheduled resume
            'graph_snapshot'  => json_encode($graph),
            'steps_executed'  => 1,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->handler()->handle($this->fakeJob($flowId, $contactId), 1);

        $run = db_connect()->table('flow_runs')
            ->where('flow_id', $flowId)->where('contact_id', $contactId)
            ->get()->getRowArray();

        $this->assertSame('waiting', $run['status'],
            'a parked run owns its own resume — the start handler must not run it early');
        $this->assertSame(1, (int) $run['steps_executed']);
    }

    // ------------------------------------------------------------------
    // once policy: allows re-enrollment after completed run
    // ------------------------------------------------------------------

    public function testOncePolicyAllowsReenrollmentAfterCompletedRun(): void
    {
        $contactId = $this->seedContact('+919992000003');
        $graph     = $this->makeSimpleGraph();
        $flowId    = $this->seedFlow($graph, 'lead_created', ['reentry_policy' => 'once']);

        // Seed a COMPLETED run
        db_connect()->table('flow_runs')->insert([
            'tenant_id'       => 1,
            'flow_id'         => $flowId,
            'contact_id'      => $contactId,
            'current_node_id' => null,
            'status'          => 'completed',
            'graph_snapshot'  => json_encode($graph),
            'steps_executed'  => 2,
            'completed_at'    => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        // Start a new run — should be allowed
        $this->handler()->handle($this->fakeJob($flowId, $contactId), 1);

        $runCount = db_connect()->table('flow_runs')
            ->where('flow_id', $flowId)->where('contact_id', $contactId)->countAllResults();
        $this->assertSame(2, $runCount, 'A completed run must not block a fresh enrollment');
    }

    // ------------------------------------------------------------------
    // always policy: allows re-enrollment even when a run is active
    // ------------------------------------------------------------------

    public function testAlwaysPolicyAllowsReenrollmentWithActiveRun(): void
    {
        $contactId = $this->seedContact('+919992000004');
        $graph     = $this->makeSimpleGraph();
        $flowId    = $this->seedFlow($graph, 'lead_created', ['reentry_policy' => 'always']);

        // Seed an existing RUNNING run
        db_connect()->table('flow_runs')->insert([
            'tenant_id'       => 1,
            'flow_id'         => $flowId,
            'contact_id'      => $contactId,
            'current_node_id' => 'a',
            'status'          => 'running',
            'graph_snapshot'  => json_encode($graph),
            'steps_executed'  => 0,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        // Start a second run
        $this->handler()->handle($this->fakeJob($flowId, $contactId), 1);

        $runCount = db_connect()->table('flow_runs')
            ->where('flow_id', $flowId)->where('contact_id', $contactId)->countAllResults();
        $this->assertSame(2, $runCount, 'always policy must create a second run');
    }
}
