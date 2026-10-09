<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the `meeting_reminder` flow trigger, fired by the meetings:remind cron a
 * fixed number of minutes before a scheduled meeting starts.
 *
 * It is a separate trigger from `meeting_scheduled` because the two fire at
 * opposite ends of the booking and face opposite window states: the confirmation
 * goes out seconds after the contact tapped a slot, so the 24-hour window is
 * certainly open and free-form is free. The reminder goes out 30 minutes before
 * a meeting that may have been booked days earlier, by which time the window is
 * usually shut and only an approved template may be sent.
 */
class AddMeetingReminderFlowTrigger extends Migration
{
    private const VALUES = "'lead_created','tag_added','form_submitted','meta_lead_received',"
        . "'google_lead_received','keyword_reply','inbound_message','order_placed','order_fulfilled',"
        . "'abandoned_cart','flow_response','date_reached','deal_created','deal_stage_changed',"
        . "'deal_won','deal_lost','ticket_created','ticket_resolved','meeting_scheduled','record_created'";

    public function up(): void
    {
        $this->db->query(
            'ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ENUM('
            . self::VALUES . ",'meeting_reminder') NOT NULL"
        );
    }

    public function down(): void
    {
        $this->db->query("UPDATE `flows` SET `trigger_type`='meeting_scheduled' WHERE `trigger_type`='meeting_reminder'");
        $this->db->query(
            'ALTER TABLE `flows` MODIFY COLUMN `trigger_type` ENUM(' . self::VALUES . ') NOT NULL'
        );
    }
}
