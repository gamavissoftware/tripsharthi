<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateFlowRuns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'flow_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'contact_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'current_node_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['running', 'waiting', 'completed', 'stopped', 'failed'],
                'default'    => 'running',
            ],
            // Run-scoped variables: trigger payload, accumulator values
            'state'          => ['type' => 'JSON',     'null' => true, 'default' => null],
            // Immutable copy of flows.graph at enrollment — in-flight runs are immune to edits
            'graph_snapshot' => ['type' => 'JSON',     'null' => true, 'default' => null],
            // When a waiting run resumes (mirrors the flow_resume job's run_at)
            'next_run_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            // ── PERSISTENT LOOP GUARD ─────────────────────────────────────────
            // Total nodes executed across ALL advance() calls for this run.
            // Guards against delay-spanning cycles (A→delay→B→delay→A) that
            // reset the per-advance counter but keep accumulating steps.
            'steps_executed' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'entered_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'completed_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        // Reentry-check index: look up live runs before enrolling a contact
        $this->forge->addKey(['flow_id', 'contact_id']);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id',  'tenants',  'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('flow_id',    'flows',    'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('contact_id', 'contacts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('flow_runs');
    }

    public function down(): void
    {
        $this->forge->dropTable('flow_runs', true);
    }
}
