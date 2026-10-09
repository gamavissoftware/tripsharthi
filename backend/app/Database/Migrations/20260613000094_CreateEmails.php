<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase L: email as a CRM channel (v1 — outbound only). A logged record of every
 * email sent to a contact, the system of record behind the contact timeline.
 * Inbound / 2-way threading is deferred (documented) — `direction` is forward-
 * compatible so a future inbound sync can write 'in' rows.
 */
class CreateEmails extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'contact_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'deal_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'direction'  => ['type' => 'ENUM', 'constraint' => ['out', 'in'], 'default' => 'out'],
            'from_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'to_email'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'subject'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'body'       => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'status'     => ['type' => 'ENUM', 'constraint' => ['queued', 'sent', 'failed'], 'default' => 'queued'],
            'error'      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'sent_by'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'contact_id']);
        $this->forge->createTable('emails');
    }

    public function down(): void
    {
        $this->forge->dropTable('emails');
    }
}
