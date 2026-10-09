<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Persist the exact charge on each subscription row.
 *
 * Before this, BillingService::getHistory() had to infer the billing cycle from
 * the period length and price it at the current list rate — which drifts if list
 * prices ever change. These columns record what was actually charged at order /
 * verify time, so billing history reflects the real amount, not a reconstruction.
 *
 * Both are nullable: pre-existing rows have no recorded amount, and getHistory()
 * falls back to the old inference for those.
 */
class AddAmountToSubscriptions extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('subscriptions', [
            // Amount actually charged, in paise (Razorpay's smallest unit), matching messages/money conventions.
            'amount_paise' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'default'    => null,
                'after'      => 'plan',
            ],
            'billing_cycle' => [
                'type'       => 'ENUM',
                'constraint' => ['monthly', 'annual'],
                'null'       => true,
                'default'    => null,
                'after'      => 'amount_paise',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('subscriptions', ['amount_paise', 'billing_cycle']);
    }
}
