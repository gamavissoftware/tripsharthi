<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase H3: per-stage rotting threshold — a deal idle in this stage longer than
 * rotting_days is flagged stale on the board and can be rerouted by the scanner.
 */
class AlterPipelineStagesAddRotting extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('pipeline_stages', [
            'rotting_days' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'probability',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('pipeline_stages', 'rotting_days');
    }
}
