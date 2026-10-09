<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase J3: a deal can select a price book, driving product line-item prices.
 */
class AlterDealsAddPriceBook extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('deals', [
            'price_book_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'currency'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('deals', 'price_book_id');
    }
}
