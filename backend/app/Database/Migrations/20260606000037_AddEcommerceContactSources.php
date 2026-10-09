<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds 'shopify' and 'woocommerce' to contacts.source ENUM (P2.3 / P2.4) so
 * contacts created from store webhooks can be filtered by origin.
 */
class AddEcommerceContactSources extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `contacts`
             MODIFY COLUMN `source`
             ENUM('manual','csv_import','web_form','meta_lead_ads','whatsapp_inbound','shopify','woocommerce')
             NOT NULL DEFAULT 'manual'"
        );
    }

    public function down(): void
    {
        $this->db->query(
            "ALTER TABLE `contacts`
             MODIFY COLUMN `source`
             ENUM('manual','csv_import','web_form','meta_lead_ads','whatsapp_inbound')
             NOT NULL DEFAULT 'manual'"
        );
    }
}
