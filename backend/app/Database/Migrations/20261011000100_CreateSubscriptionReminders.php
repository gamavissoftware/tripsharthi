<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per (subscription, milestone) reminder that was sent: d7 / d3 / d1 before a paid period ends, and `ended` once the
 * workspace went back to the free plan. The UNIQUE key is the claim, so the daily job can run twice and nobody is told twice.
 */
class CreateSubscriptionReminders extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'subscription_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'milestone'       => ['type' => 'VARCHAR', 'constraint' => 8],
            'emailed'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['subscription_id', 'milestone']);
        $this->forge->addKey('tenant_id');
        $this->forge->createTable('subscription_reminders', true);
    }

    public function down(): void { $this->forge->dropTable('subscription_reminders', true); }
}
