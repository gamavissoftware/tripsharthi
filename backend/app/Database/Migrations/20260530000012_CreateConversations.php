<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateConversations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            // Nullable until the contact is matched/created
            'contact_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            // Which of our phone numbers this conversation lives on
            'phone_number_id'=> ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            // The contact's WhatsApp number (denormalised for fast lookup without joining contacts)
            'wa_number'      => ['type' => 'VARCHAR', 'constraint' => 20],
            'contact_name'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],

            // ─── THE SINGLE SOURCE OF TRUTH FOR THE 24-HOUR WINDOW ───────
            // Set to PHP date('Y-m-d H:i:s', time() + WINDOW_SECONDS) on every inbound.
            // NULL = no window has ever opened (never messaged in, or window not yet set).
            // Expired = past this value = window closed; free-form BLOCKED.
            'window_expires_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            // ─────────────────────────────────────────────────────────────

            'last_message_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'last_inbound_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'unread_count'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'           => [
                'type'       => 'ENUM',
                'constraint' => ['open', 'resolved'],
                'default'    => 'open',
            ],
            // Agent assigned to this conversation
            'assigned_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        // One thread per (tenant, contact's WA number) — simplest for v1
        $this->forge->addUniqueKey(['tenant_id', 'wa_number']);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey('window_expires_at'); // range queries for expired windows
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('contact_id', 'contacts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('phone_number_id', 'phone_numbers', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('assigned_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('conversations');
    }

    public function down(): void
    {
        $this->forge->dropTable('conversations', true);
    }
}
