<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Product catalog + WhatsApp orders (P2.2).
 *
 * - products: a local mirror of the tenant's Meta Commerce catalog. retailer_id
 *   is the SKU shared with Meta so product messages reference the right item.
 * - commerce_orders: inbound carts customers send from the catalog (WhatsApp
 *   'order' message type).
 * - integrations gains a 'whatsapp_catalog' type to hold the Meta catalog_id.
 */
class CreateProductsAndOrders extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('integrations', [
            'type' => [
                'name'       => 'type',
                'type'       => 'ENUM',
                'constraint' => ['meta_lead_ads', 'razorpay_payments', 'shopify', 'woocommerce', 'whatsapp_catalog'],
                'default'    => 'meta_lead_ads',
            ],
        ]);

        // ── products ──────────────────────────────────────────────────
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'retailer_id'    => ['type' => 'VARCHAR', 'constraint' => 120], // SKU shared with Meta catalog
            'name'           => ['type' => 'VARCHAR', 'constraint' => 255],
            'description'    => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'price_paise'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'currency'       => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'INR'],
            'image_url'      => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true, 'default' => null],
            'availability'   => ['type' => 'ENUM', 'constraint' => ['in_stock', 'out_of_stock'], 'default' => 'in_stock'],
            'meta_product_id'=> ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'status'         => ['type' => 'ENUM', 'constraint' => ['active', 'archived'], 'default' => 'active'],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'retailer_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('products');

        // ── commerce_orders ───────────────────────────────────────────
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'contact_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'conversation_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'catalog_id'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            // [{retailer_id, quantity, item_price_paise, currency}]
            'items'           => ['type' => 'JSON', 'null' => true, 'default' => null],
            'total_paise'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'currency'        => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'INR'],
            'status'          => ['type' => 'ENUM', 'constraint' => ['placed', 'confirmed', 'paid', 'cancelled', 'fulfilled'], 'default' => 'placed'],
            'wa_message_id'   => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'default' => null],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('commerce_orders');
    }

    public function down(): void
    {
        $this->forge->dropTable('commerce_orders', true);
        $this->forge->dropTable('products', true);

        $this->forge->modifyColumn('integrations', [
            'type' => [
                'name'       => 'type',
                'type'       => 'ENUM',
                'constraint' => ['meta_lead_ads', 'razorpay_payments', 'shopify', 'woocommerce'],
                'default'    => 'meta_lead_ads',
            ],
        ]);
    }
}
