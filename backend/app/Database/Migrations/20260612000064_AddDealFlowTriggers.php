<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds CRM deal triggers to flows.trigger_type so a flow can fire when a deal is
 * created, changes stage, or is won/lost — the heart of WhatsApp × CRM
 * automation (e.g. "deal won → send onboarding template").
 */
class AddDealFlowTriggers extends Migration
{
    private const WITH_DEALS = "ENUM('lead_created','tag_added','form_submitted','meta_lead_received',"
        . "'google_lead_received','keyword_reply','inbound_message','order_placed','order_fulfilled',"
        . "'abandoned_cart','flow_response','date_reached','deal_created','deal_stage_changed',"
        . "'deal_won','deal_lost') NOT NULL";

    private const WITHOUT_DEALS = "ENUM('lead_created','tag_added','form_submitted','meta_lead_received',"
        . "'google_lead_received','keyword_reply','inbound_message','order_placed','order_fulfilled',"
        . "'abandoned_cart','flow_response','date_reached') NOT NULL";

    public function up(): void
    {
        $this->db->query('ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ' . self::WITH_DEALS);
    }

    public function down(): void
    {
        $this->db->query("UPDATE `flows` SET `trigger_type`='lead_created' WHERE `trigger_type` IN ('deal_created','deal_stage_changed','deal_won','deal_lost')");
        $this->db->query('ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ' . self::WITHOUT_DEALS);
    }
}
