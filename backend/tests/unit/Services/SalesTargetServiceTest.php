<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\SalesTargetService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Monthly targets stored in the shared sales_targets table (group "mysql"). */
#[Group('mysql')]
final class SalesTargetServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['sales_targets', 'audit_logs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        foreach ([[9, 1, 'Anita', 'owner'], [10, 1, 'Ravi', 'agent'], [11, 2, 'Other', 'owner']] as [$id, $t, $n, $r]) {
            $db->table('users')->insert(['id' => $id, 'tenant_id' => $t, 'name' => $n, 'email' => "u$id@x.test", 'password_hash' => 'x', 'role' => $r, 'created_at' => '2026-01-01 00:00:00']);
        }
    }

    private function svc(): SalesTargetService { return new SalesTargetService(); }

    public function testSaveThenReadBackForThatMonth(): void
    {
        $r = $this->svc()->save(1, '2026-10', [['user_id' => 10, 'revenue' => 50_000_000, 'bookings' => 6]], 9);
        $ravi = array_values(array_filter($r['members'], static fn ($m) => $m['id'] === 10))[0];
        $this->assertSame([50_000_000, 6, 'month'], [$ravi['revenue'], $ravi['bookings'], $ravi['source']]);
        $anita = array_values(array_filter($r['members'], static fn ($m) => $m['id'] === 9))[0];
        $this->assertSame([0, 0, null], [$anita['revenue'], $anita['bookings'], $anita['source']]);   // nobody else got a target
        $this->assertSame(2, db_connect()->table('sales_targets')->where('tenant_id', 1)->countAllResults());   // one row per metric
    }

    public function testSavingAgainUpdatesInsteadOfDuplicating(): void
    {
        $this->svc()->save(1, '2026-10', [['user_id' => 10, 'revenue' => 100, 'bookings' => 1]], 9);
        $this->svc()->save(1, '2026-10', [['user_id' => 10, 'revenue' => 200, 'bookings' => 2]], 9);
        $this->assertSame(2, db_connect()->table('sales_targets')->where('tenant_id', 1)->countAllResults());
        $this->assertSame([200, 2], [$this->svc()->forMonth(1, '2026-10')[10]['revenue'], $this->svc()->forMonth(1, '2026-10')[10]['bookings']]);
    }

    public function testLaterMonthsCarryOverUntilChanged(): void
    {
        $this->svc()->save(1, '2026-10', [['user_id' => 10, 'revenue' => 500, 'bookings' => 5]], 9);
        $nov = $this->svc()->forMonth(1, '2026-11')[10];
        $this->assertSame([500, 5, 'carried', '2026-10'], [$nov['revenue'], $nov['bookings'], $nov['source'], $nov['from']]);
        $this->assertArrayNotHasKey(10, $this->svc()->forMonth(1, '2026-09'));                      // never backwards
        $this->svc()->save(1, '2026-12', [['user_id' => 10, 'revenue' => 900, 'bookings' => 9]], 9);
        $this->assertSame(500, $this->svc()->forMonth(1, '2026-11')[10]['revenue']);                 // November keeps its own carried value
        $this->assertSame([900, 'month'], [$this->svc()->forMonth(1, '2026-12')[10]['revenue'], $this->svc()->forMonth(1, '2026-12')[10]['source']]);
        $this->assertSame('2026-12', $this->svc()->forMonth(1, '2027-02')[10]['from']);              // the newest earlier month wins
    }

    public function testAnExplicitZeroStopsTheCarryOver(): void
    {
        $this->svc()->save(1, '2026-10', [['user_id' => 10, 'revenue' => 500, 'bookings' => 5]], 9);
        $this->svc()->save(1, '2026-11', [['user_id' => 10, 'revenue' => 0, 'bookings' => 0]], 9);
        $this->assertArrayNotHasKey(10, $this->svc()->forMonth(1, '2026-11'));
        $this->assertArrayNotHasKey(10, $this->svc()->forMonth(1, '2026-12'));
        $this->assertSame(500, $this->svc()->forMonth(1, '2026-10')[10]['revenue']);                 // October is untouched
    }

    public function testRevenueAndBookingsCarryIndependently(): void
    {
        $this->svc()->save(1, '2026-10', [['user_id' => 10, 'revenue' => 500, 'bookings' => 5]], 9);
        $this->svc()->save(1, '2026-11', [['user_id' => 10, 'revenue' => 700, 'bookings' => 0]], 9);
        $nov = $this->svc()->forMonth(1, '2026-11')[10];
        $this->assertSame([700, 0, 'month'], [$nov['revenue'], $nov['bookings'], $nov['source']]);
    }

    public function testOnlyPeopleOfThisWorkspaceAndSaneNumbersAreAccepted(): void
    {
        foreach ([[['user_id' => 11, 'revenue' => 1, 'bookings' => 1]], [['user_id' => 999, 'revenue' => 1, 'bookings' => 1]], [['user_id' => 10, 'revenue' => -5, 'bookings' => 1]],
                  [['user_id' => 10, 'revenue' => 1.5, 'bookings' => 1]], [['user_id' => 10, 'revenue' => 1, 'bookings' => 'lots']], [['user_id' => 10, 'revenue' => SalesTargetService::MAX_REVENUE + 1, 'bookings' => 1]],
                  [['user_id' => 10, 'revenue' => 1, 'bookings' => SalesTargetService::MAX_BOOKINGS + 1]]] as $items) {
            try { $this->svc()->save(1, '2026-10', $items, 9); $this->fail('accepted ' . json_encode($items)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(0, db_connect()->table('sales_targets')->countAllResults());                // a rejected request writes nothing
        try { $this->svc()->save(1, '*', [], 9); $this->fail('accepted a bad month'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
    }

    public function testTenantsNeverSeeEachOthersTargets(): void
    {
        $this->svc()->save(1, '2026-10', [['user_id' => 10, 'revenue' => 500, 'bookings' => 5]], 9);
        $this->svc()->save(2, '2026-10', [['user_id' => 11, 'revenue' => 777, 'bookings' => 7]], 11);
        $this->assertSame([10], array_keys($this->svc()->forMonth(1, '2026-10')));
        $this->assertSame([11], array_keys($this->svc()->forMonth(2, '2026-10')));
    }

    public function testRowsThatAreNotWholeCalendarMonthsAreIgnored(): void
    {
        // e.g. a quarterly row made through the inherited /crm/sales-targets API must not be mistaken for a monthly target
        db_connect()->table('sales_targets')->insert(['tenant_id' => 1, 'user_id' => 10, 'metric' => 'won_value', 'period_start' => '2026-10-01', 'period_end' => '2026-12-31', 'target_amount' => 123, 'created_at' => '2026-10-01 00:00:00']);
        $this->assertSame([], $this->svc()->forMonth(1, '2026-10'));
    }
}
