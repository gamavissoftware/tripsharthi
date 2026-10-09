<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase I3: per-user sales targets/quotas for a period, used for attainment %.
 */
class CreateSalesTargets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'metric'        => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'won_value'], // won_value | won_count
            'period_start'  => ['type' => 'DATE'],
            'period_end'    => ['type' => 'DATE'],
            'target_amount' => ['type' => 'BIGINT', 'constraint' => 20, 'default' => 0], // paise for won_value, count otherwise
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'user_id', 'period_start']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('sales_targets');
    }

    public function down(): void
    {
        $this->forge->dropTable('sales_targets', true);
    }
}
