<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCustomFields extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'label'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'field_key'   => ['type' => 'VARCHAR', 'constraint' => 50],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['text', 'number', 'date', 'url', 'select'],
                'default'    => 'text',
            ],
            'options'     => ['type' => 'JSON', 'null' => true, 'default' => null],
            'is_required' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'sort_order'  => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'field_key']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('custom_fields');
    }

    public function down(): void
    {
        $this->forge->dropTable('custom_fields', true);
    }
}
