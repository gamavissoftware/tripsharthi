<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Allow 'google_lead_forms' as an integration type so Google Ads Lead Form
 * webhook deliveries can be configured per tenant. The `type` column is an ENUM,
 * so an unknown value would be silently coerced to '' under MySQL's non-strict mode.
 */
class AddGoogleLeadFormsIntegrationType extends Migration
{
    public function up(): void
    {
        $p = $this->db->DBPrefix;
        $this->db->query(
            "ALTER TABLE {$p}integrations MODIFY type
             ENUM('meta_lead_ads','google_lead_forms','razorpay_payments','shopify',
                  'woocommerce','whatsapp_catalog','ai_assistant','notifications')
             NOT NULL DEFAULT 'meta_lead_ads'"
        );
    }

    public function down(): void
    {
        $p = $this->db->DBPrefix;
        $this->db->query("DELETE FROM {$p}integrations WHERE type = 'google_lead_forms'");
        $this->db->query(
            "ALTER TABLE {$p}integrations MODIFY type
             ENUM('meta_lead_ads','razorpay_payments','shopify','woocommerce',
                  'whatsapp_catalog','ai_assistant','notifications')
             NOT NULL DEFAULT 'meta_lead_ads'"
        );
    }
}
