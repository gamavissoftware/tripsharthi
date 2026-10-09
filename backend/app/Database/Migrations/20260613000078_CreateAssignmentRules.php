<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase H2: auto-assignment rules for deals and tickets — generalizes the inbox
 * routing pattern. A rule matches a record (any / field=value), then picks an
 * assignee by strategy (round-robin / least-loaded / specific) from an optional
 * pool, respecting a per-rep open-item capacity.
 */
class CreateAssignmentRules extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'entity_type'           => ['type' => 'VARCHAR', 'constraint' => 20], // deal | ticket
            'name'                  => ['type' => 'VARCHAR', 'constraint' => 255],
            'match_type'            => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'any'], // any | field
            'match_field'           => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'match_value'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'strategy'              => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'round_robin'],
            'assigned_user_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'pool'                  => ['type' => 'TEXT', 'null' => true], // JSON array of user ids; null = all members
            'capacity'              => ['type' => 'INT', 'constraint' => 11, 'default' => 50],
            'last_assigned_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'sort_order'            => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'is_active'             => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'            => ['type' => 'DATETIME', 'null' => true],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'            => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'entity_type', 'sort_order']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('assignment_rules');
    }

    public function down(): void
    {
        $this->forge->dropTable('assignment_rules', true);
    }
}
