<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * accounts — companies/organizations (B2B). CRM Phase A.
 * Contacts belong to an account; deals/tickets reference one.
 */
class CreateAccounts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 255],
            'domain'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'industry'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'type'              => ['type' => 'ENUM', 'constraint' => ['prospect', 'customer', 'partner', 'other'], 'default' => 'prospect'],
            'owner_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'phone'             => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'website'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'address_line'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'city'              => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'state'             => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'country'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'postal_code'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
            'annual_revenue'    => ['type' => 'BIGINT', 'constraint' => 20, 'null' => true, 'default' => null], // paise
            'employee_count'    => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'default' => null],
            'parent_account_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'owner_id']);
        $this->forge->addKey(['tenant_id', 'name']);
        $this->forge->addKey('parent_account_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('owner_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('accounts');
    }

    public function down(): void
    {
        $this->forge->dropTable('accounts', true);
    }
}
