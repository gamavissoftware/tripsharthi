<?php

declare(strict_types=1);

namespace Tests\Support\Traits;

/**
 * SQLite in-memory schema for CRM (Phase A) tests. Extends FlowTestSchema with
 * the new CRM tables and the CRM columns on contacts.
 */
trait CrmTestSchema
{
    use FlowTestSchema; // contacts, conversations, messages, jobs, tenants, etc.

    private function createCrmSchema(): void
    {
        $this->createFlowSchema(); // also truncates rows from prior tests

        $db = db_connect();
        $p  = $db->DBPrefix;

        // CRM columns on contacts (idempotent — table is shared across traits).
        // Self-sufficient: include EVERY CRM column the CRM tests touch, so this
        // schema never depends on which trait created the shared `contacts` table
        // first (running tests in random order otherwise surfaces missing columns).
        foreach ([
            'account_id INTEGER',
            'owner_id INTEGER',
            'job_title TEXT',
            "lifecycle_stage TEXT DEFAULT 'lead'",
            'lead_score INTEGER DEFAULT 0',
            'phone_secondary TEXT',
            'score_tier TEXT',
            'score_breakdown TEXT',
        ] as $col) {
            try { $db->query("ALTER TABLE {$p}contacts ADD COLUMN {$col}"); } catch (\Throwable $e) { /* already added */ }
        }

        $db->query("CREATE TABLE IF NOT EXISTS {$p}accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            name TEXT, domain TEXT, industry TEXT, type TEXT DEFAULT 'prospect',
            owner_id INTEGER, phone TEXT, website TEXT, address_line TEXT, city TEXT,
            state TEXT, country TEXT, postal_code TEXT, annual_revenue INTEGER,
            employee_count INTEGER, parent_account_id INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}activities (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            type TEXT, subject TEXT, body TEXT, related_type TEXT, related_id INTEGER,
            actor_user_id INTEGER, meta TEXT, occurred_at TEXT, created_at TEXT, updated_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            body TEXT, related_type TEXT, related_id INTEGER, is_pinned INTEGER DEFAULT 0,
            created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            title TEXT, description TEXT, type TEXT DEFAULT 'todo', status TEXT DEFAULT 'open',
            priority TEXT DEFAULT 'medium', due_at TEXT, reminder_at TEXT, reminder_sent INTEGER DEFAULT 0, assigned_user_id INTEGER,
            related_type TEXT, related_id INTEGER, created_by INTEGER, completed_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        // ── Phase B: pipelines, stages, deals ─────────────────────────
        $db->query("CREATE TABLE IF NOT EXISTS {$p}pipelines (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            name TEXT, entity_type TEXT DEFAULT 'deal', is_default INTEGER DEFAULT 0,
            position INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}pipeline_stages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, pipeline_id INTEGER,
            name TEXT, position INTEGER DEFAULT 0, probability INTEGER DEFAULT 0, rotting_days INTEGER,
            is_won INTEGER DEFAULT 0, is_lost INTEGER DEFAULT 0,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}deals (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            title TEXT, pipeline_id INTEGER, stage_id INTEGER, account_id INTEGER,
            primary_contact_id INTEGER, owner_id INTEGER, value_amount INTEGER DEFAULT 0,
            is_recurring INTEGER DEFAULT 0, recurring_interval TEXT, next_renewal_at TEXT,
            currency TEXT DEFAULT 'INR', price_book_id INTEGER, expected_close_date TEXT, status TEXT DEFAULT 'open',
            source TEXT, lost_reason TEXT, won_at TEXT, last_activity_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}deal_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            deal_id INTEGER, contact_id INTEGER, role TEXT, created_at TEXT, updated_at TEXT,
            UNIQUE(deal_id, contact_id)
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}deal_line_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            deal_id INTEGER, product_id INTEGER, name TEXT, quantity INTEGER DEFAULT 1,
            unit_price INTEGER DEFAULT 0, discount_pct INTEGER DEFAULT 0, tax_pct INTEGER DEFAULT 0,
            total INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            subject TEXT, description TEXT, contact_id INTEGER, account_id INTEGER,
            status TEXT DEFAULT 'open', priority TEXT DEFAULT 'medium', owner_id INTEGER,
            source TEXT DEFAULT 'manual', category TEXT, conversation_id INTEGER,
            sla_due_at TEXT, escalated_at TEXT, resolved_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}products (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            retailer_id TEXT, name TEXT, description TEXT, price_paise INTEGER DEFAULT 0,
            currency TEXT DEFAULT 'INR', image_url TEXT, availability TEXT, meta_product_id TEXT, status TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}price_books (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            name TEXT, currency TEXT DEFAULT 'INR', is_default INTEGER DEFAULT 0,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}price_book_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            price_book_id INTEGER, product_id INTEGER, price_paise INTEGER DEFAULT 0,
            created_at TEXT, updated_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER,
            type TEXT DEFAULT 'mention', body TEXT, link TEXT, read_at TEXT, created_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}meetings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, title TEXT,
            contact_id INTEGER, deal_id INTEGER, owner_id INTEGER,
            start_at TEXT, end_at TEXT, location TEXT, notes TEXT, status TEXT DEFAULT 'scheduled',
            reminder_sent INTEGER DEFAULT 0,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, contact_id INTEGER,
            deal_id INTEGER, direction TEXT DEFAULT 'out', from_email TEXT, to_email TEXT,
            subject TEXT, body TEXT, status TEXT DEFAULT 'queued', error TEXT, sent_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}integrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, type TEXT,
            page_id TEXT, verify_token TEXT, config TEXT, status TEXT DEFAULT 'active',
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, name TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}team_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, team_id INTEGER, user_id INTEGER,
            created_at TEXT, updated_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}record_shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            entity_type TEXT, entity_id INTEGER, grantee_type TEXT, grantee_id INTEGER,
            access TEXT DEFAULT 'edit', created_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, actor_user_id INTEGER,
            action TEXT, entity_type TEXT, entity_id INTEGER, before TEXT, after TEXT, ip TEXT, created_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}business_hours (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            start_hour INTEGER DEFAULT 9, end_hour INTEGER DEFAULT 18, workdays TEXT DEFAULT '1,2,3,4,5',
            created_at TEXT, updated_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}dashboards (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            name TEXT, is_default INTEGER DEFAULT 0, position INTEGER DEFAULT 0,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}dashboard_widgets (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, dashboard_id INTEGER,
            type TEXT DEFAULT 'kpi', title TEXT, config TEXT, position INTEGER DEFAULT 0, width INTEGER DEFAULT 6,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}sales_targets (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER,
            metric TEXT DEFAULT 'won_value', period_start TEXT, period_end TEXT, target_amount INTEGER DEFAULT 0,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        // ── Phase E: custom objects + associations ────────────────────
        $db->query("CREATE TABLE IF NOT EXISTS {$p}custom_objects (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            label_singular TEXT, label_plural TEXT, api_name TEXT, icon TEXT, color TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT, UNIQUE(tenant_id, api_name)
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}custom_object_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, custom_object_id INTEGER,
            field_key TEXT, label TEXT, type TEXT DEFAULT 'text', options TEXT, required INTEGER DEFAULT 0,
            position INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}custom_object_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, custom_object_id INTEGER,
            name TEXT, owner_id INTEGER, data TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}associations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            from_type TEXT, from_id INTEGER, to_type TEXT, to_id INTEGER, label TEXT,
            created_at TEXT, updated_at TEXT, UNIQUE(tenant_id, from_type, from_id, to_type, to_id)
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}quotes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, deal_id INTEGER,
            number TEXT, status TEXT DEFAULT 'draft', valid_until TEXT, currency TEXT DEFAULT 'INR',
            subtotal INTEGER DEFAULT 0, total INTEGER DEFAULT 0, items TEXT, notes TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            related_type TEXT, related_id INTEGER, filename TEXT, stored_path TEXT,
            mime TEXT, size_bytes INTEGER DEFAULT 0, uploaded_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}saved_views (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            entity_type TEXT, name TEXT, filters TEXT, view_columns TEXT, sort TEXT,
            is_shared INTEGER DEFAULT 0, owner_id INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            name TEXT, color TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            name TEXT, email TEXT, password_hash TEXT, role TEXT DEFAULT 'agent',
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}assignment_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            entity_type TEXT, name TEXT, match_type TEXT DEFAULT 'any', match_field TEXT, match_value TEXT,
            strategy TEXT DEFAULT 'round_robin', assigned_user_id INTEGER, pool TEXT, capacity INTEGER DEFAULT 50,
            last_assigned_user_id INTEGER, sort_order INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}lead_scoring_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            weights TEXT, hot_threshold INTEGER DEFAULT 65, warm_threshold INTEGER DEFAULT 40,
            created_at TEXT, updated_at TEXT
        )");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}lead_imports (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            entity_type TEXT DEFAULT 'contact', custom_object_id INTEGER,
            original_filename TEXT, stored_filename TEXT, default_country_code TEXT,
            headers TEXT, mapping TEXT, total INTEGER DEFAULT 0, imported INTEGER DEFAULT 0,
            updated_count INTEGER DEFAULT 0, failed INTEGER DEFAULT 0, errors TEXT,
            cursor INTEGER DEFAULT 0, status TEXT DEFAULT 'pending',
            created_at TEXT, updated_at TEXT
        )");

        foreach (['accounts', 'activities', 'notes', 'tasks', 'pipelines', 'pipeline_stages', 'deals', 'deal_contacts', 'deal_line_items', 'tickets', 'custom_objects', 'custom_object_fields', 'custom_object_records', 'associations', 'quotes', 'saved_views', 'tags', 'contact_tags', 'lead_imports', 'lead_scoring_rules', 'users', 'assignment_rules', 'business_hours', 'dashboards', 'dashboard_widgets', 'sales_targets', 'products', 'price_books', 'price_book_entries', 'audit_logs', 'teams', 'team_members', 'notifications', 'meetings', 'emails', 'integrations'] as $t) {
            $db->query("DELETE FROM {$p}{$t}");
        }

        // A second tenant for isolation tests.
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (2, 'Tenant2', 'tenant2')");
    }
}
