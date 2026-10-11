<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TripSarthi's own E-MAIL marketing to its customers (admin app -> Email marketing).
 *  - CONSENT: users and partners get an explicit opt-in flag (default 0 - never assumed). Withdrawn by the one-click unsubscribe link in every e-mail.
 *  - platform_email_campaigns: one e-mail (subject + body) to one segment. draft -> sending -> sent (or cancelled).
 *  - platform_email_sends: the snapshot of recipients taken when "Send" is pressed, one row per address, processed a few per minute by cron
 *    (UNIQUE per campaign + address, so nobody gets the same campaign twice).
 */
class CreatePlatformEmailMarketing extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'email_marketing_opt_in' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'email_opt_in_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addColumn('partners', [
            'email_marketing_opt_in' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 120],
            'subject'     => ['type' => 'VARCHAR', 'constraint' => 150],
            'body'        => ['type' => 'MEDIUMTEXT'],
            'segment'     => ['type' => 'VARCHAR', 'constraint' => 30],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'draft'],
            'recipients'  => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_by'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            'started_at'  => ['type' => 'DATETIME', 'null' => true],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->createTable('platform_email_campaigns', true);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'campaign_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'email'       => ['type' => 'VARCHAR', 'constraint' => 190],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'kind'        => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'user'],   // user | partner (where the consent lives)
            'status'      => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'queued'], // queued | sending | sent | failed | skipped
            'error'       => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'sent_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['campaign_id', 'email']);
        $this->forge->addKey(['campaign_id', 'status']);
        $this->forge->createTable('platform_email_sends', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('platform_email_sends', true);
        $this->forge->dropTable('platform_email_campaigns', true);
        $this->forge->dropColumn('partners', 'email_marketing_opt_in');
        $this->forge->dropColumn('users', ['email_marketing_opt_in', 'email_opt_in_at']);
    }
}
