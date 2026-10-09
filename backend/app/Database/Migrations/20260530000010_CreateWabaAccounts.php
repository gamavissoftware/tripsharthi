<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWabaAccounts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            // Meta WABA / Business IDs
            'waba_id'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'business_id'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            // AES-256-GCM ciphertext — NEVER the raw token
            'access_token_enc' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'display_name'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            // Random string used for Meta webhook verification handshake
            'verify_token'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'status'           => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'active', 'suspended'],
                'default'    => 'pending',
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('waba_accounts');
    }

    public function down(): void
    {
        $this->forge->dropTable('waba_accounts', true);
    }
}
