<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Meta ad spend, month on month, from the Marketing API.
 *
 * integrations gains type 'meta_ads': one row per tenant holding the
 * long-lived USER token (ad-account insights need a user or system-user
 * token — a Page token cannot read them) and the ad accounts chosen.
 * ad_spend holds one row per ad account × month as Meta reports it.
 */
class AddMetaAdsSpend extends Migration
{
    private const TYPES_AFTER  = "'meta_lead_ads','google_lead_forms','razorpay_payments','shopify','woocommerce','whatsapp_catalog','ai_assistant','notifications','email_imap','meta_ads'";
    private const TYPES_BEFORE = "'meta_lead_ads','google_lead_forms','razorpay_payments','shopify','woocommerce','whatsapp_catalog','ai_assistant','notifications','email_imap'";

    public function up(): void
    {
        $p = $this->db->DBPrefix;
        if ($this->db->DBDriver !== 'SQLite3') {
            $this->db->query("ALTER TABLE {$p}integrations MODIFY type ENUM(" . self::TYPES_AFTER . ") NOT NULL DEFAULT 'meta_lead_ads'");
        }

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'ad_account_id' => ['type' => 'VARCHAR', 'constraint' => 40],   // act_123…
            'account_name'  => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'month'         => ['type' => 'CHAR', 'constraint' => 7],
            'spend'         => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'impressions'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'clicks'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'reach'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'leads'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'currency'      => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'INR'],
            'fetched_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'ad_account_id', 'month'], 'uq_ad_spend_bucket');
        $this->forge->addKey(['tenant_id', 'month']);
        $this->forge->createTable('ad_spend');
    }

    public function down(): void
    {
        $p = $this->db->DBPrefix;
        $this->forge->dropTable('ad_spend', true);
        if ($this->db->DBDriver !== 'SQLite3') {
            $this->db->query("DELETE FROM {$p}integrations WHERE type = 'meta_ads'");
            $this->db->query("ALTER TABLE {$p}integrations MODIFY type ENUM(" . self::TYPES_BEFORE . ") NOT NULL DEFAULT 'meta_lead_ads'");
        }
    }
}
