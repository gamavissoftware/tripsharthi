<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ConversationModel;
use App\Models\MessageModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\BlankSlateSchema;

/**
 * Regression tests for two inbox bugs that caused "my message is not showing":
 *
 *  1. MessageModel::forConversation() must return the NEWEST messages, not the
 *     oldest. The old ORDER BY created_at ASC LIMIT 50 hid every recent message
 *     once a conversation grew past 50 rows.
 *
 *  2. ConversationModel::findOrCreate() must map a number to ONE conversation
 *     regardless of a leading "+", so inbound ("+91…") never forks a second
 *     thread from an outbound/import row stored as bare "91…".
 *
 * Uses SQLite3 :memory: (CI4 default test DB) — no MySQL required.
 */
class InboxMessagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use BlankSlateSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
        $this->createTestSchema();
    }

    private function createTestSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;

        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (
            id INTEGER PRIMARY KEY, name TEXT, slug TEXT, plan TEXT DEFAULT 'free',
            status TEXT DEFAULT 'active', mode TEXT DEFAULT 'saas',
            settings TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, contact_id INTEGER, phone_number_id INTEGER,
            wa_number TEXT NOT NULL, contact_name TEXT,
            window_expires_at TEXT, last_message_at TEXT, last_inbound_at TEXT,
            unread_count INTEGER DEFAULT 0, is_read INTEGER DEFAULT 0,
            status TEXT DEFAULT 'open', assigned_user_id INTEGER,
            created_at TEXT, updated_at TEXT,
            UNIQUE(tenant_id, wa_number)
        )");
        // findOrCreate() resolves the CRM contact for the number it is given,
        // so the table has to exist even when a test does not use contacts.
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, wa_number TEXT, name TEXT, email TEXT,
            account_id INTEGER, business_type TEXT,
            status TEXT DEFAULT 'new', source TEXT DEFAULT 'manual', opt_in INTEGER DEFAULT 1,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, contact_id INTEGER, conversation_id INTEGER,
            campaign_id INTEGER, variant TEXT, direction TEXT, type TEXT, category TEXT,
            body TEXT, media_url TEXT, wa_message_id TEXT, status TEXT,
            billable INTEGER DEFAULT 0, error TEXT,
            sent_at TEXT, delivered_at TEXT, read_at TEXT,
            created_at TEXT, updated_at TEXT
        )");
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");
    }

    // ── forConversation: newest, not oldest ───────────────────────────

    public function testForConversationReturnsNewestMessagesNotOldest(): void
    {
        $db = db_connect();
        // 60 messages, created_at strictly increasing so ordering is unambiguous.
        for ($i = 1; $i <= 60; $i++) {
            $db->table('messages')->insert([
                'tenant_id'       => 1,
                'conversation_id' => 99,
                'direction'       => 'in',
                'type'            => 'text',
                'body'            => "msg-{$i}",
                'status'          => 'delivered',
                'created_at'      => date('Y-m-d H:i:s', mktime(12, 0, $i, 1, 15, 2026)),
            ]);
        }

        $rows = (new MessageModel())->forConversation(1, 99, null, 50);

        $this->assertCount(50, $rows, 'should return the limit');
        // Rendered oldest → newest, so the LAST row must be the newest message…
        $this->assertSame('msg-60', $rows[49]['body'], 'newest message must be present');
        // …and the window is the most recent 50 (msg-11 … msg-60), NOT msg-1.
        $this->assertSame('msg-11', $rows[0]['body'], 'should be the newest 50, not the oldest 50');
        $bodies = array_column($rows, 'body');
        $this->assertNotContains('msg-1', $bodies, 'oldest message must NOT crowd out new ones');
    }

    public function testForConversationSinceReturnsOnlyNewer(): void
    {
        $db = db_connect();
        foreach ([1, 2, 3] as $i) {
            $db->table('messages')->insert([
                'tenant_id' => 1, 'conversation_id' => 7, 'direction' => 'in', 'type' => 'text',
                'body' => "m{$i}", 'status' => 'delivered',
                'created_at' => date('Y-m-d H:i:s', mktime(12, 0, $i, 1, 15, 2026)),
            ]);
        }
        $since = date('Y-m-d H:i:s', mktime(12, 0, 2, 1, 15, 2026)); // after m2
        $rows  = (new MessageModel())->forConversation(1, 7, $since, 50);

        $this->assertCount(1, $rows);
        $this->assertSame('m3', $rows[0]['body']);
    }

    // ── findOrCreate: +/no-+ dedup ────────────────────────────────────

    public function testFindOrCreateMatchesExistingDespitePlusPrefix(): void
    {
        $model = new ConversationModel();
        // Existing row stored WITHOUT the '+'
        $first = $model->findOrCreate(1, '919650609615');
        // Inbound arrives WITH the '+': must reuse the same conversation
        $second = $model->findOrCreate(1, '+919650609615');

        $this->assertSame((int) $first['id'], (int) $second['id'], 'no duplicate thread for +/no-+');

        $count = db_connect()->table('conversations')
            ->where('tenant_id', 1)->like('wa_number', '919650609615')->countAllResults();
        $this->assertSame(1, $count, 'exactly one conversation for the number');
    }

    public function testFindOrCreateStoresCanonicalPlusForm(): void
    {
        $conv = (new ConversationModel())->findOrCreate(1, '919812345670');
        $this->assertSame('+919812345670', $conv['wa_number'], 'new conversations stored as +E.164');
    }
}
