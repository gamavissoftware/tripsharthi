<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Record-level permissions (Phase M). Per-tenant visibility mode + an explicit
 * record-sharing table. Ships with every tenant on 'open' (current behaviour) so
 * enabling the feature is an opt-in, zero-impact change.
 *
 *   open  — agents see all records (default; unchanged)
 *   owner — an agent sees only records they own or that are shared with them
 *   team  — owner + records owned by anyone on a team they belong to
 *
 * See docs/CRM/RECORD_LEVEL_PERMISSIONS_DESIGN.md.
 */
class CreateRecordPermissions extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('tenants', [
            'record_visibility' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'open',
                'null'       => false,
                'after'      => 'plan',
            ],
        ]);

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'    => ['type' => 'BIGINT', 'unsigned' => true],
            'entity_type'  => ['type' => 'VARCHAR', 'constraint' => 32],
            'entity_id'    => ['type' => 'BIGINT', 'unsigned' => true],
            'grantee_type' => ['type' => 'VARCHAR', 'constraint' => 8],  // user | team
            'grantee_id'   => ['type' => 'BIGINT', 'unsigned' => true],
            'access'       => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'edit'], // read | edit (v1: any share = edit)
            'created_by'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // Lookup by record (read scoping) and by grantee (a user's shared set).
        $this->forge->addKey(['tenant_id', 'entity_type', 'entity_id']);
        $this->forge->addKey(['tenant_id', 'grantee_type', 'grantee_id']);
        $this->forge->createTable('record_shares', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('record_shares', true);
        $this->forge->dropColumn('tenants', 'record_visibility');
    }
}
