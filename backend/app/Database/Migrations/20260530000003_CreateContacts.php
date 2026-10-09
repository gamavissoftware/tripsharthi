<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateContacts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'wa_number' => ['type' => 'VARCHAR', 'constraint' => 20],
            'name'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'email'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['new', 'contacted', 'qualified', 'won', 'lost'],
                'default'    => 'new',
            ],
            'source' => [
                'type'       => 'ENUM',
                'constraint' => ['manual', 'csv_import', 'web_form', 'meta_lead_ads'],
                'default'    => 'manual',
            ],
            'opt_in'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'last_inbound_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'wa_number']);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey(['tenant_id', 'source']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('contacts');
    }

    public function down(): void
    {
        $this->forge->dropTable('contacts', true);
    }
}
