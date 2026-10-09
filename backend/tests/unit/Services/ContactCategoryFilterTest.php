<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Category filtering and the company join behind the contacts list view.
 *
 * The list shows a contact's company and category, and filters by category —
 * both of which have to keep working for contacts that have no company at all.
 */
class ContactCategoryFilterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = true;

    private ContactModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->model = new ContactModel();
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
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, wa_number TEXT NOT NULL,
            name TEXT, email TEXT, account_id INTEGER, owner_id INTEGER,
            job_title TEXT, phone_secondary TEXT, business_type TEXT,
            city TEXT, state TEXT, country TEXT, language TEXT, remarks TEXT,
            status TEXT DEFAULT 'new', source TEXT DEFAULT 'manual', opt_in INTEGER DEFAULT 1,
            last_inbound_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            name TEXT NOT NULL, domain TEXT, industry TEXT, type TEXT DEFAULT 'prospect',
            owner_id INTEGER, phone TEXT, website TEXT, address_line TEXT,
            city TEXT, state TEXT, country TEXT, postal_code TEXT,
            annual_revenue INTEGER, employee_count INTEGER, parent_account_id INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contact_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INTEGER, tag_id INTEGER, created_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT,
            color TEXT DEFAULT '#6366f1', created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test'), (2, 'Other', 'other')");

        $db->table('accounts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => '360 Clothing', 'industry' => 'Apparel, Textiles & Leather']);
        $db->table('accounts')->insert(['id' => 2, 'tenant_id' => 1, 'name' => 'Acme Steel',   'industry' => 'Steel, Metals & Metal Products']);

        $rows = [
            ['tenant_id' => 1, 'wa_number' => '+919000000001', 'name' => 'Kanwal', 'account_id' => 1, 'business_type' => 'Apparel, Textiles & Leather'],
            ['tenant_id' => 1, 'wa_number' => '+919000000002', 'name' => 'Kapil',  'account_id' => 1, 'business_type' => 'Apparel, Textiles & Leather'],
            ['tenant_id' => 1, 'wa_number' => '+919000000003', 'name' => 'Ravi',   'account_id' => 2, 'business_type' => 'Steel, Metals & Metal Products'],
            // No company and no category — an individual contact.
            ['tenant_id' => 1, 'wa_number' => '+919000000004', 'name' => 'Aabid',  'account_id' => null, 'business_type' => null],
            ['tenant_id' => 1, 'wa_number' => '+919000000005', 'name' => 'Blank',  'account_id' => null, 'business_type' => ''],
            // Another tenant's contact must never leak into either list.
            ['tenant_id' => 2, 'wa_number' => '+919000000006', 'name' => 'Foreign', 'account_id' => null, 'business_type' => 'Pharma, Healthcare & Medical'],
        ];
        foreach ($rows as $r) {
            $db->table('contacts')->insert($r + ['created_at' => '2026-09-15 10:00:00']);
        }
    }

    // ------------------------------------------------------------------
    // Category options
    // ------------------------------------------------------------------

    public function testDistinctCategoriesAreDedupedAndSorted(): void
    {
        $this->assertSame(
            ['Apparel, Textiles & Leather', 'Steel, Metals & Metal Products'],
            $this->model->distinctCategories(1)
        );
    }

    public function testDistinctCategoriesExcludeNullAndEmpty(): void
    {
        $this->assertNotContains('', $this->model->distinctCategories(1));
        $this->assertCount(2, $this->model->distinctCategories(1));
    }

    public function testDistinctCategoriesAreTenantScoped(): void
    {
        $this->assertSame(['Pharma, Healthcare & Medical'], $this->model->distinctCategories(2));
    }

    // ------------------------------------------------------------------
    // List filtering + company join
    // ------------------------------------------------------------------

    public function testCategoryFilterReturnsOnlyThatCategory(): void
    {
        $rows = $this->model->setTenant(1)
            ->listWithTags(['category' => 'Apparel, Textiles & Leather'], 50);

        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame('Apparel, Textiles & Leather', $r['business_type']);
        }
    }

    public function testListExposesCompanyNameFromTheJoinedAccount(): void
    {
        $rows = $this->model->setTenant(1)
            ->listWithTags(['category' => 'Steel, Metals & Metal Products'], 50);

        $this->assertCount(1, $rows);
        $this->assertSame('Acme Steel', $rows[0]['company_name']);
        $this->assertSame('Steel, Metals & Metal Products', $rows[0]['company_industry']);
    }

    /** The account join must be a LEFT join or every individual contact vanishes. */
    public function testContactsWithoutACompanyStillAppear(): void
    {
        $rows  = $this->model->setTenant(1)->listWithTags([], 50);
        $names = array_column($rows, 'name');

        $this->assertContains('Aabid', $names);
        $this->assertCount(5, $rows, 'All of tenant 1 — companyless contacts included');
        $this->assertNotContains('Foreign', $names, 'Another tenant must not leak in');
    }

    public function testUnfilteredListIsUnaffectedByAnEmptyCategory(): void
    {
        $this->assertCount(5, $this->model->setTenant(1)->listWithTags(['category' => ''], 50));
    }

    // ------------------------------------------------------------------
    // companyFor — powers the contact profile header
    // ------------------------------------------------------------------

    public function testCompanyForReturnsTheLinkedAccount(): void
    {
        $company = $this->model->companyFor(1, 2);

        $this->assertNotNull($company);
        $this->assertSame('Acme Steel', $company['name']);
        $this->assertSame('Steel, Metals & Metal Products', $company['industry']);
    }

    public function testCompanyForReturnsNullWhenTheContactHasNoAccount(): void
    {
        $this->assertNull($this->model->companyFor(1, null));
        $this->assertNull($this->model->companyFor(1, 0));
    }

    public function testCompanyForWillNotReachIntoAnotherTenant(): void
    {
        $this->assertNull($this->model->companyFor(2, 1), 'Account 1 belongs to tenant 1');
    }

    public function testCompanyForReturnsNullForAnUnknownAccount(): void
    {
        $this->assertNull($this->model->companyFor(1, 999));
    }
}
