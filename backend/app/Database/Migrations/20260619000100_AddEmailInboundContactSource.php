<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Allow 'email_inbound' as a contacts.source so a contact auto-created from an
 * unknown inbound-email sender can be stored (email-only, NULL wa_number).
 * The `source` column is an ENUM; ContactModel's in_list validation is widened
 * to match (see the lead-source ENUM gotcha — both must change together).
 */
class AddEmailInboundContactSource extends Migration
{
    public function up(): void
    {
        $p = $this->db->DBPrefix;
        $this->db->query(
            "ALTER TABLE {$p}contacts MODIFY source
             ENUM('manual','csv_import','web_form','meta_lead_ads','google_lead_forms',
                  'whatsapp_inbound','email_inbound','shopify','woocommerce')
             NOT NULL DEFAULT 'manual'"
        );
    }

    public function down(): void
    {
        $p = $this->db->DBPrefix;
        $this->db->query("UPDATE {$p}contacts SET source = 'manual' WHERE source = 'email_inbound'");
        $this->db->query(
            "ALTER TABLE {$p}contacts MODIFY source
             ENUM('manual','csv_import','web_form','meta_lead_ads','google_lead_forms',
                  'whatsapp_inbound','shopify','woocommerce')
             NOT NULL DEFAULT 'manual'"
        );
    }
}
