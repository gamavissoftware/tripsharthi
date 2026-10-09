<?php

declare(strict_types=1);

namespace Tests\Support\Traits;

use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\WindowService;
use App\Models\ConversationModel;

/**
 * SQLite in-memory schema for flow engine tests.
 * Wipes all rows before each test for isolation.
 */
trait FlowTestSchema
{
    /** Drop every user table so a test class starts from a truly blank DB. */
    private function resetDatabase(\CodeIgniter\Database\BaseConnection $db): void
    {
        foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->getResultArray() as $row) {
            $db->query('DROP TABLE IF EXISTS "' . $row['name'] . '"');
        }
    }

    private function createFlowSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;

        // Order-independence: drop every existing table so this test class builds
        // its exact schema on a blank DB. The :memory: connection is shared across
        // ALL test classes, so a table/rows left by a class using a different
        // schema trait would otherwise leak in (missing columns, stale rows) and
        // make the suite pass only in a lucky order. CrmTestSchema and
        // MetaLeadTestSchema delegate here, so this resets them too.
        $this->resetDatabase($db);

        $tables = [
            "tenants" => "CREATE TABLE IF NOT EXISTS {$p}tenants (
                id INTEGER PRIMARY KEY, name TEXT, slug TEXT,
                plan TEXT DEFAULT 'free', status TEXT DEFAULT 'active',
                mode TEXT DEFAULT 'saas', record_visibility TEXT DEFAULT 'open',
                settings TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)",

            "contacts" => "CREATE TABLE IF NOT EXISTS {$p}contacts (
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
                UNIQUE(tenant_id, wa_number))",

            "accounts" => "CREATE TABLE IF NOT EXISTS {$p}accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER, name TEXT, domain TEXT, industry TEXT, type TEXT, owner_id INTEGER,
                created_at TEXT, updated_at TEXT, deleted_at TEXT)",

            "contact_tags" => "CREATE TABLE IF NOT EXISTS {$p}contact_tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_id INTEGER, tag_id INTEGER, created_at TEXT,
                UNIQUE(contact_id, tag_id))",

            "custom_fields" => "CREATE TABLE IF NOT EXISTS {$p}custom_fields (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER, label TEXT, field_key TEXT,
                type TEXT DEFAULT 'text', options TEXT,
                is_required INTEGER DEFAULT 0, restricted INTEGER DEFAULT 0,
                sort_order INTEGER DEFAULT 0,
                created_at TEXT, updated_at TEXT, deleted_at TEXT)",

            "contact_field_values" => "CREATE TABLE IF NOT EXISTS {$p}contact_field_values (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_id INTEGER, custom_field_id INTEGER, value TEXT,
                created_at TEXT, updated_at TEXT,
                UNIQUE(contact_id, custom_field_id))",

            "conversations" => "CREATE TABLE IF NOT EXISTS {$p}conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL, contact_id INTEGER,
                phone_number_id INTEGER, wa_number TEXT NOT NULL,
                contact_name TEXT, window_expires_at TEXT,
                last_message_at TEXT, last_inbound_at TEXT,
                unread_count INTEGER DEFAULT 0, is_read INTEGER DEFAULT 0,
                status TEXT DEFAULT 'open',
                assigned_user_id INTEGER, handoff_at TEXT,
                created_at TEXT, updated_at TEXT,
                UNIQUE(tenant_id, wa_number))",

            "messages" => "CREATE TABLE IF NOT EXISTS {$p}messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL, contact_id INTEGER,
                conversation_id INTEGER NOT NULL, campaign_id INTEGER, template_id INTEGER, variant TEXT,
                direction TEXT NOT NULL,
                type TEXT DEFAULT 'text', category TEXT, body TEXT,
                media_url TEXT, wa_message_id TEXT,
                status TEXT DEFAULT 'queued', billable INTEGER DEFAULT 0,
                error TEXT, sent_at TEXT, delivered_at TEXT, read_at TEXT,
                created_at TEXT, updated_at TEXT)",

            "templates" => "CREATE TABLE IF NOT EXISTS {$p}templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL, name TEXT, display_name TEXT,
                language TEXT DEFAULT 'en', category TEXT DEFAULT 'marketing',
                header_type TEXT DEFAULT 'none', header_content TEXT,
                body TEXT, footer TEXT, buttons TEXT, variables TEXT,
                meta_template_id TEXT, meta_status TEXT DEFAULT 'draft',
                rejection_reason TEXT, submitted_at TEXT,
                created_at TEXT, updated_at TEXT, deleted_at TEXT)",

            "flows" => "CREATE TABLE IF NOT EXISTS {$p}flows (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL, name TEXT,
                status TEXT DEFAULT 'draft',
                trigger_type TEXT, trigger_config TEXT, graph TEXT,
                reentry_policy TEXT DEFAULT 'once',
                version INTEGER DEFAULT 1, stats TEXT,
                created_at TEXT, updated_at TEXT, deleted_at TEXT)",

            "flow_runs" => "CREATE TABLE IF NOT EXISTS {$p}flow_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL, flow_id INTEGER, contact_id INTEGER,
                current_node_id TEXT, status TEXT DEFAULT 'running',
                state TEXT, graph_snapshot TEXT, next_run_at TEXT,
                steps_executed INTEGER DEFAULT 0, is_test INTEGER DEFAULT 0,
                entered_at TEXT, completed_at TEXT,
                created_at TEXT, updated_at TEXT, deleted_at TEXT)",

            "flow_run_logs" => "CREATE TABLE IF NOT EXISTS {$p}flow_run_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                flow_run_id INTEGER, node_id TEXT, node_type TEXT,
                result TEXT, detail TEXT, created_at TEXT)",

            "jobs" => "CREATE TABLE IF NOT EXISTS {$p}jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL, type TEXT,
                source_type TEXT, source_id INTEGER,
                payload TEXT,
                run_at TEXT NOT NULL, status TEXT DEFAULT 'pending',
                attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 5,
                locked_at TEXT, locked_by TEXT, last_error TEXT,
                created_at TEXT, updated_at TEXT)",

            "waba_accounts" => "CREATE TABLE IF NOT EXISTS {$p}waba_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER, waba_id TEXT, business_id TEXT,
                access_token_enc TEXT, display_name TEXT, verify_token TEXT,
                status TEXT DEFAULT 'active',
                created_at TEXT, updated_at TEXT, deleted_at TEXT)",

            "phone_numbers" => "CREATE TABLE IF NOT EXISTS {$p}phone_numbers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                waba_account_id INTEGER, tenant_id INTEGER,
                phone_number_id TEXT, display_number TEXT,
                quality_rating TEXT DEFAULT 'unknown', is_default INTEGER DEFAULT 1,
                created_at TEXT, updated_at TEXT)",
        ];

        foreach ($tables as $sql) {
            $db->query($sql);
        }

        // Purge all rows — shared :memory: DB persists between tests
        $delete = array_reverse(array_keys($tables));
        foreach ($delete as $tbl) {
            $db->query("DELETE FROM {$p}{$tbl}");
        }

        // Baseline seed
        $db->query("INSERT INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");
        $db->query("INSERT INTO {$p}waba_accounts
            (id, tenant_id, waba_id, access_token_enc, display_name, status, created_at, updated_at)
            VALUES (1, 1, 'mock_waba', 'mock_enc', 'Test', 'active', datetime('now'), datetime('now'))");
        $db->query("INSERT INTO {$p}phone_numbers
            (id, waba_account_id, tenant_id, phone_number_id, display_number, is_default, created_at, updated_at)
            VALUES (1, 1, 1, 'mock_phone_id', '+919999900000', 1, datetime('now'), datetime('now'))");
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

    private function seedApprovedTemplate(array $overrides = []): int
    {
        db_connect()->table('templates')->insert(array_merge([
            'tenant_id'        => 1,
            'name'             => 'test_template',
            'display_name'     => 'Test Template',
            'language'         => 'en',
            'category'         => 'marketing',
            'header_type'      => 'none',
            'body'             => 'Hi {{1}}!',
            'meta_template_id' => 'mock_meta_id',
            'meta_status'      => 'approved',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ], $overrides));
        return (int) db_connect()->insertID();
    }

    private function seedFlow(array $graph, string $triggerType = 'lead_created', array $overrides = []): int
    {
        db_connect()->table('flows')->insert(array_merge([
            'tenant_id'      => 1,
            'name'           => 'Test Flow',
            'status'         => 'active',
            'trigger_type'   => $triggerType,
            'graph'          => json_encode($graph),
            'reentry_policy' => 'once',
            'version'        => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $overrides));
        return (int) db_connect()->insertID();
    }

    private function seedRun(int $flowId, int $contactId, array $graph, string $nodeId, array $overrides = []): int
    {
        db_connect()->table('flow_runs')->insert(array_merge([
            'tenant_id'       => 1,
            'flow_id'         => $flowId,
            'contact_id'      => $contactId,
            'current_node_id' => $nodeId,
            'status'          => 'running',
            'state'           => '{}',
            'graph_snapshot'  => json_encode($graph),
            'steps_executed'  => 0,
            'entered_at'      => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ], $overrides));
        return (int) db_connect()->insertID();
    }

    /** Build a minimal linear graph: trigger → ...nodes */
    private function makeGraph(array $nodes, array $edges): array
    {
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    private function mockEngine(): CloudApiClient
    {
        return new CloudApiClient('mock_phone', 'mock_token');
    }

    private function mockWindowService(bool $windowOpen, ?int $now = null): WindowService
    {
        $fixed = $now ?? time();
        $expiry = $windowOpen
            ? date('Y-m-d H:i:s', $fixed + 86400)
            : null;

        // Pre-seed a conversation with the right window state
        // Callers set wa_number accordingly
        return new WindowService(new ConversationModel(), $fixed);
    }
}
