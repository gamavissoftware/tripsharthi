<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase K3 (field-level visibility): a custom field can be marked restricted —
 * its values are visible only to owners/admins, hidden from agents.
 */
class AlterCustomFieldsAddRestricted extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('custom_fields', [
            'restricted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'is_required'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('custom_fields', 'restricted');
    }
}
