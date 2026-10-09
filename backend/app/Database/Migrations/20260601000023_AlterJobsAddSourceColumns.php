<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds source_type + source_id to the jobs table so the orphaned-row
 * sweeper can locate a live job for any source row via an indexed JOIN
 * rather than JSON_EXTRACT on payload.
 *
 * ── Why not JSON_EXTRACT? ────────────────────────────────────────────────
 * JSON_UNQUOTE / CAST comparisons are environment-fragile (MySQL version
 * differences), unindexed, and if the match silently fails the sweeper
 * double-dispatches the job — causing duplicate processing or double-billing.
 * source_type + source_id carry an explicit composite index so the match
 * is exact, version-independent, and uses the index in the LEFT JOIN.
 *
 * ── Who fills these columns? ─────────────────────────────────────────────
 * JobDispatcher::dispatch() accepts optional sourceType/sourceId params.
 * Callers that have a owning source row (meta_lead_events, campaigns,
 * lead_imports) pass them.  Flow / free-fire jobs (flow_start, flow_resume)
 * leave them NULL — they have no single owning source row.
 */
class AlterJobsAddSourceColumns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('jobs', [
            'source_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'default'    => null,
                'after'      => 'type',
            ],
            'source_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
                'default'  => null,
                'after'    => 'source_type',
            ],
        ]);

        // Composite index used by OrphanedRowSweeper's LEFT JOIN.
        // (source_type, source_id) covers both the equality predicates
        // in the join condition and the IS NULL check on j.id.
        $this->db->query(
            'CREATE INDEX idx_jobs_source ON jobs (source_type, source_id)'
        );
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX idx_jobs_source ON jobs');
        $this->forge->dropColumn('jobs', ['source_type', 'source_id']);
    }
}
