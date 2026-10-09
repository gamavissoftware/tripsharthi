<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the `no_reply_followup` trigger, fired by the followups:scan cron for a
 * contact who received a marketing template and never answered it.
 */
class AddNoReplyFollowupFlowTrigger extends Migration
{
    private const VALUES = "'lead_created','tag_added','form_submitted','meta_lead_received',"
        . "'google_lead_received','keyword_reply','inbound_message','order_placed','order_fulfilled',"
        . "'abandoned_cart','flow_response','date_reached','deal_created','deal_stage_changed',"
        . "'deal_won','deal_lost','ticket_created','ticket_resolved','meeting_scheduled','record_created',"
        . "'meeting_reminder'";

    public function up(): void
    {
        $this->db->query(
            'ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ENUM('
            . self::VALUES . ",'no_reply_followup') NOT NULL"
        );
    }

    public function down(): void
    {
        $this->db->query("UPDATE `flows` SET `status`='paused' WHERE `trigger_type`='no_reply_followup'");
        $this->db->query("UPDATE `flows` SET `trigger_type`='tag_added' WHERE `trigger_type`='no_reply_followup'");
        $this->db->query(
            'ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ENUM(' . self::VALUES . ') NOT NULL'
        );
    }
}
