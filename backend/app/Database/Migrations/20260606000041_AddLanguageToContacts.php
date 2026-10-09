<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-contact preferred language (P4.3) for multi-language template sends.
 *
 * When set (e.g. 'hi', 'en_US'), campaign/flow template sends auto-pick the
 * approved same-name template in that language, falling back to the chosen one.
 */
class AddLanguageToContacts extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('contacts', [
            'language' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true, 'default' => null, 'after' => 'email'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('contacts', 'language');
    }
}
