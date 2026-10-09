<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the `meeting_scheduled` flow trigger so booking a meeting can fire an
 * automation (e.g. "meeting scheduled → send a WhatsApp confirmation template").
 */
class AddMeetingFlowTrigger extends Migration
{
    private const WITH = "ENUM('lead_created','tag_added','form_submitted','meta_lead_received',"
        . "'google_lead_received','keyword_reply','inbound_message','order_placed','order_fulfilled',"
        . "'abandoned_cart','flow_response','date_reached','deal_created','deal_stage_changed',"
        . "'deal_won','deal_lost','ticket_created','ticket_resolved','meeting_scheduled') NOT NULL";

    private const WITHOUT = "ENUM('lead_created','tag_added','form_submitted','meta_lead_received',"
        . "'google_lead_received','keyword_reply','inbound_message','order_placed','order_fulfilled',"
        . "'abandoned_cart','flow_response','date_reached','deal_created','deal_stage_changed',"
        . "'deal_won','deal_lost','ticket_created','ticket_resolved') NOT NULL";

    public function up(): void
    {
        $this->db->query('ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ' . self::WITH);
    }

    public function down(): void
    {
        $this->db->query("UPDATE `flows` SET `trigger_type`='lead_created' WHERE `trigger_type`='meeting_scheduled'");
        $this->db->query('ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ' . self::WITHOUT);
    }
}
