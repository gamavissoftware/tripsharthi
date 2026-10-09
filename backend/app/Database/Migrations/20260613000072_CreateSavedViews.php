<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * saved_views — per-entity list views (filters + columns + sort) that a user can
 * save and optionally share with the team. CRM Phase G (ARCH §4.16).
 *
 * `view_columns` avoids the MySQL reserved word COLUMNS; the API exposes it as
 * `columns`.
 */
class CreateSavedViews extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'entity_type'  => ['type' => 'VARCHAR', 'constraint' => 40],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 120],
            'filters'      => ['type' => 'TEXT', 'null' => true, 'default' => null], // JSON {match, conditions[]}
            'view_columns' => ['type' => 'TEXT', 'null' => true, 'default' => null], // JSON [field,...]
            'sort'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null], // "field:dir"
            'is_shared'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'owner_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'entity_type']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('saved_views');
    }

    public function down(): void
    {
        $this->forge->dropTable('saved_views', true);
    }
}
