<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Job queue model.
 *
 * Intentionally extends CI4's base Model (NOT BaseModel) because the
 * worker processes jobs across ALL tenants — no tenant scope needed.
 *
 * ── Atomic claim design ──────────────────────────────────────────────
 * claimBatch() issues a SINGLE UPDATE statement.  There is no SELECT
 * before the UPDATE.  InnoDB row-level locking during the UPDATE
 * prevents two concurrent workers from claiming the same row.
 *
 * MySQL form (production):
 *   UPDATE jobs SET status='processing', locked_at=?, locked_by=?
 *   WHERE status='pending' AND run_at <= ?
 *   ORDER BY run_at ASC LIMIT n
 *
 * SQLite form (test environment — no UPDATE…LIMIT support):
 *   UPDATE jobs SET status='processing', locked_at=?, locked_by=?
 *   WHERE id IN (SELECT id FROM jobs WHERE status='pending' AND run_at<=?
 *                ORDER BY run_at ASC LIMIT n)
 *
 * Both forms are a SINGLE statement. The SQLite variant is not
 * truly atomic under concurrent MySQL load; use MySQL in production.
 */
class JobModel extends Model
{
    protected $table      = 'jobs';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;

    protected $allowedFields = [
        'tenant_id', 'type', 'source_type', 'source_id', 'payload', 'run_at', 'status',
        'attempts', 'max_attempts', 'locked_at', 'locked_by', 'last_error',
    ];

    /**
     * Optional callback invoked for every raw db->query() call this model makes.
     * Inject in tests to count/inspect raw SQL.  Null in production (zero overhead).
     *
     *   $count = 0;
     *   $model->onQuery = function(string $sql) use (&$count) { $count++; };
     */
    public ?\Closure $onQuery = null;

    // ------------------------------------------------------------------
    // Atomic claim — SINGLE UPDATE statement
    // ------------------------------------------------------------------

    /**
     * Claim up to $limit pending due jobs for this worker instance.
     *
     * @param  string $workerId  Unique per-invocation worker ID.
     * @param  int    $limit     Max rows to claim.
     * @param  int    $now       Unix timestamp (PHP time()).
     * @return int    Number of rows actually claimed.
     */
    public function claimBatch(string $workerId, int $limit, int $now): int
    {
        $nowStr  = date('Y-m-d H:i:s', $now);
        $table   = $this->db->DBPrefix . $this->table; // e.g. 'db_jobs' in tests
        $isMySQL = str_contains(strtolower($this->db->DBDriver), 'mysql');

        if ($isMySQL) {
            // ── MySQL: single-statement UPDATE with ORDER BY + LIMIT ────
            // InnoDB acquires row locks during execution — truly atomic.
            $sql = "UPDATE {$table}
                    SET    status     = 'processing',
                           locked_at  = ?,
                           locked_by  = ?
                    WHERE  status    = 'pending'
                      AND  run_at   <= ?
                    ORDER BY run_at  ASC
                    LIMIT  {$limit}";

            $this->rawQuery($sql, [$nowStr, $workerId, $nowStr]);
        } else {
            // ── SQLite / other: subquery form ─────────────────────────
            // Still a SINGLE UPDATE statement (the subquery lives inside
            // the WHERE clause, not as a separate round-trip), so the
            // onQuery counter in the structural test fires exactly once.
            $sql = "UPDATE {$table}
                    SET    status     = 'processing',
                           locked_at  = ?,
                           locked_by  = ?
                    WHERE  id IN (
                               SELECT id
                               FROM   {$table}
                               WHERE  status  = 'pending'
                                 AND  run_at <= ?
                               ORDER BY run_at ASC
                               LIMIT  {$limit}
                           )";

            $this->rawQuery($sql, [$nowStr, $workerId, $nowStr]);
        }

        return $this->db->affectedRows();
    }

    /**
     * Return all rows currently held by this worker (SELECT after claim).
     */
    public function findClaimed(string $workerId): array
    {
        return $this->where('locked_by', $workerId)
                    ->where('status', 'processing')
                    ->orderBy('run_at', 'ASC')
                    ->findAll();
    }

    // ------------------------------------------------------------------
    // Stale lock recovery
    // ------------------------------------------------------------------

    /**
     * Reset jobs stuck in 'processing' by a crashed worker.
     * Called at the top of every worker invocation.
     *
     * @param int $staleSeconds  Age threshold in seconds.
     * @param int $now           Unix timestamp (PHP time()).
     */
    public function reclaimStaleLocks(int $staleSeconds, int $now): void
    {
        $cutoff = date('Y-m-d H:i:s', $now - $staleSeconds);
        $table  = $this->db->DBPrefix . $this->table;

        $this->rawQuery(
            "UPDATE {$table}
             SET    status     = 'pending',
                    locked_at  = NULL,
                    locked_by  = NULL
             WHERE  status     = 'processing'
               AND  locked_at  < ?",
            [$cutoff]
        );
    }

    // ------------------------------------------------------------------
    // Status transitions
    // ------------------------------------------------------------------

    public function markDone(int $jobId): void
    {
        $this->update($jobId, [
            'status'    => 'done',
            'locked_at' => null,
            'locked_by' => null,
        ]);
    }

    public function markFailed(int $jobId, string $error): void
    {
        $this->update($jobId, [
            'status'    => 'failed',
            'last_error'=> $error,
            'locked_at' => null,
            'locked_by' => null,
        ]);
    }

    /**
     * Reschedule a failed job for retry with exponential backoff.
     *
     * Clears locked_at + locked_by so no stale owner is left on
     * a re-queued job (required: plan change #2).
     */
    public function reschedule(int $jobId, int $runAt, int $attempts, string $error): void
    {
        $this->update($jobId, [
            'status'    => 'pending',
            'run_at'    => date('Y-m-d H:i:s', $runAt),
            'attempts'  => $attempts,
            'last_error'=> $error,
            'locked_at' => null,   // ← clear stale owner
            'locked_by' => null,   // ← clear stale owner
        ]);
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * Execute a raw query and invoke onQuery callback if set.
     * This is the single choke-point for all raw SQL, enabling the
     * structural atomicity test to count exactly how many statements
     * claimBatch and reclaimStaleLocks issue.
     */
    private function rawQuery(string $sql, array $bindings = []): void
    {
        if ($this->onQuery !== null) {
            ($this->onQuery)($sql);
        }
        $this->db->query($sql, $bindings);
    }
}
