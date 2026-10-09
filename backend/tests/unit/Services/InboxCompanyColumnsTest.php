<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ConversationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The inbox shows each conversation's company and category, sourced through
 * conversations → contacts → accounts.
 *
 * The risk in that chain is join strictness: an inbound from an unknown number
 * has no contact row, and plenty of contacts have no company. Either case must
 * still appear in the inbox — a conversation silently vanishing is far worse
 * than a blank company cell.
 */
class InboxCompanyColumnsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = true;

    private ConversationModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->model = new ConversationModel();
    }

    private function createTestSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;

        foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->getResultArray() as $row) {
            $db->query('DROP TABLE IF EXISTS "' . $row['name'] . '"');
        }

        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (
            id INTEGER PRIMARY KEY, name TEXT, slug TEXT, plan TEXT DEFAULT 'free',
            status TEXT DEFAULT 'active', mode TEXT DEFAULT 'saas',
            settings TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT,
            email TEXT, password_hash TEXT, role TEXT DEFAULT 'agent',
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT,
            industry TEXT, type TEXT DEFAULT 'prospect',
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, wa_number TEXT,
            name TEXT, email TEXT, account_id INTEGER, business_type TEXT,
            status TEXT DEFAULT 'new', source TEXT DEFAULT 'manual', opt_in INTEGER DEFAULT 1,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, contact_id INTEGER,
            phone_number_id INTEGER, wa_number TEXT, contact_name TEXT,
            window_expires_at TEXT, last_message_at TEXT, is_read INTEGER DEFAULT 0,
            last_inbound_at TEXT, unread_count INTEGER DEFAULT 0,
            status TEXT DEFAULT 'open', assigned_user_id INTEGER, handoff_at TEXT,
            created_at TEXT, updated_at TEXT
        )");

        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");
        $db->table('accounts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Acme Steel', 'industry' => 'Steel, Metals & Metal Products']);
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'wa_number' => '+919000000001', 'name' => 'Ravi', 'email' => 'ravi@acme.in', 'account_id' => 1, 'business_type' => 'Steel, Metals & Metal Products']);
        // A contact with a category but no company row.
        $db->table('contacts')->insert(['id' => 2, 'tenant_id' => 1, 'wa_number' => '+919000000002', 'name' => 'Solo', 'account_id' => null, 'business_type' => 'IT, Software & Telecom']);

        $convs = [
            ['id' => 1, 'tenant_id' => 1, 'contact_id' => 1,    'wa_number' => '+919000000001', 'contact_name' => 'Ravi',    'last_message_at' => '2026-09-15 10:00:00'],
            ['id' => 2, 'tenant_id' => 1, 'contact_id' => 2,    'wa_number' => '+919000000002', 'contact_name' => 'Solo',    'last_message_at' => '2026-09-15 09:00:00'],
            // Inbound from a number with no contact record yet.
            ['id' => 3, 'tenant_id' => 1, 'contact_id' => null, 'wa_number' => '+919000000003', 'contact_name' => 'Unknown', 'last_message_at' => '2026-09-15 08:00:00'],
        ];
        foreach ($convs as $c) {
            $db->table('conversations')->insert($c + ['created_at' => '2026-09-15 08:00:00']);
        }
    }

    // ------------------------------------------------------------------
    // List decoration
    // ------------------------------------------------------------------

    public function testListExposesCompanyAndCategory(): void
    {
        $rows = $this->model->listForInbox(1, [], 50);
        $byId = array_column($rows, null, 'id');

        $this->assertSame('Acme Steel', $byId[1]['company_name']);
        $this->assertSame('Steel, Metals & Metal Products', $byId[1]['contact_category']);
        $this->assertSame('ravi@acme.in', $byId[1]['contact_email']);
    }

    public function testConversationWhoseContactHasNoCompanyStillListsWithCategory(): void
    {
        $byId = array_column($this->model->listForInbox(1, [], 50), null, 'id');

        $this->assertArrayHasKey(2, $byId);
        $this->assertNull($byId[2]['company_name']);
        $this->assertSame('IT, Software & Telecom', $byId[2]['contact_category']);
    }

    /** An unknown inbound number has no contact row — it must not disappear. */
    public function testConversationWithNoContactRowStillAppears(): void
    {
        $byId = array_column($this->model->listForInbox(1, [], 50), null, 'id');

        $this->assertArrayHasKey(3, $byId);
        $this->assertNull($byId[3]['company_name']);
        $this->assertNull($byId[3]['contact_category']);
    }

    public function testAllConversationsAreListed(): void
    {
        $this->assertCount(3, $this->model->listForInbox(1, [], 50));
    }

    // ------------------------------------------------------------------
    // Category filter
    // ------------------------------------------------------------------

    public function testCategoryFilterNarrowsTheInbox(): void
    {
        $rows = $this->model->listForInbox(1, ['category' => 'IT, Software & Telecom'], 50);

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['id']);
    }

    // ------------------------------------------------------------------
    // Single conversation
    // ------------------------------------------------------------------

    public function testFindForInboxCarriesTheSameDecorationAsTheList(): void
    {
        $conv = $this->model->findForInbox(1, 1);

        $this->assertNotNull($conv);
        $this->assertSame('Acme Steel', $conv['company_name']);
        $this->assertSame('Steel, Metals & Metal Products', $conv['contact_category']);
    }

    public function testFindForInboxReturnsNullForAnUnknownConversation(): void
    {
        $this->assertNull($this->model->findForInbox(1, 999));
    }

    // ------------------------------------------------------------------
    // findOrCreate links the CRM contact
    // ------------------------------------------------------------------

    public function testNewConversationLinksTheMatchingContact(): void
    {
        // +919000000009 belongs to a contact created here, with no conversation yet.
        db_connect()->table('contacts')->insert([
            'id' => 9, 'tenant_id' => 1, 'wa_number' => '+919000000009', 'name' => 'Fresh',
        ]);

        $conv = $this->model->findOrCreate(1, '+919000000009');

        $this->assertSame(9, (int) $conv['contact_id']);
    }

    /** Webhooks store "+9198…" while some import paths stored bare "9198…". */
    public function testContactIsLinkedAcrossPlusPrefixDrift(): void
    {
        db_connect()->table('contacts')->insert([
            'id' => 10, 'tenant_id' => 1, 'wa_number' => '919000000010', 'name' => 'Bare',
        ]);

        $conv = $this->model->findOrCreate(1, '+919000000010');

        $this->assertSame(10, (int) $conv['contact_id']);
    }

    public function testAnExplicitContactIdFromTheCallerWins(): void
    {
        db_connect()->table('contacts')->insert([
            'id' => 11, 'tenant_id' => 1, 'wa_number' => '+919000000011', 'name' => 'Guessable',
        ]);

        $conv = $this->model->findOrCreate(1, '+919000000011', ['contact_id' => 1]);

        $this->assertSame(1, (int) $conv['contact_id']);
    }

    public function testUnknownNumberLeavesContactIdNull(): void
    {
        $conv = $this->model->findOrCreate(1, '+919999999999');

        $this->assertNull($conv['contact_id']);
    }

    public function testAnotherTenantsContactIsNeverLinked(): void
    {
        db_connect()->table('contacts')->insert([
            'id' => 12, 'tenant_id' => 2, 'wa_number' => '+919000000012', 'name' => 'Foreign',
        ]);

        $conv = $this->model->findOrCreate(1, '+919000000012');

        $this->assertNull($conv['contact_id']);
    }
}
