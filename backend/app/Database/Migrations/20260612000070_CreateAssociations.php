<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * associations — polymorphic any↔any links. Powers contact↔property,
 * deal↔property, contact↔policy, etc. without a join table per pair. Types are
 * strings like 'contact', 'deal', 'ticket', 'custom_object_record'.
 */
class CreateAssociations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'from_type'  => ['type' => 'VARCHAR', 'constraint' => 40],
            'from_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'to_type'    => ['type' => 'VARCHAR', 'constraint' => 40],
            'to_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'label'      => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'from_type', 'from_id', 'to_type', 'to_id']);
        $this->forge->addKey(['tenant_id', 'from_type', 'from_id']);
        $this->forge->addKey(['tenant_id', 'to_type', 'to_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('associations');
    }

    public function down(): void
    {
        $this->forge->dropTable('associations', true);
    }
}
