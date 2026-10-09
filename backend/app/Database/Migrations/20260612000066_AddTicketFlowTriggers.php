<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds ticket_created / ticket_resolved to flows.trigger_type so support flows
 * can fire — e.g. "ticket resolved → send a CSAT survey template over WhatsApp".
 */
class AddTicketFlowTriggers extends Migration
{
    private const WITH = "ENUM('lead_created','tag_added','form_submitted','meta_lead_received',"
        . "'google_lead_received','keyword_reply','inbound_message','order_placed','order_fulfilled',"
        . "'abandoned_cart','flow_response','date_reached','deal_created','deal_stage_changed',"
        . "'deal_won','deal_lost','ticket_created','ticket_resolved') NOT NULL";

    private const WITHOUT = "ENUM('lead_created','tag_added','form_submitted','meta_lead_received',"
        . "'google_lead_received','keyword_reply','inbound_message','order_placed','order_fulfilled',"
        . "'abandoned_cart','flow_response','date_reached','deal_created','deal_stage_changed',"
        . "'deal_won','deal_lost') NOT NULL";

    public function up(): void
    {
        $this->db->query('ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ' . self::WITH);
    }

    public function down(): void
    {
        $this->db->query("UPDATE `flows` SET `trigger_type`='lead_created' WHERE `trigger_type` IN ('ticket_created','ticket_resolved')");
        $this->db->query('ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ' . self::WITHOUT);
    }
}
