<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Flow\FlowEngine;
use App\Services\WhatsApp\CloudApiClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * Tests both loop-guard mechanisms:
 *
 * GUARD 1 — per-advance LOOP_GUARD_MAX (100):
 *   Fires when a tight cycle (no delays) executes > 100 transitions in one advance().
 *   Throws; job queue retries.
 *
 * GUARD 2 — persistent MAX_STEPS_PER_RUN (1000):
 *   Fires when steps_executed exceeds 1000 across ALL advance() calls.
 *   Catches delay-spanning cycles (A→delay→B→delay→A) that reset GUARD 1 each resume.
 *   Marks run.status = failed permanently.
 *
 * Key test: a delay-spanning cycle with steps_executed pre-seeded near the cap
 * must be terminated by GUARD 2 (not GUARD 1 — transitions count is low).
 */
class FlowLoopGuardTest extends CIUnitTestCase
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
        return new FlowEngine(
            new CloudApiClient('mock_phone', 'mock_token'),
            null,
            $this->now
        );
    }

    // ------------------------------------------------------------------
    // GUARD 1: tight cycle (no delays) → throws (per-advance guard)
    // ------------------------------------------------------------------

    public function testTightCycleThrowsPerAdvanceGuard(): void
    {
        $contactId = $this->seedContact('+919993000001');
        // A → A (direct back-edge, no delay)
        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created', 'data' => []],
                ['id' => 'a', 'type' => 'add_tag',      'data' => ['tag_id' => 1]],
            ],
            [
                ['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next'],
                ['id' => 'e2', 'source' => 'a', 'target' => 'a', 'sourceHandle' => 'next'], // self-loop
            ]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'a');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/loop guard/i');

        $this->engine()->advance($run, 1);
    }

    // ------------------------------------------------------------------
    // GUARD 2: delay-spanning cycle → terminated by persistent cap, NOT guard 1
    // ------------------------------------------------------------------

    public function testDelaySpanningCycleTerminatedByPersistentCapNotPerAdvanceGuard(): void
    {
        $contactId = $this->seedContact('+919993000002');

        // Cycle: A → delay(0s) → A
        // Each resume executes: [A, delay] = 2 steps; transitions = 2 (well under LOOP_GUARD_MAX=100)
        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created', 'data' => []],
                ['id' => 'a', 'type' => 'add_tag',      'data' => ['tag_id' => 1]],
                ['id' => 'd', 'type' => 'delay',        'data' => ['value' => 0, 'unit' => 'seconds']],
            ],
            [
                ['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next'],
                ['id' => 'e2', 'source' => 'a', 'target' => 'd', 'sourceHandle' => 'next'],
                ['id' => 'e3', 'source' => 'd', 'target' => 'a', 'sourceHandle' => 'next'], // cycle back
            ]
        );

        $flowId = $this->seedFlow($graph);

        // Pre-seed steps_executed at MAX_STEPS_PER_RUN (simulating 500 prior cycle iterations)
        // Next advance will hit 1001 > 1000 and fire the persistent guard
        $runId = $this->seedRun($flowId, $contactId, $graph, 'a', [
            'status'         => 'waiting', // simulating a delayed resume
            'steps_executed' => FlowEngine::MAX_STEPS_PER_RUN,
        ]);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();

        // Must NOT throw (guard 1 doesn't fire — only a few transitions happen)
        // Must terminate via guard 2 (persistent cap)
        $this->engine()->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();

        // ASSERT: terminated by persistent cap
        $this->assertSame('failed', $run['status'],
            'Delay-spanning cycle must be terminated by the persistent MAX_STEPS_PER_RUN guard.');

        // ASSERT: NOT by per-advance guard (transitions would be far < 100)
        // We verify this by checking that steps_executed slightly exceeds the cap
        $this->assertGreaterThan(
            FlowEngine::MAX_STEPS_PER_RUN,
            (int)$run['steps_executed'],
            'steps_executed must exceed MAX_STEPS_PER_RUN'
        );

        // ASSERT: per-advance transitions were low (no RuntimeException was thrown)
        $log = db_connect()->table('flow_run_logs')
            ->where('flow_run_id', $runId)->where('result', 'failed')
            ->get()->getRowArray();
        $this->assertNotNull($log, 'flow_run_logs must have a failed entry for the guard');
        $this->assertStringContainsString('MAX_STEPS_PER_RUN', $log['detail'],
            'Log detail must mention MAX_STEPS_PER_RUN');
    }

    // ------------------------------------------------------------------
    // Non-cyclic flow: no guard fires
    // ------------------------------------------------------------------

    public function testNonCyclicFlowCompletesWithoutGuardFiring(): void
    {
        $contactId = $this->seedContact('+919993000003');
        $graph = $this->makeGraph(
            [
                ['id' => 't',  'type' => 'lead_created', 'data' => []],
                ['id' => 'a1', 'type' => 'add_tag',      'data' => ['tag_id' => 1]],
                ['id' => 'a2', 'type' => 'add_tag',      'data' => ['tag_id' => 2]],
                ['id' => 'a3', 'type' => 'add_tag',      'data' => ['tag_id' => 3]],
            ],
            [
                ['id' => 'e1', 'source' => 't',  'target' => 'a1', 'sourceHandle' => 'next'],
                ['id' => 'e2', 'source' => 'a1', 'target' => 'a2', 'sourceHandle' => 'next'],
                ['id' => 'e3', 'source' => 'a2', 'target' => 'a3', 'sourceHandle' => 'next'],
            ]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'a1');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine()->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('completed', $run['status'], 'Non-cyclic flow must complete normally');
        $this->assertSame(3, (int)$run['steps_executed']);
    }

    // ------------------------------------------------------------------
    // Verify constants are sensible
    // ------------------------------------------------------------------

    public function testGuardConstantsAreWellDefined(): void
    {
        $this->assertSame(100,  FlowEngine::LOOP_GUARD_MAX);
        $this->assertSame(1000, FlowEngine::MAX_STEPS_PER_RUN);
        $this->assertGreaterThan(
            FlowEngine::LOOP_GUARD_MAX,
            FlowEngine::MAX_STEPS_PER_RUN,
            'MAX_STEPS_PER_RUN must be larger than LOOP_GUARD_MAX'
        );
    }
}
