<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Commerce foundation (P2).
 *
 * 1. Extends integrations.type so a tenant can connect their OWN Razorpay
 *    account (payments to their customers), plus Shopify / WooCommerce stores.
 *    These are bring-your-own credentials, distinct from TravelPilot's platform
 *    Razorpay used for subscription billing.
 * 2. Creates payment_links — one row per payment request sent to a contact.
 */
class PaymentsCommerce extends Migration
{
    public function up(): void
    {
        // Extend the integrations type enum.
        $this->forge->modifyColumn('integrations', [
            'type' => [
                'name'       => 'type',
                'type'       => 'ENUM',
                'constraint' => ['meta_lead_ads', 'razorpay_payments', 'shopify', 'woocommerce'],
                'default'    => 'meta_lead_ads',
            ],
        ]);

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'contact_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'conversation_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],

            'amount_paise' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'currency'     => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'INR'],
            'description'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],

            // Our idempotent reference (also embedded in the Razorpay link).
            'reference_id'        => ['type' => 'VARCHAR', 'constraint' => 64],
            'razorpay_link_id'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'razorpay_payment_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'short_url'           => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true, 'default' => null],

            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['created', 'sent', 'paid', 'cancelled', 'expired', 'failed'],
                'default'    => 'created',
            ],
            // The outbound WhatsApp message that delivered the link.
            'message_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'paid_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],

            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey('razorpay_link_id');
        $this->forge->addUniqueKey('reference_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('payment_links');
    }

    public function down(): void
    {
        $this->forge->dropTable('payment_links', true);

        $this->forge->modifyColumn('integrations', [
            'type' => [
                'name'       => 'type',
                'type'       => 'ENUM',
                'constraint' => ['meta_lead_ads'],
                'default'    => 'meta_lead_ads',
            ],
        ]);
    }
}
