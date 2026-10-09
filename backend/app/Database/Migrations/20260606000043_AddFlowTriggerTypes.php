<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Extends flows.trigger_type ENUM with the P2–P4 trigger types.
 *
 * The flow builder offers (and FlowTriggerService fires) these triggers, but
 * the original ENUM omitted them — so saving such a flow was rejected under
 * MySQL strict mode. This adds: order_placed, order_fulfilled, abandoned_cart
 * (e-commerce), flow_response (WhatsApp Flows), date_reached (birthdays/dates).
 */
class AddFlowTriggerTypes extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `flows`
             MODIFY COLUMN `trigger_type`
             ENUM('lead_created','tag_added','form_submitted','meta_lead_received',
                  'keyword_reply','inbound_message','order_placed','order_fulfilled',
                  'abandoned_cart','flow_response','date_reached') NOT NULL"
        );
    }

    public function down(): void
    {
        $this->db->query(
            "ALTER TABLE `flows`
             MODIFY COLUMN `trigger_type`
             ENUM('lead_created','tag_added','form_submitted','meta_lead_received',
                  'keyword_reply','inbound_message') NOT NULL"
        );
    }
}
