<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Services\Flow\FlowTriggers;
use CodeIgniter\Database\Migration;

/**
 * Widens flows.trigger_type to the full FlowTriggers list (adds the travel triggers and fixes the
 * pre-existing gap where `task_due` was accepted by the engine but could not be stored), and adds
 * travel_trigger_log — the claim table that makes cron-fired triggers fire once per entity.
 */
class AddTravelFlowTriggers extends Migration
{
    /** The ENUM as it stood before this migration. */
    private const BEFORE = ['lead_created', 'tag_added', 'form_submitted', 'meta_lead_received', 'google_lead_received', 'keyword_reply',
        'inbound_message', 'order_placed', 'order_fulfilled', 'abandoned_cart', 'flow_response', 'date_reached', 'deal_created',
        'deal_stage_changed', 'deal_won', 'deal_lost', 'ticket_created', 'ticket_resolved', 'meeting_scheduled', 'record_created',
        'meeting_reminder', 'no_reply_followup'];

    private static function enum(array $values): string
    {
        return implode(',', array_map(static fn (string $v): string => "'" . $v . "'", $values));
    }

    public function up(): void
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ENUM(' . self::enum(FlowTriggers::all()) . ') NOT NULL');
        }

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'trigger_type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 30],
            'entity_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            // Distinguishes repeat firings of the same trigger on the same entity (e.g. one per flow, or per N-day setting).
            'claim_key'   => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => ''],
            'fired_at'    => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'trigger_type', 'entity_type', 'entity_id', 'claim_key'], 'uq_travel_trigger_claim');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('travel_trigger_log');
    }

    public function down(): void
    {
        $this->forge->dropTable('travel_trigger_log', true);
        if ($this->db->DBDriver === 'MySQLi') {
            $new = array_diff(FlowTriggers::all(), self::BEFORE);
            $this->db->query("UPDATE `flows` SET `status`='paused' WHERE `trigger_type` IN (" . self::enum($new) . ')');
            $this->db->query("UPDATE `flows` SET `trigger_type`='tag_added' WHERE `trigger_type` IN (" . self::enum($new) . ')');
            $this->db->query('ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ENUM(' . self::enum(self::BEFORE) . ') NOT NULL');
        }
    }
}
