<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Email marketing: reusable email templates, bulk email campaigns to a segment,
 * per-recipient open/click tracking, and a per-tenant suppression list
 * (unsubscribes, bounces, manual blocks).
 *
 * Each campaign recipient is an `emails` row (the same log the 1:1 contact
 * email writes), so the contact timeline and the campaign report read one
 * table. UNIQUE(email_campaign_id, contact_id) is the idempotency guard — a
 * retried batch can never email the same contact twice. MySQL lets that key
 * hold any number of NULL campaign ids, so 1:1 emails are unaffected.
 *
 * Statuses are VARCHAR, not ENUM: an ENUM silently rejects a value added later
 * in code (see flows.trigger_type).
 */
class CreateEmailMarketing extends Migration
{
    public function up(): void
    {
        $ts = [
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ];
        $id     = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true];
        $fk     = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true];
        $fkNull = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null];

        // ── email_templates ────────────────────────────────────────────
        $this->forge->addField([
            'id'         => $id,
            'tenant_id'  => $fk,
            'name'       => ['type' => 'VARCHAR', 'constraint' => 150],
            'subject'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'preheader'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'html_body'  => ['type' => 'MEDIUMTEXT'],
        ] + $ts + ['deleted_at' => ['type' => 'DATETIME', 'null' => true]]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('tenant_id');
        $this->forge->createTable('email_templates', true);

        // ── email_campaigns ────────────────────────────────────────────
        $this->forge->addField([
            'id'                => $id,
            'tenant_id'         => $fk,
            'email_template_id' => $fkNull,
            'name'              => ['type' => 'VARCHAR', 'constraint' => 150],
            'subject'           => ['type' => 'VARCHAR', 'constraint' => 255],
            'preheader'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'from_name'         => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'default' => null],
            'reply_to'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            // Snapshot of the body at creation — editing the template later must
            // not change a campaign that is half sent.
            'html_body'         => ['type' => 'MEDIUMTEXT'],
            'segment'           => ['type' => 'TEXT', 'null' => true, 'default' => null],
            // draft | scheduled | processing | paused | done | failed | cancelled
            'status'            => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'draft'],
            'scheduled_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'schedule_timezone' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'cursor'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'total_contacts'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'sent_count'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'failed_count'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'stats'             => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'last_error'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'started_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'completed_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_by'        => $fkNull,
        ] + $ts + ['deleted_at' => ['type' => 'DATETIME', 'null' => true]]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey(['status', 'scheduled_at']);
        $this->forge->createTable('email_campaigns', true);

        // ── emails: campaign + tracking columns ────────────────────────
        $this->forge->addColumn('emails', [
            'email_campaign_id' => $fkNull + ['after' => 'deal_id'],
            'tracking_token'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null, 'after' => 'email_campaign_id'],
            'sent_at'           => ['type' => 'DATETIME', 'null' => true, 'default' => null, 'after' => 'error'],
            'opened_at'         => ['type' => 'DATETIME', 'null' => true, 'default' => null, 'after' => 'sent_at'],
            'open_count'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0, 'after' => 'opened_at'],
            'clicked_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null, 'after' => 'open_count'],
            'click_count'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0, 'after' => 'clicked_at'],
            'unsubscribed_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null, 'after' => 'click_count'],
        ]);
        $this->db->query('CREATE UNIQUE INDEX emails_campaign_contact_unique ON ' . $this->db->prefixTable('emails') . ' (email_campaign_id, contact_id)');
        $this->db->query('CREATE UNIQUE INDEX emails_tracking_token_unique ON ' . $this->db->prefixTable('emails') . ' (tracking_token)');

        // ── email_suppressions ─────────────────────────────────────────
        $this->forge->addField([
            'id'              => $id,
            'tenant_id'       => $fk,
            'email'           => ['type' => 'VARCHAR', 'constraint' => 255],
            'contact_id'      => $fkNull,
            // unsubscribed | bounced | complaint | manual
            'reason'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'manual'],
            'source_email_id' => $fkNull,
        ] + $ts);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'email']);
        $this->forge->createTable('email_suppressions', true);

        // ── email_clicks ───────────────────────────────────────────────
        $this->forge->addField([
            'id'                => $id,
            'tenant_id'         => $fk,
            'email_id'          => $fk,
            'email_campaign_id' => $fkNull,
            'contact_id'        => $fkNull,
            'url'               => ['type' => 'VARCHAR', 'constraint' => 2048],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'email_campaign_id']);
        $this->forge->createTable('email_clicks', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('email_clicks', true);
        $this->forge->dropTable('email_suppressions', true);
        $this->db->query('DROP INDEX emails_campaign_contact_unique ON ' . $this->db->prefixTable('emails'));
        $this->db->query('DROP INDEX emails_tracking_token_unique ON ' . $this->db->prefixTable('emails'));
        $this->forge->dropColumn('emails', [
            'email_campaign_id', 'tracking_token', 'sent_at', 'opened_at',
            'open_count', 'clicked_at', 'click_count', 'unsubscribed_at',
        ]);
        $this->forge->dropTable('email_campaigns', true);
        $this->forge->dropTable('email_templates', true);
    }
}
