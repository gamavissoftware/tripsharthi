<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * tasks — actionable work items (calls, follow-ups, todos) with due dates,
 * reminders and an assignee. Optionally attached to a CRM record (polymorphic).
 * Distinct from activities (immutable log) and notes (annotations).
 */
class CreateTasks extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'title'            => ['type' => 'VARCHAR', 'constraint' => 255],
            'description'      => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'type'             => ['type' => 'ENUM', 'constraint' => ['call', 'whatsapp', 'email', 'meeting', 'todo'], 'default' => 'todo'],
            'status'           => ['type' => 'ENUM', 'constraint' => ['open', 'done'], 'default' => 'open'],
            'priority'         => ['type' => 'ENUM', 'constraint' => ['low', 'medium', 'high'], 'default' => 'medium'],
            'due_at'           => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'reminder_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'assigned_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'related_type'     => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null],
            'related_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_by'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'completed_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'assigned_user_id', 'status']);
        $this->forge->addKey(['tenant_id', 'status', 'due_at']);
        $this->forge->addKey(['tenant_id', 'related_type', 'related_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('assigned_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('tasks');
    }

    public function down(): void
    {
        $this->forge->dropTable('tasks', true);
    }
}
