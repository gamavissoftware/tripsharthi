<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * "Log in as customer" for the TripSarthi team: a separate, time-limited session per entry (never the owner's own token), the reason it was
 * opened, and a tag on every workspace audit row written during it (audit_logs.impersonated_by = the admin's user id).
 */
class CreateImpersonation extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'admin_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tenant_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],   // the workspace owner we act as
            'token_hash'    => ['type' => 'CHAR', 'constraint' => 64],
            'reason'        => ['type' => 'VARCHAR', 'constraint' => 300],
            'read_only'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'ip'            => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at'    => ['type' => 'DATETIME'],
            'last_seen_at'  => ['type' => 'DATETIME'],
            'expires_at'    => ['type' => 'DATETIME'],
            'ended_at'      => ['type' => 'DATETIME', 'null' => true],
            'ended_by'      => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true],   // admin | expired | logout
            'actions'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],   // writes made during the session
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey(['tenant_id', 'created_at']);
        $this->forge->addKey(['admin_user_id', 'created_at']);
        $this->forge->createTable('impersonation_sessions');

        $this->forge->addColumn('audit_logs', ['impersonated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'actor_user_id']]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('audit_logs', 'impersonated_by');
        $this->forge->dropTable('impersonation_sessions', true);
    }
}
