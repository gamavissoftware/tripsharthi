<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\TravelHomeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Home dashboard: scoping and what an agent must never see (group "mysql"). */
#[Group('mysql')]
final class TravelHomeServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const NOW = 1_791_439_200;   // 2026-10-08 11:30 IST

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['booking_checklist_items', 'booking_payments', 'booking_services', 'bookings', 'itineraries', 'tasks', 'trips', 'contacts', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        foreach ([[9, 1, 'Anita', 'owner'], [10, 1, 'Ravi', 'agent'], [11, 2, 'Other', 'owner']] as [$id, $t, $n, $r]) {
            $db->table('users')->insert(['id' => $id, 'tenant_id' => $t, 'name' => $n, 'email' => "u$id@x.test", 'password_hash' => 'x', 'role' => $r, 'created_at' => '2026-01-01 00:00:00']);
        }
    }

    private function trip(int $tenant, ?int $owner, string $status = 'enquiry', string $created = '2026-10-02 10:00:00'): int
    {
        db_connect()->table('trips')->insert(['tenant_id' => $tenant, 'owner_id' => $owner, 'title' => 'T', 'status' => $status, 'adults' => 2, 'budget_max' => 100000, 'created_at' => $created, 'updated_at' => $created]);
        return (int) db_connect()->insertID();
    }

    private function booking(int $tenant, ?int $owner, int $subtotal, int $cost, string $created = '2026-10-03 10:00:00'): int
    {
        db_connect()->table('bookings')->insert(['tenant_id' => $tenant, 'owner_id' => $owner, 'booking_ref' => 'B' . random_int(1000, 9999), 'title' => 'Trip', 'status' => 'confirmed', 'subtotal' => $subtotal, 'total_amount' => $subtotal, 'cost_total' => $cost, 'created_at' => $created, 'updated_at' => $created]);
        return (int) db_connect()->insertID();
    }

    private function seed(): void
    {
        $this->trip(1, 9); $this->trip(1, 9, 'quoted'); $this->trip(1, 10); $this->trip(1, 10); $this->trip(1, 10, 'negotiating'); $this->trip(2, 11);
        $this->booking(1, 9, 1_000_000, 800_000); $this->booking(1, 10, 500_000, 400_000); $this->booking(2, 11, 9_999_999, 1);
        db_connect()->table('tasks')->insert(['tenant_id' => 1, 'title' => 'Late', 'type' => 'call', 'status' => 'open', 'priority' => 'medium', 'assigned_user_id' => 10, 'due_at' => '2026-10-07 10:00:00', 'created_at' => '2026-10-01 10:00:00']);
        db_connect()->table('tasks')->insert(['tenant_id' => 1, 'title' => 'Mine', 'type' => 'call', 'status' => 'open', 'priority' => 'medium', 'assigned_user_id' => 9, 'due_at' => '2026-10-07 10:00:00', 'created_at' => '2026-10-01 10:00:00']);
    }

    public function testManagerSeesTheWholeWorkspaceAndNeverAnotherTenant(): void
    {
        $this->seed();
        $d = (new TravelHomeService(self::NOW))->build(1, 9, true);
        $this->assertSame(5, $d['kpis']['enquiries']['value']);                       // tenant 2's trip is not counted
        $this->assertSame(2, $d['kpis']['bookings']['value']);
        $this->assertSame(1_500_000, $d['kpis']['revenue']['value']);                 // ex-tax, tenant 2's 9,999,999 excluded
        $this->assertSame(300_000, $d['kpis']['margin']['value']);
        $this->assertSame(2, $d['attention']['overdue_tasks']);
        $this->assertArrayHasKey('payables', $d);
        $this->assertEqualsCanonicalizing(['Anita', 'Ravi'], array_map(static fn ($u) => $u['name'], $d['team']));
        $this->assertCount(2, $d['team']);                                             // owner + agent, not the other tenant's user
    }

    public function testAgentGetsOnlyOwnNumbersAndNoCostMarginPayablesOrTeam(): void
    {
        $this->seed();
        $d = (new TravelHomeService(self::NOW))->build(1, 10, false);
        $this->assertSame(3, $d['kpis']['enquiries']['value']);
        $this->assertSame(1, $d['kpis']['bookings']['value']);
        $this->assertSame(500_000, $d['kpis']['revenue']['value']);
        $this->assertSame(1, $d['attention']['overdue_tasks']);
        foreach (['margin', 'bookings_without_cost'] as $k) { $this->assertArrayNotHasKey($k, $d['kpis']); }
        foreach (['payables', 'team'] as $k) { $this->assertArrayNotHasKey($k, $d); }
        $this->assertStringNotContainsString('cost', strtolower(json_encode($d)));     // no cost field anywhere in an agent's payload
    }

    public function testAnAgentCannotAskForSomeoneElse(): void
    {
        $this->seed();
        $d = (new TravelHomeService(self::NOW))->build(1, 10, false, 9);               // focus on the owner: ignored for an agent
        $this->assertSame(10, $d['scope']['user_id']);
        $this->assertSame(3, $d['kpis']['enquiries']['value']);
    }

    public function testManagerCanFocusOnOnePerson(): void
    {
        $this->seed();
        $d = (new TravelHomeService(self::NOW))->build(1, 9, true, 10);
        $this->assertSame([3, 1, 500_000, 100_000], [$d['kpis']['enquiries']['value'], $d['kpis']['bookings']['value'], $d['kpis']['revenue']['value'], $d['kpis']['margin']['value']]);
        $this->assertArrayNotHasKey('team', $d);                                        // the team table is for the "everyone" view
    }

    public function testPipelineAndFollowUpsCountOnlyOpenTripsWithNoNextStep(): void
    {
        $this->seed();
        $d = (new TravelHomeService(self::NOW))->build(1, 9, true);
        $this->assertSame(['enquiry' => 3, 'quoted' => 1, 'negotiating' => 1], array_column(array_slice($d['pipeline'], 0, 3), 'count', 'status'));
        $this->assertSame(0, $d['attention']['no_activity']);                           // seeded trips have no deal, so they are not "missing a next step"
    }
}
