<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SaaS subscription tracking per tenant.
 *
 * ── Write-only-from-webhook invariant ────────────────────────────────────
 * tenants.plan and tenants.status are updated ONLY by RazorpayWebhookController
 * after a verified signature.  No controller or service touches those columns
 * directly.  This table is the audit trail; the tenant row is the live state.
 *
 * ── halted_at column ─────────────────────────────────────────────────────
 * Set by the webhook on subscription.halted.  subscription:check cron reads it
 * to decide when to downgrade — avoids relying on updated_at (which can change
 * for other reasons).
 *
 * ── status 'downgraded' ──────────────────────────────────────────────────
 * Set by subscription:check after HALT_GRACE_DAYS.  Distinguishes an
 * operator-triggered cancel (status='cancelled') from an auto-downgrade
 * for non-payment, which matters for win-back flows.
 */
class CreateSubscriptions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            // Razorpay's subscription identifier, e.g. "sub_Pq8xyz..."
            'razorpay_sub_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'razorpay_customer_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
            ],
            'plan' => [
                'type'       => 'ENUM',
                'constraint' => ['free', 'starter', 'growth', 'pro'],
                'default'    => 'free',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['created', 'authenticated', 'active', 'halted', 'cancelled', 'downgraded'],
                'default'    => 'created',
            ],
            // PHP-parsed from webhook payload — never MySQL NOW()
            'current_period_start' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'current_period_end'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            // Set on subscription.halted — read by subscription:check cron
            'halted_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'cancelled_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('razorpay_sub_id');
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('subscriptions');
    }

    public function down(): void
    {
        $this->forge->dropTable('subscriptions', true);
    }
}
