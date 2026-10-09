<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTenants extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'slug' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'plan' => [
                'type'       => 'ENUM',
                'constraint' => ['free', 'starter', 'growth', 'pro'],
                'default'    => 'free',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'suspended', 'cancelled'],
                'default'    => 'active',
            ],
            'mode' => [
                'type'       => 'ENUM',
                'constraint' => ['saas', 'self_hosted'],
                'default'    => 'saas',
            ],
            'settings' => [
                'type'    => 'JSON',
                'null'    => true,
                'default' => null,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('status');
        $this->forge->createTable('tenants');
    }

    public function down(): void
    {
        $this->forge->dropTable('tenants', true);
    }
}
