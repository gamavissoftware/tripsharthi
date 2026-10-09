<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Stores third-party integrations per tenant.
 * Sprint 5 type: 'meta_lead_ads' (Facebook/Instagram Lead Ads).
 *
 * page_id is a top-level indexed column (not buried in JSON) so the
 * webhook handler can resolve page_id → tenant in O(1) — same pattern
 * as phone_numbers.phone_number_id for the WhatsApp webhook.
 *
 * verify_token is stored as a top-level column so the webhook challenge
 * handler can look it up efficiently.
 */
class CreateIntegrations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'type'         => ['type' => 'ENUM', 'constraint' => ['meta_lead_ads'], 'default' => 'meta_lead_ads'],
            // Facebook Page ID — indexed for O(1) webhook resolution
            'page_id'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            // Random string used for Meta's webhook verification challenge
            'verify_token' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            // {page_access_token_enc, default_country_code}
            'config'       => ['type' => 'JSON',    'null' => true, 'default' => null],
            'status'       => ['type' => 'ENUM', 'constraint' => ['active', 'inactive'], 'default' => 'active'],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        // One active integration of each type per tenant
        $this->forge->addUniqueKey(['tenant_id', 'type']);
        // Webhook resolution: page_id → tenant
        $this->forge->addKey(['type', 'page_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('integrations');
    }

    public function down(): void
    {
        $this->forge->dropTable('integrations', true);
    }
}
