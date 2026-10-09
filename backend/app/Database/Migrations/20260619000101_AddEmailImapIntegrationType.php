<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Allow 'email_imap' as an integration type so a tenant can connect a mailbox
 * for inbound-email polling (Phase M) — the fallback for tenants without an
 * inbound-parse webhook provider. Config holds host/port/username/folder/ssl;
 * the password is stored encrypted (TokenCipher) in the config JSON.
 */
class AddEmailImapIntegrationType extends Migration
{
    public function up(): void
    {
        $p = $this->db->DBPrefix;
        $this->db->query(
            "ALTER TABLE {$p}integrations MODIFY type
             ENUM('meta_lead_ads','google_lead_forms','razorpay_payments','shopify',
                  'woocommerce','whatsapp_catalog','ai_assistant','notifications','email_imap')
             NOT NULL DEFAULT 'meta_lead_ads'"
        );
    }

    public function down(): void
    {
        $p = $this->db->DBPrefix;
        $this->db->query("DELETE FROM {$p}integrations WHERE type = 'email_imap'");
        $this->db->query(
            "ALTER TABLE {$p}integrations MODIFY type
             ENUM('meta_lead_ads','google_lead_forms','razorpay_payments','shopify',
                  'woocommerce','whatsapp_catalog','ai_assistant','notifications')
             NOT NULL DEFAULT 'meta_lead_ads'"
        );
    }
}
