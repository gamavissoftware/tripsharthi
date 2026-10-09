<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Allow 'notifications' as an integration type so reply-alert preferences can be
 * stored per tenant. The `type` column is an ENUM, so an unknown value was being
 * silently coerced to '' under MySQL's non-strict mode.
 */
class AddNotificationsIntegrationType extends Migration
{
    public function up(): void
    {
        $p = $this->db->DBPrefix;
        $this->db->query(
            "ALTER TABLE {$p}integrations MODIFY type
             ENUM('meta_lead_ads','razorpay_payments','shopify','woocommerce',
                  'whatsapp_catalog','ai_assistant','notifications')
             NOT NULL DEFAULT 'meta_lead_ads'"
        );
        // Drop any rows coerced to '' before the enum was widened.
        $this->db->query("DELETE FROM {$p}integrations WHERE type = ''");
    }

    public function down(): void
    {
        $p = $this->db->DBPrefix;
        $this->db->query("DELETE FROM {$p}integrations WHERE type = 'notifications'");
        $this->db->query(
            "ALTER TABLE {$p}integrations MODIFY type
             ENUM('meta_lead_ads','razorpay_payments','shopify','woocommerce',
                  'whatsapp_catalog','ai_assistant')
             NOT NULL DEFAULT 'meta_lead_ads'"
        );
    }
}
