<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Link outbound messages back to the campaign that produced them (P1.3/P1.4).
 *
 * Enables:
 *   - Retargeting: find a campaign's recipients filtered by outcome.
 *   - Click/CTA analytics: attribute interactions to a campaign.
 *
 * Nullable — non-campaign messages (inbox replies, flow sends) leave it NULL.
 * ON DELETE SET NULL so deleting a campaign never orphans message history.
 */
class AddCampaignIdToMessages extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('messages', [
            'campaign_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'default'    => null,
                'after'      => 'conversation_id',
            ],
        ]);

        $this->db->query('CREATE INDEX messages_campaign_idx ON '
            . $this->db->DBPrefix . 'messages (campaign_id, status)');

        $this->forge->addForeignKey('campaign_id', 'campaigns', 'id', 'SET NULL', 'CASCADE', 'messages_campaign_fk');
    }

    public function down(): void
    {
        $this->forge->dropForeignKey('messages', 'messages_campaign_fk');
        $this->db->query('DROP INDEX messages_campaign_idx ON '
            . $this->db->DBPrefix . 'messages');
        $this->forge->dropColumn('messages', 'campaign_id');
    }
}
