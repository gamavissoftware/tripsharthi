<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One sign-in token PER DEVICE. Until now users.api_token held a single token, so signing in on the phone signed the same person out of the
 * web app (and the reverse). Only the SHA-256 of a token is stored. Tokens issued before this migration (users.api_token) keep working until they expire.
 */
class CreateUserTokens extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'      => ['type' => 'INT', 'unsigned' => true],
            'token_hash'   => ['type' => 'CHAR', 'constraint' => 64],
            'client'       => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'web'],
            'ip'           => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'created_at'   => ['type' => 'DATETIME'],
            'last_used_at' => ['type' => 'DATETIME', 'null' => true],
            'expires_at'   => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey('user_id');
        $this->forge->addKey('expires_at');
        $this->forge->createTable('user_tokens', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('user_tokens', true);
    }
}
