<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Outbound webhook subscriptions (P3.2) — the Zapier / Make / custom-CRM hook.
 *
 * A tenant registers a URL + the events it cares about. When those events fire,
 * OutboundWebhookService enqueues a signed POST (delivered by the cron worker so
 * the request path stays fast and delivery is retried on failure).
 */
class CreateWebhookSubscriptions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'url'       => ['type' => 'VARCHAR', 'constraint' => 512],
            'secret'    => ['type' => 'VARCHAR', 'constraint' => 64],
            // JSON array of event names, or ["*"] for all.
            'events'    => ['type' => 'JSON', 'null' => true, 'default' => null],
            'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],

            'last_status'       => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'default' => null],
            'last_delivered_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'failure_count'     => ['type' => 'INT', 'constraint' => 11, 'default' => 0],

            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'is_active']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('webhook_subscriptions');
    }

    public function down(): void
    {
        $this->forge->dropTable('webhook_subscriptions', true);
    }
}
