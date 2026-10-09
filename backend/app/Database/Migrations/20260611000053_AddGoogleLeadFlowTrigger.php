<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds 'google_lead_received' to flows.trigger_type so a flow can be triggered
 * when a Google Ads Lead Form submission creates a contact. The column is an
 * ENUM, so saving such a flow would be rejected under MySQL strict mode without
 * this. Mirrors the existing 'meta_lead_received' trigger.
 */
class AddGoogleLeadFlowTrigger extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `flows`
             MODIFY COLUMN `trigger_type`
             ENUM('lead_created','tag_added','form_submitted','meta_lead_received',
                  'google_lead_received','keyword_reply','inbound_message','order_placed',
                  'order_fulfilled','abandoned_cart','flow_response','date_reached') NOT NULL"
        );
    }

    public function down(): void
    {
        $this->db->query("UPDATE `flows` SET `trigger_type` = 'lead_created' WHERE `trigger_type` = 'google_lead_received'");
        $this->db->query(
            "ALTER TABLE `flows`
             MODIFY COLUMN `trigger_type`
             ENUM('lead_created','tag_added','form_submitted','meta_lead_received',
                  'keyword_reply','inbound_message','order_placed','order_fulfilled',
                  'abandoned_cart','flow_response','date_reached') NOT NULL"
        );
    }
}
