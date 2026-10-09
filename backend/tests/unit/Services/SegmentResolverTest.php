<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use App\Services\Leads\SegmentResolver;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Segment resolution decides who receives a broadcast, so the dangerous failure
 * here is not "too few" but "everyone": a segment the resolver cannot read must
 * never quietly expand to the entire contact list.
 */
class SegmentResolverTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = true;

    private SegmentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->resolver = new SegmentResolver();
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
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            wa_number TEXT, name TEXT, email TEXT, business_type TEXT,
            status TEXT DEFAULT 'new', source TEXT DEFAULT 'manual', opt_in INTEGER DEFAULT 1,
            last_inbound_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT,
            color TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contact_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INTEGER, tag_id INTEGER, created_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}segments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT,
            filters TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");

        $db->table('tags')->insert(['id' => 5, 'tenant_id' => 1, 'name' => 'Automobile & Auto Components']);

        // 4 contacts: only #1 is in the Automobile category and carries tag 5.
        $rows = [
            ['id' => 1, 'tenant_id' => 1, 'wa_number' => '+911', 'status' => 'new',       'source' => 'csv_import', 'business_type' => 'Automobile & Auto Components'],
            ['id' => 2, 'tenant_id' => 1, 'wa_number' => '+912', 'status' => 'contacted', 'source' => 'csv_import', 'business_type' => 'Steel, Metals & Metal Products', 'last_inbound_at' => '2026-09-15 09:00:00'],
            ['id' => 3, 'tenant_id' => 1, 'wa_number' => '+913', 'status' => 'new',       'source' => 'manual',     'business_type' => null],
            ['id' => 4, 'tenant_id' => 1, 'wa_number' => '+914', 'status' => 'won',       'source' => 'manual',     'business_type' => null],
        ];
        foreach ($rows as $r) {
            $db->table('contacts')->insert($r);
        }
        $db->table('contact_tags')->insert(['contact_id' => 1, 'tag_id' => 5]);
    }

    /** @return list<int> ids the segment resolves to */
    private function resolve(array $segment): array
    {
        $model = (new ContactModel())->setTenant(1)->select('contacts.id');
        $rows  = $this->resolver->apply($model, $segment)->findAll();

        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        sort($ids);
        return $ids;
    }

    // ------------------------------------------------------------------
    // The whole list — only when explicitly asked for
    // ------------------------------------------------------------------

    public function testAllTrueResolvesToEveryContact(): void
    {
        $this->assertSame([1, 2, 3, 4], $this->resolve(['all' => true]));
    }

    public function testEmptySegmentResolvesToEveryContact(): void
    {
        $this->assertSame([1, 2, 3, 4], $this->resolve([]));
    }

    /**
     * The one that matters: a segment naming a filter we do not understand must
     * resolve to nobody. Falling through to every contact would broadcast a
     * campaign meant for a handful to the entire list.
     */
    public function testUnrecognisedSegmentResolvesToNobodyNotEveryone(): void
    {
        $this->assertSame([], $this->resolve(['some_future_filter' => 'value']));
    }

    public function testSegmentWithAnEmptyTagListResolvesToNobody(): void
    {
        $this->assertSame([], $this->resolve(['tag_ids' => []]));
    }

    // ------------------------------------------------------------------
    // Tags — both spellings
    // ------------------------------------------------------------------

    public function testTagIdsListSelectsTaggedContacts(): void
    {
        $this->assertSame([1], $this->resolve(['tag_ids' => [5]]));
    }

    /** The campaign wizard sent this scalar form; it previously matched nothing. */
    public function testScalarTagIdSelectsTaggedContacts(): void
    {
        $this->assertSame([1], $this->resolve(['tag_id' => 5]));
    }

    // ------------------------------------------------------------------
    // Status / source — scalar and list
    // ------------------------------------------------------------------

    public function testStatusScalar(): void
    {
        $this->assertSame([1, 3], $this->resolve(['status' => 'new']));
    }

    /** The wizard's multi-select sent this plural form. */
    public function testStatusesList(): void
    {
        $this->assertSame([1, 3, 4], $this->resolve(['statuses' => ['new', 'won']]));
    }

    public function testSourceScalar(): void
    {
        $this->assertSame([1, 2], $this->resolve(['source' => 'csv_import']));
    }

    // ------------------------------------------------------------------
    // Category
    // ------------------------------------------------------------------

    public function testCategorySelectsThatCategoryOnly(): void
    {
        $this->assertSame([1], $this->resolve(['category' => 'Automobile & Auto Components']));
    }

    public function testCategoriesListSelectsAnyOfThem(): void
    {
        $this->assertSame(
            [1, 2],
            $this->resolve(['categories' => ['Automobile & Auto Components', 'Steel, Metals & Metal Products']])
        );
    }

    public function testCategoryWithNoMatchesResolvesToNobody(): void
    {
        $this->assertSame([], $this->resolve(['category' => 'Government & Public Sector']));
    }

    // ------------------------------------------------------------------
    // Combinations and explicit lists
    // ------------------------------------------------------------------

    public function testFiltersCombineAsAnd(): void
    {
        $this->assertSame([], $this->resolve([
            'category' => 'Automobile & Auto Components',
            'status'   => 'won',
        ]));

        $this->assertSame([1], $this->resolve([
            'category' => 'Automobile & Auto Components',
            'status'   => 'new',
        ]));
    }

    public function testExplicitContactIdsStillWin(): void
    {
        $this->assertSame([2, 4], $this->resolve(['contact_ids' => [2, 4]]));
    }

    public function testEmptyContactIdListResolvesToNobody(): void
    {
        $this->assertSame([], $this->resolve(['contact_ids' => []]));
    }

    // ------------------------------------------------------------------
    // Reply state — the follow-up audience
    // ------------------------------------------------------------------

    public function testNotRepliedSelectsContactsWhoNeverWroteBack(): void
    {
        $this->assertSame([1, 3, 4], $this->resolve(['not_replied' => true]));
    }

    public function testRepliedSelectsOnlyContactsWhoWroteBack(): void
    {
        $this->assertSame([2], $this->resolve(['replied' => true]));
    }

    /** The real cold-nudge segment: one category, minus anyone who answered. */
    public function testCategoryAndNotRepliedCombine(): void
    {
        $this->assertSame([1], $this->resolve([
            'category'    => 'Automobile & Auto Components',
            'not_replied' => true,
        ]));

        $this->assertSame([], $this->resolve([
            'category'    => 'Steel, Metals & Metal Products',
            'not_replied' => true,
        ]), 'The only steel contact has already replied');
    }

    /** A false flag must not silently become "everyone" via the fail-closed guard. */
    public function testNotRepliedFalseIsNotTreatedAsAFilter(): void
    {
        $this->assertSame([], $this->resolve(['not_replied' => false]));
    }

    // ------------------------------------------------------------------
    // countTotal mirrors apply()
    // ------------------------------------------------------------------

    public function testCountTotalMatchesTheResolvedAudience(): void
    {
        $this->assertSame(1, $this->resolver->countTotal(1, ['category' => 'Automobile & Auto Components']));
        $this->assertSame(4, $this->resolver->countTotal(1, ['all' => true]));
    }

    public function testCountTotalOfAnUnreadableSegmentIsZero(): void
    {
        $this->assertSame(0, $this->resolver->countTotal(1, ['mystery' => 'x']));
    }
}
