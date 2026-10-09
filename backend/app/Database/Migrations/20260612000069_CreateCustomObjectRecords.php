<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * custom_object_records — instances of a custom object. Field values are stored
 * as a JSON `data` map keyed by field_key (no EAV — keeps reads simple).
 */
class CreateCustomObjectRecords extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'custom_object_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'             => ['type' => 'VARCHAR', 'constraint' => 255], // display title
            'owner_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'data'             => ['type' => 'TEXT', 'null' => true, 'default' => null], // JSON {field_key: value}
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'custom_object_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('custom_object_id', 'custom_objects', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('custom_object_records');
    }

    public function down(): void
    {
        $this->forge->dropTable('custom_object_records', true);
    }
}
