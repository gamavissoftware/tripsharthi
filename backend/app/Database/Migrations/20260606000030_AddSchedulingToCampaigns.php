<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Broadcast scheduler (P1.2).
 *
 * Adds a 'scheduled' status to campaigns plus a schedule_timezone column so a
 * broadcast can be armed for a future moment in the user's own timezone.
 *
 * The cron-driven ScheduledCampaignDispatcher promotes due 'scheduled'
 * campaigns to 'processing' and enqueues the existing campaign_send job —
 * no change to the send pipeline itself.
 */
class AddSchedulingToCampaigns extends Migration
{
    public function up(): void
    {
        // Extend the status enum with 'scheduled'.
        $this->forge->modifyColumn('campaigns', [
            'status' => [
                'name'       => 'status',
                'type'       => 'ENUM',
                'constraint' => ['draft', 'scheduled', 'processing', 'done', 'failed', 'paused'],
                'default'    => 'draft',
            ],
        ]);

        // IANA timezone the scheduled_at instant was chosen in (e.g. Asia/Kolkata).
        // scheduled_at itself is always stored in UTC.
        $this->forge->addColumn('campaigns', [
            'schedule_timezone' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'default'    => null,
                'after'      => 'scheduled_at',
            ],
        ]);

        // Index to let the dispatcher cheaply find due rows.
        $this->db->query('CREATE INDEX campaigns_scheduled_idx ON '
            . $this->db->DBPrefix . 'campaigns (status, scheduled_at)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX campaigns_scheduled_idx ON '
            . $this->db->DBPrefix . 'campaigns');

        $this->forge->dropColumn('campaigns', 'schedule_timezone');

        $this->forge->modifyColumn('campaigns', [
            'status' => [
                'name'       => 'status',
                'type'       => 'ENUM',
                'constraint' => ['draft', 'processing', 'done', 'failed', 'paused'],
                'default'    => 'draft',
            ],
        ]);
    }
}
