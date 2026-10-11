<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Plan prices are now EXCLUSIVE of GST: the customer pays the (offer-adjusted) price PLUS GST. `amount_paise` stays "what the customer was charged"
 * (so every Razorpay check keeps working); these columns record how it splits. NULL = an older row whose price already included GST.
 * `platform_invoices.price_basis` says which way an invoice was built (exclusive = GST charged extra; inclusive = GST carved out of the amount).
 */
class AddGstExtraToSubscriptions extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('subscriptions', [
            'taxable_paise' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'gst_paise'     => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'gst_rate'      => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
        ]);
        $this->forge->addColumn('platform_invoices', [
            'price_basis' => ['type' => 'VARCHAR', 'constraint' => 9, 'default' => 'inclusive'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('platform_invoices', 'price_basis');
        $this->forge->dropColumn('subscriptions', ['taxable_paise', 'gst_paise', 'gst_rate']);
    }
}
