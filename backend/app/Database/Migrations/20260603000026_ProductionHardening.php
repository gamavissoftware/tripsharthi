<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Production-hardening schema additions:
 *
 *  users
 *    - api_token_expires_at  DATETIME NULL  — tokens expire after 90 days
 *    - reset_token           VARCHAR(64) NULL — SHA-256 of the raw reset token
 *    - reset_token_expires_at DATETIME NULL  — 1-hour window
 *
 *  conversations
 *    - is_read               TINYINT(1) DEFAULT 0 — unread indicator for inbox
 */
class ProductionHardening extends Migration
{
    public function up(): void
    {
        // ── users additions ───────────────────────────────────────────
        $this->forge->addColumn('users', [
            'api_token_expires_at'   => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'after'   => 'api_token',
            ],
            'reset_token'            => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'default'    => null,
                'after'      => 'api_token_expires_at',
            ],
            'reset_token_expires_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'after'   => 'reset_token',
            ],
        ]);

        // ── conversations additions ───────────────────────────────────
        $this->forge->addColumn('conversations', [
            'is_read' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'after'      => 'last_message_at',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', ['api_token_expires_at', 'reset_token', 'reset_token_expires_at']);
        $this->forge->dropColumn('conversations', ['is_read']);
    }
}
