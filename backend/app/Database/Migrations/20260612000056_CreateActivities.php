<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * activities — the unified, immutable timeline log for any CRM record.
 *
 * Polymorphic (related_type + related_id) so one stream powers a contact's,
 * account's, deal's or ticket's history. WhatsApp messages are mirrored here
 * (whatsapp_in/out) so the record page shows the full thread inline, alongside
 * notes, calls, stage changes, and system events. Append-only (no soft delete).
 */
class CreateActivities extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'type'          => [
                'type'       => 'ENUM',
                'constraint' => [
                    'note', 'call', 'meeting', 'whatsapp_in', 'whatsapp_out', 'email',
                    'task_done', 'stage_change', 'field_change', 'deal_won', 'deal_lost',
                    'lifecycle_change', 'system',
                ],
                'default'    => 'system',
            ],
            'subject'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'body'          => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'related_type'  => ['type' => 'VARCHAR', 'constraint' => 40],  // contact|account|deal|ticket
            'related_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'actor_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'meta'          => ['type' => 'JSON', 'null' => true, 'default' => null],
            'occurred_at'   => ['type' => 'DATETIME', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'related_type', 'related_id', 'occurred_at']);
        $this->forge->addKey(['tenant_id', 'type']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('activities');
    }

    public function down(): void
    {
        $this->forge->dropTable('activities', true);
    }
}
