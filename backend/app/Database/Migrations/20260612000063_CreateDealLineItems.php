<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * deal_line_items — products/services on a deal (CPQ-lite). Prices in paise;
 * percentages as whole numbers. total is computed at write time.
 */
class CreateDealLineItems extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'deal_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'product_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'quantity'     => ['type' => 'INT', 'constraint' => 11, 'default' => 1],
            'unit_price'   => ['type' => 'BIGINT', 'constraint' => 20, 'default' => 0], // paise
            'discount_pct' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'tax_pct'      => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'total'        => ['type' => 'BIGINT', 'constraint' => 20, 'default' => 0], // paise
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'deal_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('deal_id', 'deals', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('deal_line_items');
    }

    public function down(): void
    {
        $this->forge->dropTable('deal_line_items', true);
    }
}
