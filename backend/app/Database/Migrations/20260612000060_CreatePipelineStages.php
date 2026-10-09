<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * pipeline_stages — the ordered columns of a pipeline. probability drives
 * weighted forecast; is_won / is_lost mark terminal stages.
 */
class CreatePipelineStages extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'pipeline_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 120],
            'position'    => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'probability' => ['type' => 'INT', 'constraint' => 11, 'default' => 0], // 0-100
            'is_won'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_lost'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'pipeline_id', 'position']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('pipeline_id', 'pipelines', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('pipeline_stages');
    }

    public function down(): void
    {
        $this->forge->dropTable('pipeline_stages', true);
    }
}
