<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * pipelines — configurable sales (or support) workflows. A tenant can have many
 * pipelines (e.g. "New Sales", "Renewals"); one is the default.
 */
class CreatePipelines extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 120],
            'entity_type' => ['type' => 'ENUM', 'constraint' => ['deal', 'ticket'], 'default' => 'deal'],
            'is_default'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'position'    => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'entity_type']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('pipelines');
    }

    public function down(): void
    {
        $this->forge->dropTable('pipelines', true);
    }
}
