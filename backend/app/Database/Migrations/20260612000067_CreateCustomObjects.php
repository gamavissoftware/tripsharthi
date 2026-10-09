<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * custom_objects — tenant-defined record types (Property, Patient, Policy,
 * Course …). The multi-industry superpower: a vertical adds its own objects
 * without any code change.
 */
class CreateCustomObjects extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'label_singular' => ['type' => 'VARCHAR', 'constraint' => 80],
            'label_plural'   => ['type' => 'VARCHAR', 'constraint' => 80],
            'api_name'       => ['type' => 'VARCHAR', 'constraint' => 60], // machine name, unique per tenant
            'icon'           => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true, 'default' => null],
            'color'          => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true, 'default' => null],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'api_name']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('custom_objects');
    }

    public function down(): void
    {
        $this->forge->dropTable('custom_objects', true);
    }
}
