<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Indexes the delivery report reads through.
 *
 * Two access patterns dominate the new reporting layer, and neither had an
 * index that covered it:
 *
 *  1. "What went out in this window, and how did it land" — the log, the
 *     funnel and the daily trend all scan (tenant_id, direction, created_at).
 *     Without this, every report full-scans `messages`, which on a live tenant
 *     is already six figures of rows.
 *
 *  2. "Did this contact write back afterwards" — the reply-attribution
 *     subquery (ReplyAttribution) probes (tenant_id, contact_id, direction,
 *     created_at) once per row returned. Unindexed, that is the difference
 *     between a report and a timeout.
 *
 * Both are plain secondary indexes, created INPLACE by MySQL 8, so this is
 * safe to run against the live table.
 */
class AddMessageReportIndexes extends Migration
{
    public function up(): void
    {
        $p = $this->db->DBPrefix;

        $this->createIndex("{$p}messages_report_idx", "{$p}messages", '(tenant_id, direction, created_at)');
        $this->createIndex("{$p}messages_reply_idx", "{$p}messages", '(tenant_id, contact_id, direction, created_at)');
    }

    public function down(): void
    {
        $p = $this->db->DBPrefix;

        foreach (["{$p}messages_report_idx", "{$p}messages_reply_idx"] as $name) {
            try {
                $this->db->query($this->db->DBDriver === 'SQLite3'
                    ? "DROP INDEX IF EXISTS {$name}"
                    : "DROP INDEX {$name} ON {$p}messages");
            } catch (\Throwable) {
                // Already gone — nothing to undo.
            }
        }
    }

    /**
     * MySQL has no CREATE INDEX IF NOT EXISTS, and a half-applied migration
     * (or an index an operator already added by hand on the live box) must not
     * block the rest of the batch.
     */
    private function createIndex(string $name, string $table, string $columns): void
    {
        $ifNotExists = $this->db->DBDriver === 'SQLite3' ? 'IF NOT EXISTS ' : '';

        try {
            $this->db->query("CREATE INDEX {$ifNotExists}{$name} ON {$table} {$columns}");
        } catch (\Throwable $e) {
            log_message('info', "AddMessageReportIndexes: skipped {$name} — " . $e->getMessage());
        }
    }
}
