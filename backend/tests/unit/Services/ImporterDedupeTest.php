<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Services\Leads\ContactDedupeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Tests ContactDedupeService upsert logic and Importer dedupe.
 *
 * Runs against CI4's SQLite3 :memory: test database.
 * The contacts table is created inline — no migration runner needed.
 */
class ImporterDedupeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = false; // we set up schema manually below
    protected $refresh     = true;

    private ContactDedupeService $dedupe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();

        $this->dedupe = new ContactDedupeService(
            new ContactModel(),
            new ContactFieldValueModel()
        );
    }

    private function createTestSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix; // 'db_' in the SQLite3 test group

        // Order-independence: the :memory: connection is shared across all test
        // classes, so drop every table first to shed any contacts/rows a prior
        // class (using a different schema trait) left behind — otherwise a leftover
        // contact collides here and dedupe returns 'updated' instead of 'inserted'.
        foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->getResultArray() as $row) {
            $db->query('DROP TABLE IF EXISTS "' . $row['name'] . '"');
        }

        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (
            id INTEGER PRIMARY KEY,
            name TEXT, slug TEXT, plan TEXT DEFAULT 'free',
            status TEXT DEFAULT 'active', mode TEXT DEFAULT 'saas',
            settings TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            wa_number TEXT NOT NULL,
            name TEXT, email TEXT,
            status TEXT DEFAULT 'new',
            source TEXT DEFAULT 'manual',
            opt_in INTEGER DEFAULT 1,
            last_inbound_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT,
            UNIQUE(tenant_id, wa_number)
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}custom_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER, label TEXT, field_key TEXT, type TEXT DEFAULT 'text',
            options TEXT, is_required INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contact_field_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER, custom_field_id INTEGER, value TEXT,
            created_at TEXT, updated_at TEXT,
            UNIQUE(contact_id, custom_field_id)
        )");
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");
    }

    // ------------------------------------------------------------------
    // Insert on new wa_number
    // ------------------------------------------------------------------

    public function testNewNumberCreatesContact(): void
    {
        $result = $this->dedupe->upsert(1, [
            'wa_number' => '+919999900001',
            'name'      => 'Alice',
            'source'    => 'csv_import',
        ]);

        $this->assertSame('inserted', $result['action']);
        $this->assertGreaterThan(0, $result['contact_id']);

        $row = db_connect()->table('contacts')->where('wa_number', '+919999900001')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('Alice', $row['name']);
    }

    // ------------------------------------------------------------------
    // Update on duplicate wa_number (same tenant)
    // ------------------------------------------------------------------

    public function testDuplicateWaNumberUpdatesNotInserts(): void
    {
        $this->dedupe->upsert(1, ['wa_number' => '+919999900002', 'name' => 'Bob']);
        $result = $this->dedupe->upsert(1, ['wa_number' => '+919999900002', 'name' => 'Robert']);

        $this->assertSame('updated', $result['action']);

        $count = db_connect()->table('contacts')->where('wa_number', '+919999900002')->countAllResults();
        $this->assertSame(1, $count, 'Should only be one row, not two');

        $row = db_connect()->table('contacts')->where('wa_number', '+919999900002')->get()->getRowArray();
        $this->assertSame('Robert', $row['name'], 'Name should be overwritten');
    }

    // ------------------------------------------------------------------
    // '+' prefix drift — the same person must never become two contacts
    // ------------------------------------------------------------------

    /**
     * Meta's webhooks deliver bare digits that we store as "+9198…", while a CSV
     * column already carrying the country code is stored as "9198…". Matching
     * exactly meant an imported lead who replied on WhatsApp was deduped into a
     * second contact, and the conversation attached to the copy — losing the
     * lead's tags, status, custom fields and campaign attribution.
     */
    public function testInboundWithPlusMatchesContactImportedWithout(): void
    {
        $this->dedupe->upsert(1, ['wa_number' => '919999900050', 'name' => 'Imported Lead']);
        $result = $this->dedupe->upsert(1, ['wa_number' => '+919999900050', 'name' => 'WhatsApp Reply']);

        $this->assertSame('updated', $result['action'],
            'the inbound reply must land on the imported lead, not a new contact');
        $this->assertSame(1, db_connect()->table('contacts')
            ->whereIn('wa_number', ['919999900050', '+919999900050'])->countAllResults());
    }

    public function testImportWithoutPlusMatchesContactStoredWithIt(): void
    {
        $this->dedupe->upsert(1, ['wa_number' => '+919999900051', 'name' => 'Existing']);
        $result = $this->dedupe->upsert(1, ['wa_number' => '919999900051', 'name' => 'Csv Row']);

        $this->assertSame('updated', $result['action']);
        $this->assertSame(1, db_connect()->table('contacts')
            ->whereIn('wa_number', ['919999900051', '+919999900051'])->countAllResults());
    }

    public function testDifferentNumbersAreStillSeparateContacts(): void
    {
        $this->dedupe->upsert(1, ['wa_number' => '+919999900052', 'name' => 'A']);
        $result = $this->dedupe->upsert(1, ['wa_number' => '+919999900053', 'name' => 'B']);

        $this->assertSame('inserted', $result['action'],
            'variant matching must not collapse genuinely different numbers');
    }

    // ------------------------------------------------------------------
    // Tenant isolation — same wa_number in different tenants
    // ------------------------------------------------------------------

    public function testSameWaNumberInDifferentTenantsInsertsSeparately(): void
    {
        // Seed tenant id=2
        $p = db_connect()->DBPrefix;
        db_connect()->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (2, 'OtherCo', 'otherco')");

        $r1 = $this->dedupe->upsert(1, ['wa_number' => '+919999900003', 'name' => 'T1 User']);
        $r2 = $this->dedupe->upsert(2, ['wa_number' => '+919999900003', 'name' => 'T2 User']);

        $this->assertSame('inserted', $r1['action']);
        $this->assertSame('inserted', $r2['action']);
        $this->assertNotSame($r1['contact_id'], $r2['contact_id']);

        $count = db_connect()->table('contacts')->where('wa_number', '+919999900003')->countAllResults();
        $this->assertSame(2, $count, 'Both tenants should have their own contact row');
    }

    // ------------------------------------------------------------------
    // Blank name on update does NOT overwrite
    // ------------------------------------------------------------------

    public function testBlankNameDoesNotOverwriteExistingName(): void
    {
        $this->dedupe->upsert(1, ['wa_number' => '+919999900004', 'name' => 'Carol']);
        $this->dedupe->upsert(1, ['wa_number' => '+919999900004', 'name' => '']);

        $row = db_connect()->table('contacts')->where('wa_number', '+919999900004')->get()->getRowArray();
        $this->assertSame('Carol', $row['name'], 'Empty name should not overwrite existing');
    }

    // ------------------------------------------------------------------
    // Status and opt_in are NOT touched on update
    // ------------------------------------------------------------------

    public function testStatusNotOverwrittenOnUpdate(): void
    {
        $this->dedupe->upsert(1, ['wa_number' => '+919999900005', 'status' => 'new']);

        // Manually advance the status (simulating CRM work)
        db_connect()->table('contacts')
            ->where('wa_number', '+919999900005')
            ->update(['status' => 'qualified']);

        $this->dedupe->upsert(1, ['wa_number' => '+919999900005', 'name' => 'UpdatedName', 'status' => 'new']);

        $row = db_connect()->table('contacts')->where('wa_number', '+919999900005')->get()->getRowArray();
        $this->assertSame('qualified', $row['status'], 'Status must NOT be overwritten by re-import');
    }

    public function testOptInNotOverwrittenOnUpdate(): void
    {
        $this->dedupe->upsert(1, ['wa_number' => '+919999900006', 'opt_in' => 1]);

        // Contact unsubscribes
        db_connect()->table('contacts')
            ->where('wa_number', '+919999900006')
            ->update(['opt_in' => 0]);

        $this->dedupe->upsert(1, ['wa_number' => '+919999900006', 'opt_in' => 1]);

        $row = db_connect()->table('contacts')->where('wa_number', '+919999900006')->get()->getRowArray();
        $this->assertSame('0', (string) $row['opt_in'], 'opt_in must NOT be reset by re-import');
    }

    // ------------------------------------------------------------------
    // Missing wa_number throws
    // ------------------------------------------------------------------

    public function testMissingWaNumberThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dedupe->upsert(1, ['name' => 'NoPhone']);
    }

    public function testEmptyWaNumberThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dedupe->upsert(1, ['wa_number' => '', 'name' => 'Empty']);
    }
}
