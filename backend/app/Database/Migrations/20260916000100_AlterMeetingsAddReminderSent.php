<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A once-only guard so the meetings:remind scanner can never send the same
 * contact two reminders for one meeting.
 *
 * The flag is set BEFORE the trigger fires, so a crash mid-scan costs a missed
 * reminder rather than a duplicate — the same trade the tasks scanner makes.
 * A missed reminder is invisible; a duplicate 30 minutes before a demo is the
 * kind of thing that gets a number reported.
 */
class AlterMeetingsAddReminderSent extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('meetings', [
            'reminder_sent' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'status',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('meetings', 'reminder_sent');
    }
}
