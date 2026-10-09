<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase H5: per-tenant working hours, used for business-hours-aware SLA timers.
 */
class CreateBusinessHours extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'start_hour' => ['type' => 'INT', 'constraint' => 11, 'default' => 9],
            'end_hour'   => ['type' => 'INT', 'constraint' => 11, 'default' => 18],
            'workdays'   => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => '1,2,3,4,5'], // ISO-8601 day numbers
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('business_hours');
    }

    public function down(): void
    {
        $this->forge->dropTable('business_hours', true);
    }
}
