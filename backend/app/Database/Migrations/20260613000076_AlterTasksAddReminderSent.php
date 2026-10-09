<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase H4: a once-only guard so the task_due scanner never fires a reminder
 * twice for the same task.
 */
class AlterTasksAddReminderSent extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('tasks', [
            'reminder_sent' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'reminder_at',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tasks', 'reminder_sent');
    }
}
