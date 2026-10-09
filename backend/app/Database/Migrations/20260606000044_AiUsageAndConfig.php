<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AI metering + per-tenant key (#3).
 *
 * - ai_usage: monthly per-tenant counter of AI generations, enforced against
 *   the plan's ai_replies limit.
 * - integrations gains 'ai_assistant' so a tenant can store their OWN Anthropic
 *   key (encrypted); absent → falls back to the platform ANTHROPIC_API_KEY.
 */
class AiUsageAndConfig extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('integrations', [
            'type' => [
                'name'       => 'type',
                'type'       => 'ENUM',
                'constraint' => ['meta_lead_ads', 'razorpay_payments', 'shopify', 'woocommerce', 'whatsapp_catalog', 'ai_assistant'],
                'default'    => 'meta_lead_ads',
            ],
        ]);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'period'     => ['type' => 'VARCHAR', 'constraint' => 7], // YYYY-MM
            'used'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'period']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ai_usage');
    }

    public function down(): void
    {
        $this->forge->dropTable('ai_usage', true);

        $this->forge->modifyColumn('integrations', [
            'type' => [
                'name'       => 'type',
                'type'       => 'ENUM',
                'constraint' => ['meta_lead_ads', 'razorpay_payments', 'shopify', 'woocommerce', 'whatsapp_catalog'],
                'default'    => 'meta_lead_ads',
            ],
        ]);
    }
}
