<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase H5: stamp when a ticket's SLA breach was escalated (once-only guard).
 */
class AlterTicketsAddEscalatedAt extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('tickets', [
            'escalated_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'sla_due_at'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tickets', 'escalated_at');
    }
}
