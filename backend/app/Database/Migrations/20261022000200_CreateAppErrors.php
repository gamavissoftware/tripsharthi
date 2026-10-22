<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Crashes and unexpected errors reported by the PHONE app, so a problem on someone's phone is not invisible. Platform-level (admin app -> Mobile app). */
class CreateAppErrors extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'tenant_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'platform'    => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'android'],
            'app_version' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'screen'      => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'message'     => ['type' => 'VARCHAR', 'constraint' => 300],
            'stack'       => ['type' => 'TEXT', 'null' => true],
            'ip_hash'     => ['type' => 'CHAR', 'constraint' => 32, 'null' => true],
            'created_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('created_at');
        $this->forge->createTable('app_errors', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('app_errors', true);
    }
}
