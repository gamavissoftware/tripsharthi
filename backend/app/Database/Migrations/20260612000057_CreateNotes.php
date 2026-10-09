<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * notes — editable, pinnable annotations on any CRM record (polymorphic).
 * Distinct from activities (which are immutable log entries).
 */
class CreateNotes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'body'         => ['type' => 'TEXT'],
            'related_type' => ['type' => 'VARCHAR', 'constraint' => 40],  // contact|account|deal|ticket
            'related_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'is_pinned'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_by'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'related_type', 'related_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('notes');
    }

    public function down(): void
    {
        $this->forge->dropTable('notes', true);
    }
}
