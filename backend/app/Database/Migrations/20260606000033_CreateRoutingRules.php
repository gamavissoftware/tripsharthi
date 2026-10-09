<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Inbox auto-assignment rules (P1.5).
 *
 * On every inbound message to an UNASSIGNED conversation, AgentRouter evaluates
 * active rules in sort_order and assigns the first match to an agent:
 *   - match_type 'any'     → matches every conversation (catch-all)
 *   - match_type 'tag'     → contact carries tag id = match_value
 *   - match_type 'keyword' → message body contains match_value
 *
 * strategy:
 *   - 'specific'     → always assign to assigned_user_id
 *   - 'least_loaded' → assign to the agent with the fewest open conversations
 *                      (round-robin that self-balances, no pointer needed)
 */
class CreateRoutingRules extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'      => ['type' => 'VARCHAR', 'constraint' => 255],

            'match_type' => [
                'type'       => 'ENUM',
                'constraint' => ['any', 'tag', 'keyword'],
                'default'    => 'any',
            ],
            'match_value' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],

            'strategy' => [
                'type'       => 'ENUM',
                'constraint' => ['specific', 'least_loaded'],
                'default'    => 'least_loaded',
            ],
            'assigned_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],

            'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],

            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'is_active', 'sort_order']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('assigned_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('routing_rules');
    }

    public function down(): void
    {
        $this->forge->dropTable('routing_rules', true);
    }
}
