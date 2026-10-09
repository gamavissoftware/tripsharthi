<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Crm\AccountResolver;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Shared by the importer and the contact form. The behaviour that matters is
 * that both reach the SAME account row for the same name — a second copy of
 * this logic, or a case-sensitive match, quietly produces duplicate companies.
 */
class AccountResolverTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = true;

    private AccountResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->resolver = new AccountResolver();
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
        $db->query("CREATE TABLE IF NOT EXISTS {$p}accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            name TEXT NOT NULL, domain TEXT, industry TEXT, type TEXT DEFAULT 'prospect',
            owner_id INTEGER, phone TEXT, website TEXT, address_line TEXT,
            city TEXT, state TEXT, country TEXT, postal_code TEXT,
            annual_revenue INTEGER, employee_count INTEGER, parent_account_id INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test'), (2, 'Other', 'other')");
    }

    private function accountCount(): int
    {
        return db_connect()->table('accounts')->countAllResults();
    }

    public function testCreatesAnAccountWithItsIndustry(): void
    {
        $id = $this->resolver->resolve(1, 'Mahesh Roofing Enterprise', 'Construction & Building Materials');

        $this->assertNotNull($id);
        $row = db_connect()->table('accounts')->where('id', $id)->get()->getRowArray();
        $this->assertSame('Mahesh Roofing Enterprise', $row['name']);
        $this->assertSame('Construction & Building Materials', $row['industry']);
        $this->assertSame('prospect', $row['type']);
    }

    public function testSameNameReturnsTheSameAccount(): void
    {
        $a = $this->resolver->resolve(1, 'Acme Steel');
        $b = $this->resolver->resolve(1, 'Acme Steel');

        $this->assertSame($a, $b);
        $this->assertSame(1, $this->accountCount());
    }

    /** A fresh instance must not create a second row — the cache is an optimisation, not the rule. */
    public function testAnotherInstanceReusesTheExistingAccount(): void
    {
        $a = $this->resolver->resolve(1, 'Acme Steel');
        $b = (new AccountResolver())->resolve(1, 'Acme Steel');

        $this->assertSame($a, $b);
        $this->assertSame(1, $this->accountCount());
    }

    public function testExistingIndustryIsNotOverwritten(): void
    {
        $id = $this->resolver->resolve(1, 'Acme Steel', 'Steel, Metals & Metal Products');
        $this->resolver->resolve(1, 'Acme Steel', 'Other / Not Identifiable');

        $row = db_connect()->table('accounts')->where('id', $id)->get()->getRowArray();
        $this->assertSame('Steel, Metals & Metal Products', $row['industry']);
    }

    public function testBlankNameResolvesToNullAndCreatesNothing(): void
    {
        $this->assertNull($this->resolver->resolve(1, ''));
        $this->assertNull($this->resolver->resolve(1, '   '));
        $this->assertSame(0, $this->accountCount());
    }

    public function testNameIsTrimmedBeforeMatching(): void
    {
        $a = $this->resolver->resolve(1, 'Acme Steel');
        $b = $this->resolver->resolve(1, '  Acme Steel  ');

        $this->assertSame($a, $b);
        $this->assertSame(1, $this->accountCount());
    }

    public function testTenantsDoNotShareAccounts(): void
    {
        $a = $this->resolver->resolve(1, 'Acme Steel');
        $b = $this->resolver->resolve(2, 'Acme Steel');

        $this->assertNotSame($a, $b);
        $this->assertSame(2, $this->accountCount());
    }

    public function testAnOverlongNameIsTruncatedRatherThanRejected(): void
    {
        $id = $this->resolver->resolve(1, str_repeat('A', 400));

        $this->assertNotNull($id);
        $row = db_connect()->table('accounts')->where('id', $id)->get()->getRowArray();
        $this->assertSame(255, mb_strlen($row['name']));
    }
}
