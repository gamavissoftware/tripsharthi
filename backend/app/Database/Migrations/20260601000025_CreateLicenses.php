<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * License key storage for self_hosted mode.
 *
 * Only ever has ONE row (one installation, one license).
 * Stored as a table so the cron can update last_check_at without
 * touching the filesystem or env.
 *
 * ── last_check_at semantics ───────────────────────────────────────────────
 * Timestamp of the last SUCCESSFUL phone-home (Gamavis returned status=active).
 * On failure, last_check_status='failed' is written but last_check_at is NOT
 * updated.  This means last_check_at always represents the last known-good state
 * and is the anchor for grace period calculation.
 *
 * ── status transitions ────────────────────────────────────────────────────
 * inactive  → first state, also set if license is revoked
 * active    → phone-home confirmed active; OR within GRACE_PERIOD_DAYS of last ok check
 * grace     → phone-home has failed for > GRACE_PERIOD_DAYS; system enters read-only
 */
class CreateLicenses extends Migration
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
            // SHA-256(raw_key) for fast lookup without exposing the key in queries
            'key_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
            // Full signed key stored for re-verification on each checkStatus() call
            'key_payload' => ['type' => 'TEXT'],
            'customer_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'activated_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            // Last SUCCESSFUL phone-home — grace period anchor
            'last_check_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'last_check_status' => [
                'type'       => 'ENUM',
                'constraint' => ['ok', 'failed'],
                'null'       => true,
                'default'    => null,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'grace', 'inactive'],
                'default'    => 'inactive',
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('key_hash');
        $this->forge->createTable('licenses');
    }

    public function down(): void
    {
        $this->forge->dropTable('licenses', true);
    }
}
