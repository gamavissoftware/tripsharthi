<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase K1: an append-only audit trail of CRM mutations. before/after hold the
 * CHANGED fields only (lean diff) for updates; the full row for create/delete.
 */
class CreateAuditLogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'actor_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'action'        => ['type' => 'VARCHAR', 'constraint' => 30],
            'entity_type'   => ['type' => 'VARCHAR', 'constraint' => 40],
            'entity_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'before'        => ['type' => 'TEXT', 'null' => true],
            'after'         => ['type' => 'TEXT', 'null' => true],
            'ip'            => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'entity_type', 'created_at']);
        $this->forge->addKey(['tenant_id', 'actor_user_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('audit_logs');
    }

    public function down(): void
    {
        $this->forge->dropTable('audit_logs', true);
    }
}
