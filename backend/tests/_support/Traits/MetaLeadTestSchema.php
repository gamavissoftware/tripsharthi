<?php

declare(strict_types=1);

namespace Tests\Support\Traits;

/**
 * SQLite in-memory schema for Meta Lead Ads tests.
 * Extends FlowTestSchema with integrations + meta_lead_events tables.
 */
trait MetaLeadTestSchema
{
    use FlowTestSchema; // brings contacts, jobs, flows, flow_runs, etc.

    private function createMetaLeadSchema(): void
    {
        $this->createFlowSchema(); // also truncates all rows from prior tests

        $db = db_connect();
        $p  = $db->DBPrefix;

        $db->query("CREATE TABLE IF NOT EXISTS {$p}integrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            type TEXT DEFAULT 'meta_lead_ads',
            page_id TEXT,
            verify_token TEXT,
            config TEXT,
            status TEXT DEFAULT 'active',
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}meta_lead_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            integration_id INTEGER NOT NULL,
            leadgen_id TEXT NOT NULL,
            status TEXT DEFAULT 'queued',
            contact_id INTEGER,
            error TEXT,
            created_at TEXT, updated_at TEXT,
            UNIQUE(tenant_id, leadgen_id)
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}google_lead_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            integration_id INTEGER NOT NULL,
            lead_id TEXT NOT NULL,
            payload TEXT,
            status TEXT DEFAULT 'queued',
            contact_id INTEGER,
            created_at TEXT, updated_at TEXT,
            UNIQUE(tenant_id, lead_id)
        )");

        // Minimal campaigns table — needed by OrphanedRowSweeper descriptor
        $db->query("CREATE TABLE IF NOT EXISTS {$p}campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            template_id INTEGER,
            name TEXT,
            segment TEXT,
            scheduled_at TEXT,
            status TEXT DEFAULT 'draft',
            sent_count INTEGER DEFAULT 0, failed_count INTEGER DEFAULT 0,
            stats TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        // lead_imports — needed by OrphanedRowSweeper descriptor. Columns mirror the
        // real schema (and JobHandlerPortTest's) so a shared-SQLite CREATE-IF-NOT-EXISTS
        // race between traits can't leave a divergent column set.
        $db->query("CREATE TABLE IF NOT EXISTS {$p}lead_imports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            original_filename TEXT, stored_filename TEXT,
            default_country_code TEXT, headers TEXT, mapping TEXT,
            total INTEGER DEFAULT 0, imported INTEGER DEFAULT 0,
            updated_count INTEGER DEFAULT 0, failed INTEGER DEFAULT 0,
            errors TEXT, cursor INTEGER DEFAULT 0,
            status TEXT DEFAULT 'pending',
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        // Clean these tables too
        $db->query("DELETE FROM {$p}meta_lead_events");
        $db->query("DELETE FROM {$p}google_lead_events");
        $db->query("DELETE FROM {$p}integrations");
        $db->query("DELETE FROM {$p}campaigns");
        $db->query("DELETE FROM {$p}lead_imports");
    }

    protected function seedIntegration(int $tenantId, string $pageId, int $id = 0): int
    {
        $data = [
            'tenant_id'    => $tenantId,
            'type'         => 'meta_lead_ads',
            'page_id'      => $pageId,
            'verify_token' => 'test_verify_token_' . $pageId,
            'config'       => json_encode([
                'page_access_token_enc' => 'mock_encrypted_token',
                'default_country_code'  => '+91',
            ]),
            'status'       => 'active',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        db_connect()->table('integrations')->insert($data);
        return (int) db_connect()->insertID();
    }

    protected function seedGoogleIntegration(int $tenantId, string $googleKey): int
    {
        $data = [
            'tenant_id'    => $tenantId,
            'type'         => 'google_lead_forms',
            // verify_token stores the SHA-256 hash of the key (replay-safe lookup),
            // matching IntegrationModel::findByGoogleKey() which hashes the input.
            'verify_token' => \App\Models\IntegrationModel::hashGoogleKey($googleKey),
            'config'       => json_encode(['default_country_code' => '+91']),
            'status'       => 'active',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        db_connect()->table('integrations')->insert($data);
        return (int) db_connect()->insertID();
    }
}
