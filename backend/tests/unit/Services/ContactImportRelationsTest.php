<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Services\Leads\ContactDedupeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Import relations: tag get-or-create + linking, company → account linking,
 * and the direct CRM columns the mapper can now target.
 *
 * These cover the path a categorised CRM export takes: one column carries the
 * company category (→ tag, the unit campaigns segment on) and another the
 * company name (→ account).
 */
class ContactImportRelationsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = true;

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
        $p  = $db->DBPrefix;

        // Order-independence: the :memory: connection is shared across test
        // classes, so shed anything a prior class left behind.
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
            account_id INTEGER, owner_id INTEGER,
            job_title TEXT, phone_secondary TEXT, business_type TEXT,
            city TEXT, state TEXT, country TEXT, language TEXT, remarks TEXT,
            status TEXT DEFAULT 'new',
            source TEXT DEFAULT 'manual',
            opt_in INTEGER DEFAULT 1,
            last_inbound_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT,
            UNIQUE(tenant_id, wa_number)
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            color TEXT DEFAULT '#6366f1',
            created_at TEXT, updated_at TEXT, deleted_at TEXT,
            UNIQUE(tenant_id, name)
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contact_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            created_at TEXT,
            UNIQUE(contact_id, tag_id)
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            name TEXT NOT NULL, domain TEXT, industry TEXT,
            type TEXT DEFAULT 'prospect', owner_id INTEGER,
            phone TEXT, website TEXT, address_line TEXT,
            city TEXT, state TEXT, country TEXT, postal_code TEXT,
            annual_revenue INTEGER, employee_count INTEGER, parent_account_id INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
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

    /** @return string[] tag names linked to a contact */
    private function tagsOf(int $contactId): array
    {
        $rows = db_connect()->table('contact_tags ct')
            ->select('t.name')
            ->join('tags t', 't.id = ct.tag_id')
            ->where('ct.contact_id', $contactId)
            ->get()->getResultArray();

        $names = array_column($rows, 'name');
        sort($names);
        return $names;
    }

    // ------------------------------------------------------------------
    // Tags
    // ------------------------------------------------------------------

    public function testTagIsCreatedAndLinkedOnInsert(): void
    {
        $res = $this->dedupe->upsert(1, [
            'wa_number' => '+919810000001',
            'name'      => 'Kapil',
            'source'    => 'csv_import',
            'tags'      => 'Automobile & Auto Components',
        ]);

        $this->assertSame('inserted', $res['action']);
        $this->assertSame(['Automobile & Auto Components'], $this->tagsOf($res['contact_id']));
    }

    public function testMultipleTagsSplitOnSemicolon(): void
    {
        $res = $this->dedupe->upsert(1, [
            'wa_number' => '+919810000002',
            'tags'      => 'CRM – Buyer; CRM – Tenant ;Inactive',
        ]);

        $this->assertSame(['CRM – Buyer', 'CRM – Tenant', 'Inactive'], $this->tagsOf($res['contact_id']));
    }

    /**
     * Category names routinely contain commas. Splitting on ',' would turn one
     * real category into two meaningless tags and break campaign targeting.
     */
    public function testCommaInsideATagNameIsNotASeparator(): void
    {
        $res = $this->dedupe->upsert(1, [
            'wa_number' => '+919810000009',
            'tags'      => 'Steel, Metals & Metal Products',
        ]);

        $this->assertSame(['Steel, Metals & Metal Products'], $this->tagsOf($res['contact_id']));
    }

    public function testTagsAreReusedNotDuplicatedAcrossContacts(): void
    {
        $a = $this->dedupe->upsert(1, ['wa_number' => '+919810000003', 'tags' => 'Steel, Metals']);
        $b = $this->dedupe->upsert(1, ['wa_number' => '+919810000004', 'tags' => 'Steel, Metals']);

        $this->assertNotSame($a['contact_id'], $b['contact_id']);

        $count = db_connect()->table('tags')->where('tenant_id', 1)->countAllResults();
        $this->assertSame(1, $count, 'Tags must be reused, not recreated per contact');
    }

    public function testReimportAddsTagWithoutRemovingExistingOnes(): void
    {
        $res = $this->dedupe->upsert(1, ['wa_number' => '+919810000005', 'tags' => 'Apparel']);
        $id  = $res['contact_id'];

        // A tag a human added by hand, not present in the import file.
        $this->dedupe->upsert(1, ['wa_number' => '+919810000005', 'tags' => 'VIP']);

        $this->assertSame(['Apparel', 'VIP'], $this->tagsOf($id));
    }

    public function testRelinkingSameTagIsANoOp(): void
    {
        $res = $this->dedupe->upsert(1, ['wa_number' => '+919810000006', 'tags' => 'Pharma']);
        $this->dedupe->upsert(1, ['wa_number' => '+919810000006', 'tags' => 'Pharma']);

        $links = db_connect()->table('contact_tags')
            ->where('contact_id', $res['contact_id'])->countAllResults();

        $this->assertSame(1, $links, 'Re-importing the same tag must not duplicate the link');
    }

    public function testOverlongTagNameIsTruncatedToColumnWidth(): void
    {
        $long = str_repeat('A', 80);
        $res  = $this->dedupe->upsert(1, ['wa_number' => '+919810000007', 'tags' => $long]);

        $names = $this->tagsOf($res['contact_id']);
        $this->assertCount(1, $names);
        $this->assertSame(50, mb_strlen($names[0]));
    }

    public function testBlankTagCellCreatesNoTags(): void
    {
        $res = $this->dedupe->upsert(1, ['wa_number' => '+919810000008', 'tags' => '  ;  , ']);

        $this->assertSame([], $this->tagsOf($res['contact_id']));
        $this->assertSame(0, db_connect()->table('tags')->countAllResults());
    }

    // ------------------------------------------------------------------
    // Company → account
    // ------------------------------------------------------------------

    public function testCompanyCreatesAccountWithIndustryAndLinksContact(): void
    {
        $res = $this->dedupe->upsert(1, [
            'wa_number'        => '+919810000010',
            'company'          => '360 Clothing',
            'company_industry' => 'Apparel, Textiles & Leather',
        ]);

        $account = db_connect()->table('accounts')->where('name', '360 Clothing')->get()->getRowArray();
        $this->assertNotNull($account);
        $this->assertSame('Apparel, Textiles & Leather', $account['industry']);

        $contact = db_connect()->table('contacts')->where('id', $res['contact_id'])->get()->getRowArray();
        $this->assertSame((int) $account['id'], (int) $contact['account_id']);
    }

    public function testSameCompanyIsReusedAcrossContacts(): void
    {
        $a = $this->dedupe->upsert(1, ['wa_number' => '+919810000011', 'company' => 'A B Garments']);
        $b = $this->dedupe->upsert(1, ['wa_number' => '+919810000012', 'company' => 'A B Garments']);

        $this->assertSame(1, db_connect()->table('accounts')->countAllResults());

        $rows = db_connect()->table('contacts')
            ->whereIn('id', [$a['contact_id'], $b['contact_id']])->get()->getResultArray();

        $this->assertSame(1, count(array_unique(array_column($rows, 'account_id'))));
    }

    public function testExistingAccountIndustryIsNotOverwritten(): void
    {
        $this->dedupe->upsert(1, [
            'wa_number' => '+919810000013',
            'company'   => 'Acme Corp', 'company_industry' => 'Engineering',
        ]);
        // A later row claims a different category for the same company.
        $this->dedupe->upsert(1, [
            'wa_number' => '+919810000014',
            'company'   => 'Acme Corp', 'company_industry' => 'Other / Not Identifiable',
        ]);

        $account = db_connect()->table('accounts')->where('name', 'Acme Corp')->get()->getRowArray();
        $this->assertSame('Engineering', $account['industry'], 'Import must not re-categorise a known company');
    }

    public function testBlankCompanyLeavesContactUnlinked(): void
    {
        $res = $this->dedupe->upsert(1, ['wa_number' => '+919810000015', 'company' => '   ']);

        $contact = db_connect()->table('contacts')->where('id', $res['contact_id'])->get()->getRowArray();
        $this->assertNull($contact['account_id']);
        $this->assertSame(0, db_connect()->table('accounts')->countAllResults());
    }

    // ------------------------------------------------------------------
    // Direct CRM columns
    // ------------------------------------------------------------------

    public function testDirectCrmFieldsArePersistedOnInsert(): void
    {
        $res = $this->dedupe->upsert(1, [
            'wa_number'       => '+919810000020',
            'name'            => 'Awdesh',
            'job_title'       => 'Owner',
            'phone_secondary' => '011-22090632',
            'business_type'   => 'Apparel, Textiles & Leather',
            'city'            => 'Faridabad',
        ]);

        $row = db_connect()->table('contacts')->where('id', $res['contact_id'])->get()->getRowArray();
        $this->assertSame('Owner', $row['job_title']);
        $this->assertSame('011-22090632', $row['phone_secondary']);
        $this->assertSame('Apparel, Textiles & Leather', $row['business_type']);
        $this->assertSame('Faridabad', $row['city']);
    }

    public function testDirectFieldsEnrichAnExistingContactButBlanksDoNotErase(): void
    {
        $res = $this->dedupe->upsert(1, [
            'wa_number' => '+919810000021',
            'job_title' => 'Owner',
            'city'      => 'Gurugram',
        ]);

        $this->dedupe->upsert(1, [
            'wa_number' => '+919810000021',
            'job_title' => 'Managing Director',   // enriched
            'city'      => '',                    // blank must not erase
        ]);

        $row = db_connect()->table('contacts')->where('id', $res['contact_id'])->get()->getRowArray();
        $this->assertSame('Managing Director', $row['job_title']);
        $this->assertSame('Gurugram', $row['city']);
    }

    public function testOptInDefaultsToOneSoMarketingCampaignsCanReachImports(): void
    {
        $res = $this->dedupe->upsert(1, ['wa_number' => '+919810000022', 'source' => 'csv_import']);

        $row = db_connect()->table('contacts')->where('id', $res['contact_id'])->get()->getRowArray();
        $this->assertSame(1, (int) $row['opt_in']);
    }
}
