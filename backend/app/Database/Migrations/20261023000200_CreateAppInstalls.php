<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** One row per phone-app installation that has opened the app while signed in: which version, which update, when last seen. No push token needed. */
class CreateAppInstalls extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'       => ['type' => 'INT', 'unsigned' => true],
            'user_id'         => ['type' => 'INT', 'unsigned' => true],
            'install_id'      => ['type' => 'VARCHAR', 'constraint' => 40],
            'platform'        => ['type' => 'ENUM', 'constraint' => ['android', 'ios'], 'default' => 'android'],
            'app_version'     => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'runtime_version' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'update_id'       => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true],
            'device_name'     => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'first_seen_at'   => ['type' => 'DATETIME'],
            'last_seen_at'    => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'install_id']);
        $this->forge->addKey('last_seen_at');
        $this->forge->createTable('app_installs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('app_installs', true);
    }
}
