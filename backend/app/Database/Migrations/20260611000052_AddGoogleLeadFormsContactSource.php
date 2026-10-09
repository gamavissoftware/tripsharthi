<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Allow 'google_lead_forms' as a contacts.source value so leads ingested from
 * the Google Ads Lead Forms webhook can be stored. The `source` column is an
 * ENUM, so an unknown value would be rejected (strict) or coerced to '' — and
 * ContactModel's in_list validation already gates the same set.
 */
class AddGoogleLeadFormsContactSource extends Migration
{
    public function up(): void
    {
        $p = $this->db->DBPrefix;
        $this->db->query(
            "ALTER TABLE {$p}contacts MODIFY source
             ENUM('manual','csv_import','web_form','meta_lead_ads','google_lead_forms',
                  'whatsapp_inbound','shopify','woocommerce')
             NOT NULL DEFAULT 'manual'"
        );
    }

    public function down(): void
    {
        $p = $this->db->DBPrefix;
        $this->db->query("UPDATE {$p}contacts SET source = 'manual' WHERE source = 'google_lead_forms'");
        $this->db->query(
            "ALTER TABLE {$p}contacts MODIFY source
             ENUM('manual','csv_import','web_form','meta_lead_ads',
                  'whatsapp_inbound','shopify','woocommerce')
             NOT NULL DEFAULT 'manual'"
        );
    }
}
