<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * custom_object_fields — the schema of each custom object. Drives the dynamic
 * create/edit form and validation for its records.
 */
class CreateCustomObjectFields extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'custom_object_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'field_key'        => ['type' => 'VARCHAR', 'constraint' => 60],
            'label'            => ['type' => 'VARCHAR', 'constraint' => 100],
            'type'             => ['type' => 'ENUM', 'constraint' => ['text', 'textarea', 'number', 'date', 'select', 'boolean', 'email', 'phone'], 'default' => 'text'],
            'options'          => ['type' => 'TEXT', 'null' => true, 'default' => null], // JSON array for select
            'required'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'position'         => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'custom_object_id', 'position']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('custom_object_id', 'custom_objects', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('custom_object_fields');
    }

    public function down(): void
    {
        $this->forge->dropTable('custom_object_fields', true);
    }
}
