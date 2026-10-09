<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase G4: let lead_imports drive imports for any CRM entity, not just contacts.
 */
class AlterLeadImportsAddEntity extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('lead_imports', [
            'entity_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'default'    => 'contact',
                'after'      => 'tenant_id',
            ],
            'custom_object_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'entity_type',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('lead_imports', ['entity_type', 'custom_object_id']);
    }
}
