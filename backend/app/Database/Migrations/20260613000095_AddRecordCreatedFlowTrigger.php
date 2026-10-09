<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the `record_created` flow trigger so creating a custom-object record can
 * fire an automation (optionally scoped to one object via trigger_config's
 * custom_object_id). The record must be linked to a contact at creation time —
 * the flow engine is contact-centric — otherwise the trigger is a logged no-op.
 */
class AddRecordCreatedFlowTrigger extends Migration
{
    private const WITH = "ENUM('lead_created','tag_added','form_submitted','meta_lead_received',"
        . "'google_lead_received','keyword_reply','inbound_message','order_placed','order_fulfilled',"
        . "'abandoned_cart','flow_response','date_reached','deal_created','deal_stage_changed',"
        . "'deal_won','deal_lost','ticket_created','ticket_resolved','meeting_scheduled','record_created') NOT NULL";

    private const WITHOUT = "ENUM('lead_created','tag_added','form_submitted','meta_lead_received',"
        . "'google_lead_received','keyword_reply','inbound_message','order_placed','order_fulfilled',"
        . "'abandoned_cart','flow_response','date_reached','deal_created','deal_stage_changed',"
        . "'deal_won','deal_lost','ticket_created','ticket_resolved','meeting_scheduled') NOT NULL";

    public function up(): void
    {
        $this->db->query('ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ' . self::WITH);
    }

    public function down(): void
    {
        $this->db->query("UPDATE `flows` SET `trigger_type`='lead_created' WHERE `trigger_type`='record_created'");
        $this->db->query('ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ' . self::WITHOUT);
    }
}
