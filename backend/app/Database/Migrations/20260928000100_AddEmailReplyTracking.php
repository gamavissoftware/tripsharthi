<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Reply tracking for email marketing.
 *
 *  - emails.replied_at     on the outbound campaign email a customer answered
 *  - emails.message_id     the RFC 5322 Message-ID of an inbound email, so the
 *                          same reply is never recorded (or alerted) twice
 *  - emails.is_auto_reply  inbound out-of-office / autoresponder messages are
 *                          kept for the timeline but never count as a reply
 */
class AddEmailReplyTracking extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('emails', [
            'replied_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null, 'after' => 'unsubscribed_at'],
            'message_id'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null, 'after' => 'tracking_token'],
            'is_auto_reply' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'direction'],
        ]);
        $this->db->query('CREATE INDEX emails_tenant_message_id ON ' . $this->db->prefixTable('emails') . ' (tenant_id, message_id)');
        $this->db->query('CREATE INDEX emails_tenant_to_email ON ' . $this->db->prefixTable('emails') . ' (tenant_id, to_email)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX emails_tenant_message_id ON ' . $this->db->prefixTable('emails'));
        $this->db->query('DROP INDEX emails_tenant_to_email ON ' . $this->db->prefixTable('emails'));
        $this->forge->dropColumn('emails', ['replied_at', 'message_id', 'is_auto_reply']);
    }
}
