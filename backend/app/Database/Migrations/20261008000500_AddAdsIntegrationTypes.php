<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** 'meta_capi' (pixel/dataset + token) and 'google_ads' (OAuth + conversion actions) integration types. */
class AddAdsIntegrationTypes extends Migration
{
    private const BEFORE = "'meta_lead_ads','google_lead_forms','razorpay_payments','shopify','woocommerce','whatsapp_catalog','ai_assistant','notifications','email_imap','meta_ads','email_smtp'";
    private const AFTER  = self::BEFORE . ",'meta_capi','google_ads'";

    public function up(): void
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query("ALTER TABLE {$this->db->DBPrefix}integrations MODIFY type ENUM(" . self::AFTER . ") NOT NULL DEFAULT 'meta_lead_ads'");
        }
    }

    public function down(): void
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $p = $this->db->DBPrefix;
            $this->db->query("DELETE FROM {$p}integrations WHERE type IN ('meta_capi','google_ads')");
            $this->db->query("ALTER TABLE {$p}integrations MODIFY type ENUM(" . self::BEFORE . ") NOT NULL DEFAULT 'meta_lead_ads'");
        }
    }
}
