<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Immutable execution audit log — one row per node executed.
 * No soft-delete; no tenant scope column (access through flow_runs).
 */
class CreateFlowRunLogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'flow_run_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'node_id'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'node_type'   => ['type' => 'VARCHAR', 'constraint' => 50],
            // executed / sent / delayed / branched_true / branched_false /
            // branched_open / branched_closed / blocked_window /
            // skipped_opt_out / stopped / failed / loop_guard
            'result'      => ['type' => 'VARCHAR', 'constraint' => 50],
            'detail'      => ['type' => 'TEXT',    'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('flow_run_id');
        $this->forge->addForeignKey('flow_run_id', 'flow_runs', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('flow_run_logs');
    }

    public function down(): void
    {
        $this->forge->dropTable('flow_run_logs', true);
    }
}
