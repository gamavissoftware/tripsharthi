<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePhoneNumbers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'waba_account_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            // Denormalised for fast tenant-scoped queries without joining waba_accounts
            'tenant_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            // Meta's phone_number_id — used to match inbound webhook payloads
            'phone_number_id'  => ['type' => 'VARCHAR', 'constraint' => 100],
            // Human-readable, e.g. "+91 99999 00000"
            'display_number'   => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
            'quality_rating'   => [
                'type'       => 'ENUM',
                'constraint' => ['green', 'yellow', 'red', 'unknown'],
                'default'    => 'unknown',
            ],
            'is_default'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'phone_number_id']);
        $this->forge->addKey('waba_account_id');
        $this->forge->addForeignKey('waba_account_id', 'waba_accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('phone_numbers');
    }

    public function down(): void
    {
        $this->forge->dropTable('phone_numbers', true);
    }
}
