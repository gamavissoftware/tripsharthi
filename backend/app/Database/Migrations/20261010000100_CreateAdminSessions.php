<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sessions for the separate platform-admin app (admin.tripsarthi.com).
 * They live apart from users.api_token on purpose: the customer app keeps ONE token per user, so signing in there would
 * replace an admin's session (and the other way round). Only the SHA-256 of the token is stored.
 */
class CreateAdminSessions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'token_hash'   => ['type' => 'CHAR', 'constraint' => 64],
            'ip'           => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'default' => null],
            'user_agent'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'default' => null],
            'created_at'   => ['type' => 'DATETIME'],
            'last_seen_at' => ['type' => 'DATETIME'],
            'expires_at'   => ['type' => 'DATETIME'],
            'revoked_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey(['user_id', 'revoked_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('admin_sessions');
    }

    public function down(): void
    {
        $this->forge->dropTable('admin_sessions', true);
    }
}
