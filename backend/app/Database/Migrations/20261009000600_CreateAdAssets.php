<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Images the agency uploads for ads (kept so they can be re-used across campaigns and platforms). */
class CreateAdAssets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'original_name' => ['type' => 'VARCHAR', 'constraint' => 200],
            'mime'          => ['type' => 'VARCHAR', 'constraint' => 30],
            'width'         => ['type' => 'SMALLINT', 'unsigned' => true],
            'height'        => ['type' => 'SMALLINT', 'unsigned' => true],
            'shape'         => ['type' => 'VARCHAR', 'constraint' => 12],                     // landscape | square | portrait | other
            'size'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'path'          => ['type' => 'VARCHAR', 'constraint' => 255],
            'sha256'        => ['type' => 'CHAR', 'constraint' => 64],
            'meta_hashes'   => ['type' => 'JSON', 'null' => true],                              // {"act_123": "<image hash>"} so one image is uploaded to an account once
            'created_by'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'sha256']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ad_assets');
    }

    public function down(): void
    {
        $this->forge->dropTable('ad_assets', true);
    }
}
