<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Make campaign sends idempotent.
 *
 * A UNIQUE(campaign_id, contact_id) index lets CampaignSender reserve a contact
 * (insert-before-send) so a mid-batch crash, a job retry, or a concurrent
 * stale-lock reclaim can never re-send a WhatsApp message already delivered to a
 * contact — duplicate marketing messages are billed to the customer's WABA and
 * risk a quality downgrade/ban (CLAUDE.md §1, the highest-priority constraint).
 *
 * NULL campaign_id (inbox replies, flow sends) is exempt: MySQL/SQLite treat
 * NULLs as distinct in a unique index, so non-campaign messages never collide.
 */
class AddUniqueCampaignContactToMessages extends Migration
{
    public function up(): void
    {
        $p = $this->db->DBPrefix;

        // De-duplicate any pre-existing (campaign_id, contact_id) rows, keeping
        // the earliest message, so the UNIQUE index can be created.
        $this->db->query(
            "DELETE m1 FROM {$p}messages m1
             INNER JOIN {$p}messages m2
                ON m1.campaign_id = m2.campaign_id
               AND m1.contact_id  = m2.contact_id
               AND m1.id > m2.id
             WHERE m1.campaign_id IS NOT NULL
               AND m1.contact_id  IS NOT NULL"
        );

        $this->db->query(
            "CREATE UNIQUE INDEX messages_campaign_contact_uniq
             ON {$p}messages (campaign_id, contact_id)"
        );
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX messages_campaign_contact_uniq ON '
            . $this->db->DBPrefix . 'messages');
    }
}
