<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateContactFieldValues extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'contact_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'custom_field_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'value'           => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['contact_id', 'custom_field_id']);
        $this->forge->addKey('custom_field_id');
        $this->forge->addForeignKey('contact_id', 'contacts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('custom_field_id', 'custom_fields', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('contact_field_values');
    }

    public function down(): void
    {
        $this->forge->dropTable('contact_field_values', true);
    }
}
