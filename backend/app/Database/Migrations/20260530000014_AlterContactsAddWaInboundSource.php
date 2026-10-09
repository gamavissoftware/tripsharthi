<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds 'whatsapp_inbound' to contacts.source ENUM.
 *
 * Contacts auto-created from inbound WhatsApp messages use this source
 * so they can be filtered separately in the contacts list.
 */
class AlterContactsAddWaInboundSource extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `contacts`
             MODIFY COLUMN `source`
             ENUM('manual','csv_import','web_form','meta_lead_ads','whatsapp_inbound')
             NOT NULL DEFAULT 'manual'"
        );
    }

    public function down(): void
    {
        $this->db->query(
            "ALTER TABLE `contacts`
             MODIFY COLUMN `source`
             ENUM('manual','csv_import','web_form','meta_lead_ads')
             NOT NULL DEFAULT 'manual'"
        );
    }
}
