<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Durable job queue — the execution engine for Sprint 4 flows
 * and the port target for Sprint 1 lead imports + Sprint 3 campaign sends.
 *
 * Clock rule: run_at is PHP-computed UTC (same single clock as WindowService).
 * The hot worker query is: WHERE status='pending' AND run_at <= ?
 *   → covered by the composite index (status, run_at).
 */
class CreateJobs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            // 'flow_start' | 'flow_resume' | 'campaign_send' | 'lead_import'
            'type'        => ['type' => 'VARCHAR', 'constraint' => 50],
            'payload'     => ['type' => 'JSON', 'null' => true, 'default' => null],
            // PHP-computed UTC datetime — never MySQL NOW()
            'run_at'      => ['type' => 'DATETIME'],
            'status'      => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'processing', 'done', 'failed'],
                'default'    => 'pending',
            ],
            'attempts'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'max_attempts' => ['type' => 'INT', 'unsigned' => true, 'default' => 5],
            // Set when a worker claims the job; cleared on reschedule/done/failed
            'locked_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'locked_by'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'last_error'  => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        // Worker hot-path index: WHERE status='pending' AND run_at <= ?
        $this->forge->addKey(['status', 'run_at']);
        // Claimed-rows lookup: WHERE locked_by = ? AND status = 'processing'
        $this->forge->addKey('locked_by');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('jobs');
    }

    public function down(): void
    {
        $this->forge->dropTable('jobs', true);
    }
}
