<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the segments table for saved/named audience segments.
 * Adds is_test flag to flow_runs for test-mode execution.
 */
class CreateSegmentsAndFlowTest extends Migration
{
    public function up(): void
    {
        // ── segments ──────────────────────────────────────────────────
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'unsigned' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'description' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'filters'     => ['type' => 'JSON', 'null' => false],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id'], false);
        $this->forge->createTable('segments', true);

        // ── flow_runs: test flag ───────────────────────────────────────
        $this->forge->addColumn('flow_runs', [
            'is_test' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'after'      => 'status',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('segments', true);
        $this->forge->dropColumn('flow_runs', ['is_test']);
    }
}
