<?php

declare(strict_types=1);

namespace Tests\Support\Traits;

/**
 * SQLite schema for email marketing tests: the CRM schema (contacts, emails,
 * integrations, activities, jobs, …) plus the email marketing tables and the
 * tracking columns on `emails`. createCrmSchema() drops everything first, so
 * this is order-independent.
 */
trait EmailMarketingTestSchema
{
    use CrmTestSchema;

    private function createEmailMarketingSchema(): void
    {
        $this->createCrmSchema();

        $db = db_connect();
        $p  = $db->DBPrefix;

        foreach ([
            'email_campaign_id INTEGER', 'tracking_token TEXT', 'sent_at TEXT', 'opened_at TEXT',
            'open_count INTEGER DEFAULT 0', 'clicked_at TEXT', 'click_count INTEGER DEFAULT 0', 'unsubscribed_at TEXT',
            'replied_at TEXT', 'message_id TEXT', 'is_auto_reply INTEGER DEFAULT 0',
        ] as $col) {
            try { $db->query("ALTER TABLE {$p}emails ADD COLUMN {$col}"); } catch (\Throwable $e) { /* already there */ }
        }
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS emails_campaign_contact_unique ON {$p}emails (email_campaign_id, contact_id)");
        $db->query("CREATE UNIQUE INDEX IF NOT EXISTS emails_tracking_token_unique ON {$p}emails (tracking_token)");

        $db->query("CREATE TABLE IF NOT EXISTS {$p}email_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, name TEXT, subject TEXT,
            preheader TEXT, html_body TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}email_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, email_template_id INTEGER,
            name TEXT, subject TEXT, preheader TEXT, from_name TEXT, reply_to TEXT, html_body TEXT,
            segment TEXT, status TEXT DEFAULT 'draft', scheduled_at TEXT, schedule_timezone TEXT,
            cursor INTEGER DEFAULT 0, total_contacts INTEGER DEFAULT 0, sent_count INTEGER DEFAULT 0,
            failed_count INTEGER DEFAULT 0, stats TEXT, last_error TEXT, started_at TEXT, completed_at TEXT,
            created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}email_suppressions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, email TEXT NOT NULL,
            contact_id INTEGER, reason TEXT DEFAULT 'manual', source_email_id INTEGER,
            created_at TEXT, updated_at TEXT, UNIQUE(tenant_id, email)
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}email_clicks (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, email_id INTEGER,
            email_campaign_id INTEGER, contact_id INTEGER, url TEXT, created_at TEXT
        )");

        foreach (['email_templates', 'email_campaigns', 'email_suppressions', 'email_clicks'] as $t) {
            $db->query("DELETE FROM {$p}{$t}");
        }
    }

    private function connectTenantSmtp(int $tenantId = 1, array $extra = []): void
    {
        db_connect()->table('integrations')->insert([
            'tenant_id' => $tenantId,
            'type'      => 'email_smtp',
            'status'    => 'active',
            'config'    => json_encode($extra + [
                'host' => 'smtp.tenant.test', 'port' => 587, 'username' => 'u',
                'from_email' => 'news@tenant.test', 'from_name' => 'Tenant News',
            ]),
        ]);
    }

    private function seedEmailCampaign(array $overrides = []): int
    {
        db_connect()->table('email_campaigns')->insert(array_merge([
            'tenant_id'  => 1,
            'name'       => 'September newsletter',
            'subject'    => 'Hi {{contact.first_name|there}}',
            'html_body'  => '<html><body><p>Hello {{contact.name}}</p><a href="https://example.com/offer?a=1&amp;b=2">Offer</a></body></html>',
            'segment'    => json_encode(['all' => true]),
            'status'     => 'processing',
            'stats'      => json_encode([]),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides));

        return (int) db_connect()->insertID();
    }
}
