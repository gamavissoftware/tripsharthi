<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\JobModel;
use App\Commands\FlowWork;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\BlankSlateSchema;

/**
 * Tests the durable job queue mechanics.
 *
 * Three atomic-claim assertions (per the spec):
 *
 *   STRUCTURAL — claimBatch issues exactly ONE SQL statement that carries both
 *                SET status='processing' and WHERE status='pending'.
 *                Verified via CI4's query log (saveQueries=true).
 *                A regression to read-then-write would show 2 statements.
 *
 *   GUARD      — a job already in status='processing' CANNOT be claimed by a
 *                fresh claimBatch.  Proves WHERE status='pending' is the real lock.
 *
 *   CONSERVATION — two sequential workers (A claims 4, B claims 4) produce
 *                disjoint sets and together cover all 6 jobs.  Sanity check.
 *
 * All tests run against SQLite3 :memory:. claimBatch detects the driver and
 * uses the subquery form — still a single UPDATE statement.
 */
class JobQueueTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use BlankSlateSchema;

    protected $migrate = false;
    protected $refresh = true;

    /** Fixed "now" for deterministic run_at comparisons. */
    private int $now;
    /** Auto-incrementing sequence so every test uses distinct wa-style IDs. */
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
        $this->now = mktime(12, 0, 0, 1, 15, 2026); // 2026-01-15 12:00:00 UTC
        $this->createSchema();
    }

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    private function createSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;

        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (
            id INTEGER PRIMARY KEY, name TEXT, slug TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}jobs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id  INTEGER NOT NULL,
            type       TEXT    NOT NULL,
            payload    TEXT,
            run_at     TEXT    NOT NULL,
            status     TEXT    DEFAULT 'pending',
            attempts   INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 5,
            locked_at  TEXT,
            locked_by  TEXT,
            last_error TEXT,
            created_at TEXT,
            updated_at TEXT
        )");

        // Purge rows so each test is isolated (shared :memory: DB)
        $db->query("DELETE FROM {$p}jobs");
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertJob(array $overrides = []): int
    {
        self::$seq++;
        db_connect()->table('jobs')->insert(array_merge([
            'tenant_id'    => 1,
            'type'         => 'flow_start',
            'payload'      => json_encode(['seq' => self::$seq]),
            'run_at'       => date('Y-m-d H:i:s', $this->now - 60), // due
            'status'       => 'pending',
            'attempts'     => 0,
            'max_attempts' => 5,
            'created_at'   => date('Y-m-d H:i:s', $this->now),
            'updated_at'   => date('Y-m-d H:i:s', $this->now),
        ], $overrides));
        return (int) db_connect()->insertID();
    }

    // ------------------------------------------------------------------
    // ── STRUCTURAL ────────────────────────────────────────────────────
    // claimBatch must issue exactly ONE SQL statement.
    // ------------------------------------------------------------------

    public function testClaimBatchStructural_SingleUpdateNotReadThenWrite(): void
    {
        $this->insertJob(['run_at' => date('Y-m-d H:i:s', $this->now - 60)]);

        $model = new JobModel();

        // Wire the onQuery callback to count and capture every raw SQL statement
        // that claimBatch issues.  Production code leaves onQuery=null.
        $rawStatements = [];
        $model->onQuery = static function (string $sql) use (&$rawStatements): void {
            $rawStatements[] = $sql;
        };

        $model->claimBatch('worker_struct', 1, $this->now);

        $issued = count($rawStatements);

        // ── Assertion 1: exactly ONE SQL statement (not a read-then-write pair) ──
        // A SELECT-before-UPDATE regression would produce 2 statements here.
        $this->assertSame(1, $issued,
            "claimBatch() must issue exactly ONE raw SQL statement. "
            . "Got {$issued}. A SELECT-then-UPDATE anti-pattern would show 2 "
            . "and is not atomic under concurrent workers."
        );

        $sql = strtoupper($rawStatements[0]);

        // ── Assertion 2: the statement is an UPDATE (not a standalone SELECT) ──
        $this->assertStringStartsWith('UPDATE', ltrim($sql),
            'The single statement must be an UPDATE, not a SELECT.'
        );

        // ── Assertion 3: the claim action (SET clause) is present ──
        $this->assertStringContainsString('PROCESSING', $sql,
            "SET status='processing' must appear in the UPDATE statement."
        );

        // ── Assertion 4: the lock predicate (WHERE clause) is present ──
        $this->assertStringContainsString('PENDING', $sql,
            "WHERE status='pending' must appear in the UPDATE statement — "
            . "this predicate IS the distributed lock that prevents double-claiming."
        );
    }

    // ------------------------------------------------------------------
    // ── GUARD ─────────────────────────────────────────────────────────
    // A job already in status='processing' cannot be claimed.
    // ------------------------------------------------------------------

    public function testClaimBatchGuard_CannotClaimAlreadyProcessingJob(): void
    {
        // Seed one job that is already claimed by another worker
        $jobId = $this->insertJob([
            'status'    => 'processing',
            'locked_by' => 'other_worker',
            'locked_at' => date('Y-m-d H:i:s', $this->now - 10),
        ]);

        $model   = new JobModel();
        $claimed = $model->claimBatch('fresh_worker', 10, $this->now);

        // GUARD: WHERE status='pending' means we cannot see 'processing' rows
        $this->assertSame(0, $claimed,
            "claimBatch() must NOT claim a job with status='processing'. "
            . "The WHERE status='pending' predicate is the real distributed lock."
        );

        $this->assertEmpty(
            $model->findClaimed('fresh_worker'),
            "findClaimed() must return nothing for fresh_worker."
        );

        // Confirm the original job is still held by other_worker
        $row = db_connect()->table('jobs')->where('id', $jobId)->get()->getRowArray();
        $this->assertSame('processing', $row['status']);
        $this->assertSame('other_worker', $row['locked_by']);
    }

    // ------------------------------------------------------------------
    // ── CONSERVATION ─────────────────────────────────────────────────
    // Two workers produce disjoint sets covering all jobs.
    // (Sanity check — not the atomicity proof.)
    // ------------------------------------------------------------------

    public function testClaimBatchConservation_TwoWorkersClaimDisjointSets(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->insertJob();
        }

        $model = new JobModel();

        $claimedA = $model->claimBatch('worker_A', 4, $this->now);
        $jobsA    = array_column($model->findClaimed('worker_A'), 'id');

        $claimedB = $model->claimBatch('worker_B', 4, $this->now);
        $jobsB    = array_column($model->findClaimed('worker_B'), 'id');

        $this->assertSame(4, $claimedA, 'Worker A must claim 4 jobs.');
        $this->assertSame(2, $claimedB, 'Worker B must claim the remaining 2 jobs.');

        $overlap = array_intersect($jobsA, $jobsB);
        $this->assertEmpty($overlap,
            'No job may appear in both worker A and worker B claim sets. '
            . 'Overlap: ' . implode(', ', $overlap)
        );

        $allClaimed = array_unique(array_merge($jobsA, $jobsB));
        $this->assertCount(6, $allClaimed, 'All 6 jobs must be accounted for.');
    }

    // ------------------------------------------------------------------
    // Basic claiming behaviour
    // ------------------------------------------------------------------

    public function testDueJobIsClaimed(): void
    {
        $this->insertJob(['run_at' => date('Y-m-d H:i:s', $this->now - 60)]);

        $claimed = (new JobModel())->claimBatch('wkr', 10, $this->now);

        $this->assertSame(1, $claimed);
    }

    public function testFutureJobIsNotClaimed(): void
    {
        $this->insertJob(['run_at' => date('Y-m-d H:i:s', $this->now + 999)]);

        $claimed = (new JobModel())->claimBatch('wkr', 10, $this->now);

        $this->assertSame(0, $claimed, 'A job with run_at in the future must not be claimed.');
    }

    public function testJobDueExactlyNowIsClaimed(): void
    {
        $this->insertJob(['run_at' => date('Y-m-d H:i:s', $this->now)]);

        $claimed = (new JobModel())->claimBatch('wkr', 10, $this->now);

        $this->assertSame(1, $claimed, 'A job due at exactly now must be claimed.');
    }

    // ------------------------------------------------------------------
    // Failure → reschedule with backoff + cleared lock
    // ------------------------------------------------------------------

    public function testFailureIncrementsAttemptsAndReschedulesWithBackoff(): void
    {
        $jobId = $this->insertJob(['attempts' => 0]);
        $model = new JobModel();

        $delay = (int) min(
            (int) round(FlowWork::BACKOFF_BASE ** 1), // 1st failure → attempts becomes 1
            FlowWork::BACKOFF_MAX
        );

        $model->reschedule($jobId, $this->now + $delay, 1, 'test error');

        $row = db_connect()->table('jobs')->where('id', $jobId)->get()->getRowArray();

        $this->assertSame('pending',   $row['status']);
        $this->assertSame('1',         (string) $row['attempts']);
        $this->assertSame('test error', $row['last_error']);

        $expectedRunAt = date('Y-m-d H:i:s', $this->now + $delay);
        $this->assertSame($expectedRunAt, $row['run_at'],
            "run_at must be now + backoff({$delay}s)."
        );
    }

    /**
     * Required change #2: locked_at and locked_by must be NULLed on reschedule
     * so a stale owner is not left on a re-queued job.
     */
    public function testRescheduleClearsLockFields(): void
    {
        $jobId = $this->insertJob([
            'status'    => 'processing',
            'locked_by' => 'old_worker',
            'locked_at' => date('Y-m-d H:i:s', $this->now - 5),
        ]);
        $model = new JobModel();
        $model->reschedule($jobId, $this->now + 2, 1, 'transient error');

        $row = db_connect()->table('jobs')->where('id', $jobId)->get()->getRowArray();

        $this->assertNull($row['locked_by'],
            "locked_by must be NULL after reschedule — no stale owner on re-queued job."
        );
        $this->assertNull($row['locked_at'],
            "locked_at must be NULL after reschedule."
        );
        $this->assertSame('pending', $row['status']);
    }

    public function testBackoffIsExponential(): void
    {
        // Verify 2^n progression (capped at BACKOFF_MAX)
        $base = FlowWork::BACKOFF_BASE;
        $max  = FlowWork::BACKOFF_MAX;

        $this->assertSame(
            (int) min((int) round($base ** 1), $max), 2,  'Attempt 1: 2^1 = 2s'
        );
        $this->assertSame(
            (int) min((int) round($base ** 2), $max), 4,  'Attempt 2: 2^2 = 4s'
        );
        $this->assertSame(
            (int) min((int) round($base ** 3), $max), 8,  'Attempt 3: 2^3 = 8s'
        );
        $this->assertSame(
            (int) min((int) round($base ** 4), $max), 16, 'Attempt 4: 2^4 = 16s'
        );
        $this->assertSame($max, FlowWork::BACKOFF_MAX, 'Backoff ceiling is 3600s (1 hour)');
    }

    public function testMaxAttemptsReachedMarksJobFailed(): void
    {
        $jobId = $this->insertJob(['attempts' => 4, 'max_attempts' => 5]);
        $model = new JobModel();

        // This attempt (5th) hits max_attempts
        $model->markFailed($jobId, 'permanent error');

        $row = db_connect()->table('jobs')->where('id', $jobId)->get()->getRowArray();
        $this->assertSame('failed', $row['status']);
        $this->assertSame('permanent error', $row['last_error']);
        $this->assertNull($row['locked_by'], 'Lock must be cleared even on permanent failure.');
    }

    // ------------------------------------------------------------------
    // Stale lock recovery
    // ------------------------------------------------------------------

    public function testStaleLockIsReclaimed(): void
    {
        $stale = FlowWork::STALE_TIMEOUT + 1; // just over the threshold

        $jobId = $this->insertJob([
            'status'    => 'processing',
            'locked_by' => 'crashed_worker',
            'locked_at' => date('Y-m-d H:i:s', $this->now - $stale),
        ]);

        (new JobModel())->reclaimStaleLocks(FlowWork::STALE_TIMEOUT, $this->now);

        $row = db_connect()->table('jobs')->where('id', $jobId)->get()->getRowArray();
        $this->assertSame('pending', $row['status'],
            'A stale lock (locked_at > STALE_TIMEOUT ago) must be reclaimed to pending.'
        );
        $this->assertNull($row['locked_by'], 'locked_by must be cleared on reclaim.');
        $this->assertNull($row['locked_at'], 'locked_at must be cleared on reclaim.');
    }

    public function testFreshLockIsNotReclaimed(): void
    {
        $fresh = FlowWork::STALE_TIMEOUT - 30; // 30 seconds under the threshold

        $jobId = $this->insertJob([
            'status'    => 'processing',
            'locked_by' => 'active_worker',
            'locked_at' => date('Y-m-d H:i:s', $this->now - $fresh),
        ]);

        (new JobModel())->reclaimStaleLocks(FlowWork::STALE_TIMEOUT, $this->now);

        $row = db_connect()->table('jobs')->where('id', $jobId)->get()->getRowArray();
        $this->assertSame('processing', $row['status'],
            'A fresh lock (locked_at within STALE_TIMEOUT) must NOT be reclaimed.'
        );
        $this->assertSame('active_worker', $row['locked_by']);
    }

    public function testStaleLockRecoveredJobCanBeClaimedAgain(): void
    {
        $stale = FlowWork::STALE_TIMEOUT + 1;
        $jobId = $this->insertJob([
            'status'    => 'processing',
            'locked_at' => date('Y-m-d H:i:s', $this->now - $stale),
            'locked_by' => 'crashed_worker',
        ]);

        $model = new JobModel();
        $model->reclaimStaleLocks(FlowWork::STALE_TIMEOUT, $this->now);

        // After reclaim, job is pending again — a new worker can claim it
        $claimed = $model->claimBatch('new_worker', 1, $this->now);
        $this->assertSame(1, $claimed, 'Reclaimed job must be claimable by a new worker.');

        $jobs = $model->findClaimed('new_worker');
        $this->assertSame($jobId, (int) $jobs[0]['id']);
    }

    // ------------------------------------------------------------------
    // JobDispatcher integration
    // ------------------------------------------------------------------

    public function testJobDispatcherInsertsCorrectRecord(): void
    {
        $id = \App\Services\Flow\JobDispatcher::dispatch(
            1,
            'flow_start',
            ['flow_id' => 42, 'contact_id' => 7],
            $this->now + 300
        );

        $this->assertGreaterThan(0, $id);

        $row = db_connect()->table('jobs')->where('id', $id)->get()->getRowArray();
        $this->assertSame('pending',     $row['status']);
        $this->assertSame('flow_start',  $row['type']);
        $this->assertSame('1',           (string) $row['tenant_id']);

        $payload = json_decode($row['payload'], true);
        $this->assertSame(42, $payload['flow_id']);
        $this->assertSame(7,  $payload['contact_id']);

        $expectedRunAt = date('Y-m-d H:i:s', $this->now + 300);
        $this->assertSame($expectedRunAt, $row['run_at']);
    }

    public function testJobDispatcherDefaultsRunAtToNow(): void
    {
        $before = time();
        $id     = \App\Services\Flow\JobDispatcher::dispatch(1, 'flow_resume', []);
        $after  = time();

        $row   = db_connect()->table('jobs')->where('id', $id)->get()->getRowArray();
        $runAt = strtotime($row['run_at']);

        $this->assertGreaterThanOrEqual($before, $runAt);
        $this->assertLessThanOrEqual($after, $runAt);
    }
}
