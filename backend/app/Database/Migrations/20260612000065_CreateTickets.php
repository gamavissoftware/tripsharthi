<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * tickets — support cases (Service CRM, Phase D). Often WhatsApp-originated;
 * links to a contact (and optionally the conversation it came from). sla_due_at
 * is computed from priority on create; resolved_at stamps the resolution.
 */
class CreateTickets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'subject'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'description'     => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'contact_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'account_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'status'          => ['type' => 'ENUM', 'constraint' => ['open', 'pending', 'resolved', 'closed'], 'default' => 'open'],
            'priority'        => ['type' => 'ENUM', 'constraint' => ['low', 'medium', 'high', 'urgent'], 'default' => 'medium'],
            'owner_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'source'          => ['type' => 'ENUM', 'constraint' => ['whatsapp', 'email', 'web', 'manual'], 'default' => 'manual'],
            'category'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'conversation_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'sla_due_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'resolved_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey(['tenant_id', 'owner_id']);
        $this->forge->addKey(['tenant_id', 'contact_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('contact_id', 'contacts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('owner_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('tickets');
    }

    public function down(): void
    {
        $this->forge->dropTable('tickets', true);
    }
}
