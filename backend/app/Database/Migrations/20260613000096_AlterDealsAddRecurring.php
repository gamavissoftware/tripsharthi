<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Recurring / subscription deals (v1): mark a deal as recurring with a billing
 * interval. `value_amount` is the per-cycle value; monthly recurring revenue
 * (MRR) is derived by normalizing each won recurring deal to a monthly figure.
 * No automatic renewal-deal generation in this version (opt-in follow-up).
 */
class AlterDealsAddRecurring extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('deals', [
            'is_recurring'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'value_amount'],
            'recurring_interval' => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true, 'default' => null, 'after' => 'is_recurring'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('deals', ['is_recurring', 'recurring_interval']);
    }
}
