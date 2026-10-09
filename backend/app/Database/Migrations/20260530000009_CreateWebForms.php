<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWebForms extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'form_token'   => ['type' => 'VARCHAR', 'constraint' => 36],
            'title'        => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => 'Contact Form'],
            // JSON array: [{"field_key":"name","label":"Name","required":true}, …]
            'fields'       => ['type' => 'JSON', 'null' => true, 'default' => null],
            'redirect_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'active'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('form_token');
        $this->forge->addKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('web_forms');
    }

    public function down(): void
    {
        $this->forge->dropTable('web_forms', true);
    }
}
