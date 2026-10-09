<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase I2: widgets on a dashboard. `config` (JSON) holds the saved report spec
 * (entity, metric, dimension, date_range) and the chart type.
 */
class CreateDashboardWidgets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'dashboard_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'type'         => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'kpi'], // kpi|bar|line|pie|table|funnel
            'title'        => ['type' => 'VARCHAR', 'constraint' => 160],
            'config'       => ['type' => 'TEXT', 'null' => true],
            'position'     => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'width'        => ['type' => 'INT', 'constraint' => 11, 'default' => 6], // 12-col grid span
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'dashboard_id', 'position']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('dashboard_widgets');
    }

    public function down(): void
    {
        $this->forge->dropTable('dashboard_widgets', true);
    }
}
