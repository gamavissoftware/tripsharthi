<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Queue\OrphanedRowSweeper;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Traits\MetaLeadTestSchema;

/**
 * Tests for OrphanedRowSweeper — the "insert-then-dispatch gap" mitigation.
 *
 * All tests use an in-memory SQLite DB via MetaLeadTestSchema (which provides
 * the jobs + meta_lead_events tables).  We test exclusively on the
 * meta_lead_events descriptor; the campaign / lead_import descriptors follow
 * the same code path — the descriptor coverage assertion (test 5) proves all
 * three are wired.
 *
 * The five cases:
 *   1. Orphaned row (old, no job)           → job dispatched
 *   2. Fresh row (under threshold)          → NOT dispatched
 *   3. Old row WITH live 'pending' job      → NOT dispatched
 *   4. Old row WITH live 'processing' job   → NOT dispatched
 *   5. Old row WITH a job whose payload is  → NOT dispatched
 *      wrong/empty but source_id is correct
 *      (proves match is index-based, not JSON)
 */
class OrphanedRowSweeperTest extends CIUnitTestCase
{
    use MetaLeadTestSchema;

    private int   $integrationId;
    private int   $past;   // Unix ts older than ORPHAN_THRESHOLD
    private int   $now;    // Unix ts used as "now" for sweep()

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMetaLeadSchema();

        $this->now  = time();
        $this->past = $this->now - OrphanedRowSweeper::ORPHAN_THRESHOLD - 60; // 1 min over threshold

        // Seed a tenant (already done by createMetaLeadSchema → createFlowSchema)
        $this->integrationId = $this->seedIntegration(1, 'sweep_page');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function seedEvent(int $createdAtTs, string $status = 'queued'): int
    {
        db_connect()->table('meta_lead_events')->insert([
            'tenant_id'      => 1,
            'integration_id' => $this->integrationId,
            'leadgen_id'     => 'lgid_' . uniqid(),
            'status'         => $status,
            'created_at'     => date('Y-m-d H:i:s', $createdAtTs),
            'updated_at'     => date('Y-m-d H:i:s', $createdAtTs),
        ]);
        return (int) db_connect()->insertID();
    }

    /** Insert a job row with explicit source_type / source_id / status / payload. */
    private function seedJob(
        string $sourceType,
        int    $sourceId,
        string $status  = 'pending',
        string $payload = '{}',
    ): void {
        db_connect()->table('jobs')->insert([
            'tenant_id'   => 1,
            'type'        => 'meta_lead_fetch',
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'payload'     => $payload,
            'run_at'      => date('Y-m-d H:i:s'),
            'status'      => $status,
            'attempts'    => 0,
            'max_attempts'=> 5,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    private function jobCount(): int
    {
        return (int) db_connect()->table('jobs')->countAll();
    }

    // ------------------------------------------------------------------
    // Test 1: orphaned row (old, no job) → job dispatched
    // ------------------------------------------------------------------

    public function testDispatchesOrphanedEventWithNoJob(): void
    {
        $eventId = $this->seedEvent($this->past); // old enough, no job

        $swept = (new OrphanedRowSweeper())->sweep($this->now);

        $this->assertSame(1, $swept, 'Expected exactly one re-dispatch for the orphaned event.');
        $this->assertSame(1, $this->jobCount(), 'Expected one job row created.');

        $job = db_connect()->table('jobs')->get()->getRowArray();
        $this->assertSame('meta_lead_events', $job['source_type']);
        $this->assertSame((string) $eventId,  (string) $job['source_id']);
        $this->assertSame('meta_lead_fetch',  $job['type']);

        $payload = json_decode($job['payload'], true);
        $this->assertSame($eventId, $payload['meta_lead_event_id'],
            'Payload must carry the correct meta_lead_event_id for the handler.');
    }

    // ------------------------------------------------------------------
    // Test 2: fresh row (under threshold) → NOT dispatched
    // ------------------------------------------------------------------

    public function testIgnoresFreshEventUnderThreshold(): void
    {
        $fresh = $this->now - OrphanedRowSweeper::ORPHAN_THRESHOLD + 30; // 30s under threshold
        $this->seedEvent($fresh);

        $swept = (new OrphanedRowSweeper())->sweep($this->now);

        $this->assertSame(0, $swept, 'Fresh row should not be swept.');
        $this->assertSame(0, $this->jobCount());
    }

    // ------------------------------------------------------------------
    // Test 3: old row with live 'pending' job → NOT dispatched
    // ------------------------------------------------------------------

    public function testIgnoresEventWithLivePendingJob(): void
    {
        $eventId = $this->seedEvent($this->past);
        $this->seedJob('meta_lead_events', $eventId, 'pending');

        $swept = (new OrphanedRowSweeper())->sweep($this->now);

        $this->assertSame(0, $swept, 'Pending job covers the event — must not re-dispatch.');
        $this->assertSame(1, $this->jobCount(), 'Job count must not change.');
    }

    // ------------------------------------------------------------------
    // Test 4: old row with live 'processing' job → NOT dispatched
    // ------------------------------------------------------------------

    public function testIgnoresEventWithLiveProcessingJob(): void
    {
        $eventId = $this->seedEvent($this->past);
        $this->seedJob('meta_lead_events', $eventId, 'processing');

        $swept = (new OrphanedRowSweeper())->sweep($this->now);

        $this->assertSame(0, $swept, 'Processing job covers the event — must not re-dispatch.');
        $this->assertSame(1, $this->jobCount(), 'Job count must not change.');
    }

    // ------------------------------------------------------------------
    // Test 5: live job matched via source_id even when payload is wrong
    //         PROVES the match never relies on JSON payload parsing
    // ------------------------------------------------------------------

    public function testLiveJobMatchedBySourceIdRegardlessOfPayload(): void
    {
        $eventId = $this->seedEvent($this->past);

        // Intentionally empty / wrong payload — proves it is irrelevant
        $this->seedJob('meta_lead_events', $eventId, 'pending', '{}');

        $swept = (new OrphanedRowSweeper())->sweep($this->now);

        $this->assertSame(0, $swept,
            'Job with correct source_type/source_id must suppress re-dispatch '
            . 'even when its payload JSON is empty or wrong.');
        $this->assertSame(1, $this->jobCount(), 'No duplicate job should be created.');
    }

    // ------------------------------------------------------------------
    // Bonus: descriptor coverage — all three source tables are wired
    // ------------------------------------------------------------------

    public function testAllDescriptorsArePresentInSweeper(): void
    {
        // flow_runs is deliberately NOT swept. A sweep keyed only on
        // status='running' matches every run ever stranded, so enabling it
        // resurrected months-old runs and re-sent their messages. Recovering a
        // stranded run belongs to the job retry (FlowStartHandler), which is
        // bounded to the enrolment being retried.
        $this->assertSame(4, (new OrphanedRowSweeper())->descriptorCount(),
            'Sweeper must cover meta_lead_events, google_lead_events, campaigns, and lead_imports.');
    }
}
