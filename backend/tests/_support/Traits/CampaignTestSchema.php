<?php

declare(strict_types=1);

namespace Tests\Support\Traits;

/**
 * Shared SQLite3 schema for campaign-related tests.
 *
 * Creates all tables needed by CampaignSender in the :memory: test DB.
 * Uses IF NOT EXISTS so the trait can be included in multiple test classes
 * within the same PHPUnit process.
 */
trait CampaignTestSchema
{
    private function createCampaignSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;

        // Order-independence: start from a blank DB so no table/rows from a prior
        // test class (which may use a different schema trait, with divergent
        // columns) can leak in. The :memory: connection is shared across classes.
        foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->getResultArray() as $row) {
            $db->query('DROP TABLE IF EXISTS "' . $row['name'] . '"');
        }

        // Ensure tables exist first (idempotent CREATE IF NOT EXISTS),
        // then wipe rows so each test starts with a clean slate.
        // The SQLite :memory: DB is shared across test classes in the same
        // PHPUnit process, so explicit cleanup is required.
        $this->ensureCampaignTables($db, $p);
        $this->truncateCampaignTables($db, $p);
    }

    private function truncateCampaignTables(\CodeIgniter\Database\BaseConnection $db, string $p): void
    {
        // SQLite supports DELETE FROM, not TRUNCATE. Delete in FK-safe order.
        foreach ([
            'messages', 'conversations', 'campaigns', 'templates',
            'contact_field_values', 'custom_fields', 'contacts',
            'phone_numbers', 'waba_accounts', 'tenants',
        ] as $table) {
            $db->query("DELETE FROM {$p}{$table}");
        }

        // Re-seed the baseline rows every test needs
        $db->query("INSERT INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");
        $db->query("INSERT INTO {$p}waba_accounts
            (id, tenant_id, waba_id, access_token_enc, display_name, status, created_at, updated_at)
            VALUES (1, 1, 'mock_waba', 'mock_token_enc', 'Test WABA', 'active', datetime('now'), datetime('now'))");
        $db->query("INSERT INTO {$p}phone_numbers
            (id, waba_account_id, tenant_id, phone_number_id, display_number, is_default, created_at, updated_at)
            VALUES (1, 1, 1, 'mock_phone_id', '+919999900000', 1, datetime('now'), datetime('now'))");
    }

    private function ensureCampaignTables(\CodeIgniter\Database\BaseConnection $db, string $p): void
    {

        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (
            id INTEGER PRIMARY KEY,
            name TEXT, slug TEXT, plan TEXT DEFAULT 'free',
            status TEXT DEFAULT 'active', mode TEXT DEFAULT 'saas',
            settings TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, wa_number TEXT,
            name TEXT, email TEXT, language TEXT, status TEXT DEFAULT 'new',
            source TEXT DEFAULT 'manual', opt_in INTEGER DEFAULT 1,
            last_inbound_at TEXT,
            account_id INTEGER, owner_id INTEGER, job_title TEXT,
            lifecycle_stage TEXT DEFAULT 'lead', lead_score INTEGER DEFAULT 0, phone_secondary TEXT,
            score_tier TEXT, score_breakdown TEXT,
            city TEXT, state TEXT, country TEXT, business_type TEXT, requirement_type TEXT,
            current_process TEXT, budget_amount INTEGER, timeline TEXT, qualification_status TEXT,
            ai_call_status TEXT, priority TEXT DEFAULT 'medium', remarks TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT,
            UNIQUE(tenant_id, wa_number)
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}custom_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER, label TEXT, field_key TEXT, type TEXT DEFAULT 'text',
            options TEXT, is_required INTEGER DEFAULT 0, restricted INTEGER DEFAULT 0,
            sort_order INTEGER DEFAULT 0,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}contact_field_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER, custom_field_id INTEGER, value TEXT,
            created_at TEXT, updated_at TEXT,
            UNIQUE(contact_id, custom_field_id)
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, contact_id INTEGER,
            phone_number_id INTEGER, wa_number TEXT NOT NULL,
            contact_name TEXT, window_expires_at TEXT,
            last_message_at TEXT, last_inbound_at TEXT,
            unread_count INTEGER DEFAULT 0, status TEXT DEFAULT 'open',
            assigned_user_id INTEGER, handoff_at TEXT,
            created_at TEXT, updated_at TEXT,
            UNIQUE(tenant_id, wa_number)
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, contact_id INTEGER,
            conversation_id INTEGER NOT NULL, campaign_id INTEGER, template_id INTEGER, variant TEXT,
            direction TEXT NOT NULL,
            type TEXT DEFAULT 'text', category TEXT, body TEXT,
            media_url TEXT, wa_message_id TEXT, status TEXT DEFAULT 'queued',
            billable INTEGER DEFAULT 0, error TEXT,
            sent_at TEXT, delivered_at TEXT, read_at TEXT,
            created_at TEXT, updated_at TEXT
        )");
        // Mirror production: one message per (campaign_id, contact_id) so the
        // campaign-send idempotency (insert-before-send) is exercised in tests.
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS {$p}messages_campaign_contact_uniq
            ON {$p}messages (campaign_id, contact_id)");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, name TEXT NOT NULL,
            display_name TEXT, language TEXT DEFAULT 'en',
            category TEXT DEFAULT 'marketing',
            header_type TEXT DEFAULT 'none', header_content TEXT,
            body TEXT, footer TEXT, buttons TEXT, variables TEXT,
            meta_template_id TEXT, meta_status TEXT DEFAULT 'draft',
            rejection_reason TEXT, submitted_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, template_id INTEGER NOT NULL,
            variant_template_id INTEGER, ab_split INTEGER DEFAULT 0,
            name TEXT NOT NULL, segment TEXT, variable_mapping TEXT,
            variable_defaults TEXT, status TEXT DEFAULT 'draft',
            scheduled_at TEXT, cursor INTEGER DEFAULT 0,
            total_contacts INTEGER DEFAULT 0, sent_count INTEGER DEFAULT 0,
            failed_count INTEGER DEFAULT 0, stats TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}waba_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER, waba_id TEXT, business_id TEXT,
            access_token_enc TEXT, display_name TEXT, verify_token TEXT,
            status TEXT DEFAULT 'active',
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}phone_numbers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            waba_account_id INTEGER, tenant_id INTEGER,
            phone_number_id TEXT, display_number TEXT,
            quality_rating TEXT DEFAULT 'unknown', is_default INTEGER DEFAULT 1,
            created_at TEXT, updated_at TEXT
        )");

    }

    private function seedApprovedTemplate(array $overrides = []): int
    {
        $p = db_connect()->DBPrefix;
        db_connect()->table('templates')->insert(array_merge([
            'tenant_id'        => 1,
            'name'             => 'test_template',
            'display_name'     => 'Test Template',
            'language'         => 'en',
            'category'         => 'marketing',
            'header_type'      => 'none',
            'body'             => 'Hi {{1}}, welcome!',
            'meta_template_id' => 'mock_meta_id',
            'meta_status'      => 'approved',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ], $overrides));
        return (int) db_connect()->insertID();
    }

    private function seedCampaign(int $templateId, array $overrides = []): int
    {
        db_connect()->table('campaigns')->insert(array_merge([
            'tenant_id'   => 1,
            'template_id' => $templateId,
            'name'        => 'Test Campaign',
            'segment'     => json_encode(['all' => true]),
            'status'      => 'draft',
            'cursor'      => 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], $overrides));
        return (int) db_connect()->insertID();
    }

    private function seedContact(string $waNumber, array $overrides = []): int
    {
        db_connect()->table('contacts')->insert(array_merge([
            'tenant_id'  => 1,
            'wa_number'  => $waNumber,
            'name'       => 'Test Contact',
            'opt_in'     => 1,
            'status'     => 'new',
            'source'     => 'manual',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides));
        return (int) db_connect()->insertID();
    }
}
