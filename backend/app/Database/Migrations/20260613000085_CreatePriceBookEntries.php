<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase J3: a product's price within a price book (paise).
 */
class CreatePriceBookEntries extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'price_book_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'product_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'price_paise'   => ['type' => 'BIGINT', 'constraint' => 20, 'default' => 0],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['price_book_id', 'product_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('price_book_entries');
    }

    public function down(): void
    {
        $this->forge->dropTable('price_book_entries', true);
    }
}
