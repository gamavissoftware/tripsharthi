<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMessages extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'contact_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'conversation_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'direction'       => [
                'type'       => 'ENUM',
                'constraint' => ['in', 'out'],
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['text', 'template', 'image', 'document', 'audio', 'video'],
                'default'    => 'text',
            ],
            // Sprint 3: populated from template record for template sends.
            // free_form = outbound text/media inside an open window.
            // NULL until Sprint 3 wires this properly.
            'category' => [
                'type'       => 'ENUM',
                'constraint' => ['marketing', 'utility', 'authentication', 'service', 'free_form'],
                'null'       => true,
                'default'    => null,
            ],
            'body'          => ['type' => 'TEXT',    'null' => true, 'default' => null],
            'media_url'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'wa_message_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'status'        => [
                'type'       => 'ENUM',
                'constraint' => ['queued', 'sent', 'delivered', 'read', 'failed'],
                'default'    => 'queued',
            ],
            // 0 for free-form inside window.
            // Sprint 3: compute from category + window state (see comment in MessageModel).
            'billable'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'error'        => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'sent_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'delivered_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'read_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'conversation_id']);
        $this->forge->addKey('wa_message_id'); // status callback lookup
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('conversation_id', 'conversations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('contact_id', 'contacts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('messages');
    }

    public function down(): void
    {
        $this->forge->dropTable('messages', true);
    }
}
