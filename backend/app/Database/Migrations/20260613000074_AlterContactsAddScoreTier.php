<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase H1: cache the derived lead-score tier and the signal breakdown on the
 * contact, so lists/Kanban can badge it and the contact page can explain "why".
 */
class AlterContactsAddScoreTier extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('contacts', [
            'score_tier' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'after'      => 'lead_score',
            ],
            'score_breakdown' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'score_tier',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('contacts', ['score_tier', 'score_breakdown']);
    }
}
