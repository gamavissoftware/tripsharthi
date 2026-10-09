<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Browser Web-Push subscriptions — one row per user/device that opted in to
 * desktop notifications. Used by PushSender to deliver "new reply" alerts.
 */
class CreatePushSubscriptions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'endpoint'   => ['type' => 'VARCHAR', 'constraint' => 512],
            'p256dh'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'auth'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('tenant_id');
        $this->forge->addUniqueKey('endpoint');
        $this->forge->createTable('push_subscriptions');
    }

    public function down(): void
    {
        $this->forge->dropTable('push_subscriptions');
    }
}
