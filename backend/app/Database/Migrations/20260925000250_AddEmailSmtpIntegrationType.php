<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Allow 'email_smtp' as an integration type.
 *
 * EmailsController::saveConfig has written type='email_smtp' since the email
 * channel shipped, but the ENUM never listed it. CodeIgniter's MySQL session
 * is not strict, so MySQL stored '' instead of refusing — the save reported
 * success and every later lookup by type found nothing, which is why tenant
 * SMTP settings never took effect. Email marketing depends on them.
 *
 * Rows already stored with an empty type AND an SMTP-shaped config (host +
 * from_email) are repaired. Every other type is in the ENUM, so '' can only
 * have come from this save.
 */
class AddEmailSmtpIntegrationType extends Migration
{
    private const TYPES_AFTER  = "'meta_lead_ads','google_lead_forms','razorpay_payments','shopify','woocommerce','whatsapp_catalog','ai_assistant','notifications','email_imap','meta_ads','email_smtp'";
    private const TYPES_BEFORE = "'meta_lead_ads','google_lead_forms','razorpay_payments','shopify','woocommerce','whatsapp_catalog','ai_assistant','notifications','email_imap','meta_ads'";

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }
        $p = $this->db->DBPrefix;

        // Widen first while '' rows still exist: the ENUM keeps '' as its
        // error value, so they survive the MODIFY and can then be repaired.
        $this->db->query("ALTER TABLE {$p}integrations MODIFY type ENUM(" . self::TYPES_AFTER . ") NOT NULL DEFAULT 'meta_lead_ads'");

        $this->db->query("UPDATE {$p}integrations SET type = 'email_smtp'
            WHERE type = '' AND JSON_VALID(config)
              AND JSON_EXTRACT(config, '$.host') IS NOT NULL
              AND JSON_EXTRACT(config, '$.from_email') IS NOT NULL");
    }

    public function down(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }
        $p = $this->db->DBPrefix;
        $this->db->query("DELETE FROM {$p}integrations WHERE type = 'email_smtp'");
        $this->db->query("ALTER TABLE {$p}integrations MODIFY type ENUM(" . self::TYPES_BEFORE . ") NOT NULL DEFAULT 'meta_lead_ads'");
    }
}
