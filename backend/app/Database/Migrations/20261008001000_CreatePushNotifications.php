<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Native mobile push (Expo).
 *  - mobile_devices: one row per installed app (Expo push token). A token belongs to ONE user at a time: logging in as
 *    someone else on the same phone moves it, so a handed-over phone never receives the previous person's alerts.
 *  - push_preferences: per-user switches (global, per category), quiet hours and lock-screen privacy.
 *  - push_log: audit + idempotency + retry queue. One row per (device, notification); `suppressed` rows record WHY
 *    nothing was sent (preference, quiet hours, throttle, no device) so "why didn't I get it?" is answerable.
 */
class CreatePushNotifications extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'expo_token'   => ['type' => 'VARCHAR', 'constraint' => 120],
            'platform'     => ['type' => 'ENUM', 'constraint' => ['ios', 'android', 'web', 'unknown'], 'default' => 'unknown'],
            'device_name'  => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'app_version'  => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'last_seen_at' => ['type' => 'DATETIME', 'null' => true],
            'disabled_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'disabled_reason' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'default' => null],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('expo_token');
        $this->forge->addKey(['tenant_id', 'user_id', 'disabled_at']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('mobile_devices');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'enabled'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'categories'  => ['type' => 'JSON', 'null' => true],
            'quiet_start' => ['type' => 'CHAR', 'constraint' => 5, 'null' => true, 'default' => null],
            'quiet_end'   => ['type' => 'CHAR', 'constraint' => 5, 'null' => true, 'default' => null],
            'privacy'     => ['type' => 'ENUM', 'constraint' => ['full', 'minimal'], 'default' => 'full'],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'user_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('push_preferences');

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'device_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'category'    => ['type' => 'VARCHAR', 'constraint' => 20],
            'event'       => ['type' => 'VARCHAR', 'constraint' => 30],
            'dedupe_key'  => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'default' => null],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'body'        => ['type' => 'VARCHAR', 'constraint' => 300],
            'data'        => ['type' => 'JSON', 'null' => true],
            'status'      => ['type' => 'ENUM', 'constraint' => ['queued', 'sent', 'delivered', 'error', 'failed', 'suppressed'], 'default' => 'queued'],
            'reason'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'ticket_id'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'attempts'    => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'sent_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'receipt_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'dedupe_key']);
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->addKey(['tenant_id', 'user_id', 'created_at']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('push_log');
    }

    public function down(): void
    {
        foreach (['push_log', 'push_preferences', 'mobile_devices'] as $t) { $this->forge->dropTable($t, true); }
    }
}
