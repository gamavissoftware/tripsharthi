<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Recurring deals, part 2: when a recurring deal is won it gets a next_renewal_at
 * date (won date + one billing interval). The opt-in `deals:renewals` cron clones
 * a fresh renewal deal on/after that date and advances it to the next cycle.
 */
class AlterDealsAddNextRenewal extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('deals', [
            'next_renewal_at' => ['type' => 'DATE', 'null' => true, 'default' => null, 'after' => 'recurring_interval'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('deals', 'next_renewal_at');
    }
}
