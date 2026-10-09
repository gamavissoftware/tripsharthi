<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase H1: per-tenant lead-scoring configuration — signal weights (JSON) and the
 * Hot/Warm tier thresholds. Absent → the service uses built-in defaults.
 */
class CreateLeadScoringRules extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'weights'        => ['type' => 'TEXT', 'null' => true],
            'hot_threshold'  => ['type' => 'INT', 'constraint' => 11, 'default' => 65],
            'warm_threshold' => ['type' => 'INT', 'constraint' => 11, 'default' => 40],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('lead_scoring_rules');
    }

    public function down(): void
    {
        $this->forge->dropTable('lead_scoring_rules', true);
    }
}
