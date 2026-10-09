<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Services\Flow\JobDispatcher;

/**
 * Re-dispatches jobs for source rows that were committed to the DB but whose
 * corresponding job was never enqueued (the "insert-then-dispatch" gap).
 *
 * ── The gap ──────────────────────────────────────────────────────────────
 * Three code paths follow the same pattern:
 *   1. Write a source row (meta_lead_events, campaign, import) — auto-committed.
 *   2. Call JobDispatcher::dispatch() — separate DB INSERT.
 *
 * If step 2 throws (DB connection dropped, lock timeout, max_connections) the
 * source row is permanently stranded: the row is committed, but no job will
 * ever process it.  The caller already returned 200 / HTTP response, so there
 * is no user or Meta retry to rescue it.
 *
 * ── Match strategy: source_type + source_id (indexed, never JSON_EXTRACT) ─
 * JobDispatcher stores the owning row's table name and PK in jobs.source_type
 * and jobs.source_id (indexed together).  The sweeper LEFT JOINs on those two
 * columns — exact, version-independent, and uses the index — instead of parsing
 * jobs.payload JSON which is environment-fragile and unindexed.
 *
 * ── Safety invariants ────────────────────────────────────────────────────
 * 1. Only rows OLDER than ORPHAN_THRESHOLD are swept.  A freshly created row
 *    whose job is still being committed (slow writer) is never touched.
 * 2. Statuses 'pending' AND 'processing' are treated as "live" — a job being
 *    worked by a slow worker is not re-dispatched.
 * 3. PHP clock for the cutoff (consistent with JobDispatcher / WindowService).
 *    Never MySQL NOW().
 */
class OrphanedRowSweeper
{
    /** Seconds a source row must be stuck before the sweeper re-dispatches. */
    public const ORPHAN_THRESHOLD = 300;

    /**
     * Descriptor for each source-table/job-type pair.
     *   table        — the source table name (no DB prefix)
     *   stuck_status — the status that indicates the row is stuck
     *   job_type     — the job type to enqueue
     *   source_type  — the string stored in jobs.source_type (equals table name)
     *   payload_key  — the payload key the handler expects (e.g. 'meta_lead_event_id')
     */
    private const DESCRIPTORS = [
        [
            'table'        => 'meta_lead_events',
            'stuck_status' => 'queued',
            'job_type'     => 'meta_lead_fetch',
            'source_type'  => 'meta_lead_events',
            'payload_key'  => 'meta_lead_event_id',
        ],
        [
            'table'        => 'google_lead_events',
            'stuck_status' => 'queued',
            'job_type'     => 'google_lead_process',
            'source_type'  => 'google_lead_events',
            'payload_key'  => 'google_lead_event_id',
        ],
        [
            'table'        => 'campaigns',
            'stuck_status' => 'processing',
            'job_type'     => 'campaign_send',
            'source_type'  => 'campaigns',
            'payload_key'  => 'campaign_id',
        ],
        [
            'table'        => 'lead_imports',
            'stuck_status' => 'processing',
            'job_type'     => 'lead_import',
            'source_type'  => 'lead_imports',
            'payload_key'  => 'import_id',
        ],
    ];

    /**
     * Sweep every source table for orphaned rows.
     *
     * @param  int $now  Unix timestamp — PHP time(), consistent with JobDispatcher clock rule.
     * @return int       Total jobs re-dispatched across all descriptors.
     */
    public function sweep(int $now): int
    {
        $db     = db_connect();
        $p      = $db->DBPrefix;
        $cutoff = date('Y-m-d H:i:s', $now - self::ORPHAN_THRESHOLD);
        $total  = 0;

        foreach (self::DESCRIPTORS as $d) {
            // ── Indexed LEFT JOIN — no JSON_EXTRACT ──────────────────────
            // Find source rows in the stuck state, older than the threshold,
            // with NO live job (pending or processing) on the index columns.
            $sql = "SELECT s.id, s.tenant_id
                    FROM   {$p}{$d['table']} s
                    LEFT   JOIN {$p}jobs j
                           ON  j.source_type = ?
                           AND j.source_id   = s.id
                           AND j.status      IN ('pending', 'processing')
                    WHERE  s.status     = ?
                      AND  s.created_at < ?
                      AND  j.id        IS NULL";

            $orphans = $db->query($sql, [
                $d['source_type'],
                $d['stuck_status'],
                $cutoff,
            ])->getResultArray();

            foreach ($orphans as $row) {
                $tenantId = (int) ($row['tenant_id'] ?? 0);
                $sourceId = (int) ($row['id']        ?? 0);

                if ($tenantId <= 0 || $sourceId <= 0) {
                    continue;
                }

                try {
                    JobDispatcher::dispatch(
                        tenantId:   $tenantId,
                        type:       $d['job_type'],
                        payload:    [$d['payload_key'] => $sourceId],
                        sourceType: $d['source_type'],
                        sourceId:   $sourceId,
                    );
                    $total++;

                    log_message('warning', sprintf(
                        '[OrphanedRowSweeper] Re-dispatched %s for %s #%d (tenant %d) — '
                        . 'orphaned in status "%s" > %ds.',
                        $d['job_type'], $d['table'], $sourceId, $tenantId,
                        $d['stuck_status'], self::ORPHAN_THRESHOLD
                    ));
                } catch (\Throwable $e) {
                    log_message('error', sprintf(
                        '[OrphanedRowSweeper] Failed to re-dispatch %s for %s #%d: %s',
                        $d['job_type'], $d['table'], $sourceId, $e->getMessage()
                    ));
                }
            }
        }

        return $total;
    }

    /** Expose the descriptor count so tests can assert coverage without white-boxing. */
    public function descriptorCount(): int
    {
        return count(self::DESCRIPTORS);
    }
}
