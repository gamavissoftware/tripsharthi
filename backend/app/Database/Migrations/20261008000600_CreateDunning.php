<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Payment reminders (dunning) for booking instalments.
 *
 * payment_reminders is both the audit log and the idempotency guard:
 * UNIQUE(booking_payment_id, step) means a step is reserved BEFORE sending, so a
 * cron overlap or retry can never message a customer twice about the same dues.
 */
class CreateDunning extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'enabled'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'send_from_hour' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 9],
            'send_to_hour'   => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 20],
            'steps'     => ['type' => 'JSON', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('dunning_settings');

        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'booking_payment_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'booking_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'step'               => ['type' => 'VARCHAR', 'constraint' => 40],
            'channel'            => ['type' => 'ENUM', 'constraint' => ['whatsapp_text', 'whatsapp_template', 'task'], 'default' => 'whatsapp_template'],
            'status'             => ['type' => 'ENUM', 'constraint' => ['reserved', 'sent', 'failed', 'skipped'], 'default' => 'reserved'],
            'reason'             => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'message_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'attempts'           => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'sent_at'            => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
            'updated_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['booking_payment_id', 'step'], 'uq_reminder_step');
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('booking_payment_id', 'booking_payments', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('payment_reminders');
    }

    public function down(): void
    {
        $this->forge->dropTable('payment_reminders', true);
        $this->forge->dropTable('dunning_settings', true);
    }
}
