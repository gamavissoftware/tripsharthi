<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\ChecklistPlanner;
use App\Services\Travel\ChecklistService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Planner is pure; the service half is MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class ChecklistTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const NOW = 1_791_439_200;   // 2026-10-08

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['booking_checklist_items', 'travelers', 'bookings', 'contacts', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
    }

    private function booking(bool $intl, string $start = '2026-11-20', int $tenant = 1): int
    {
        $db = db_connect();
        $db->table('bookings')->insert(['tenant_id' => $tenant, 'booking_ref' => 'TP-' . random_int(1000, 9999), 'title' => 'Trip', 'status' => 'confirmed', 'is_international' => $intl ? 1 : 0, 'travel_start' => $start, 'created_at' => '2026-09-01 00:00:00']);
        return (int) $db->insertID();
    }

    private function traveler(int $booking, string $name, string $type = 'adult', string $visa = 'pending'): int
    {
        $db = db_connect();
        $db->table('travelers')->insert(['tenant_id' => 1, 'booking_id' => $booking, 'full_name' => $name, 'pax_type' => $type, 'visa_status' => $visa, 'created_at' => '2026-09-01 00:00:00']);
        return (int) $db->insertID();
    }

    public function testPlannerForInternationalAndDomesticTrips(): void
    {
        $tr = [['id' => 1, 'full_name' => 'Rohit', 'pax_type' => 'adult', 'visa_status' => 'pending'], ['id' => 2, 'full_name' => 'Tia', 'pax_type' => 'child', 'visa_status' => 'not_required']];
        $intl = ChecklistPlanner::plan(['is_international' => 1, 'travel_start' => '2026-12-01'], $tr);
        $keys = array_map(fn ($i) => $i['traveler_id'] . ':' . $i['item_key'], $intl);
        $this->assertContains('1:visa', $keys);
        $this->assertNotContains('2:visa', $keys);            // visa not required for this traveller
        $this->assertContains('2:birth_certificate', $keys);  // minor
        $this->assertContains('0:travel_insurance', $keys);
        $this->assertSame('2026-10-17', $intl[0]['due_date']);  // passport copy due 45 days before
        $dom = ChecklistPlanner::plan(['is_international' => 0, 'travel_start' => null], $tr);
        $this->assertSame(['1:id_proof', '2:id_proof', '0:traveller_details'], array_map(fn ($i) => $i['traveler_id'] . ':' . $i['item_key'], $dom));
        $this->assertNull($dom[0]['due_date']);
    }

    public function testGenerateIsIdempotentAndKeepsProgress(): void
    {
        $b = $this->booking(true); $this->traveler($b, 'Rohit');
        $svc = new ChecklistService(self::NOW);
        $this->assertGreaterThan(0, $svc->generate(1, $b));
        $first = $svc->forBooking(1, $b);
        $svc->setStatus(1, $first['items'][0]['id'], 'received', 'scan on WhatsApp', 7);
        $this->assertSame(0, $svc->generate(1, $b));                       // nothing duplicated
        $after = $svc->forBooking(1, $b);
        $this->assertCount(count($first['items']), $after['items']);
        $this->assertSame('received', $after['items'][0]['status']);       // progress survived regeneration
        $this->traveler($b, 'Priya');
        $this->assertGreaterThan(0, $svc->generate(1, $b));                // a new traveller gets their own items
    }

    public function testProgressReadinessAndOverdue(): void
    {
        $b = $this->booking(false, '2026-10-12'); $this->traveler($b, 'Rohit');
        $svc = new ChecklistService(self::NOW);
        $svc->generate(1, $b);
        $c = $svc->forBooking(1, $b);
        $this->assertFalse($c['progress']['ready']);
        $this->assertTrue($c['items'][0]['overdue']);                      // id proof due 10 days before 12 Oct = 2 Oct < today
        foreach ($c['items'] as $i) { $c = $svc->setStatus(1, $i['id'], $i['key'] === 'traveller_details' ? 'not_applicable' : 'received', null, 1); }
        $this->assertSame([true, 100], [$c['progress']['ready'], $c['progress']['percent']]);
        $this->assertSame([], $svc->overview(1)['bookings']);               // ready bookings drop off the overview
    }

    public function testOverviewListsOnlyUpcomingNotReadyAndTenantScoped(): void
    {
        $soon = $this->booking(true, '2026-10-25'); $this->traveler($soon, 'Rohit');
        $this->booking(true, '2027-06-01');                                 // outside the window
        $other = $this->booking(true, '2026-10-20', 2);
        $svc = new ChecklistService(self::NOW);
        $o = $svc->overview(1)['bookings'];
        $this->assertCount(1, $o);
        $this->assertSame($soon, $o[0]['booking_id']);
        $this->assertSame(17, $o[0]['days_to_departure']);
        $this->assertFalse($o[0]['generated']);
        $this->expectException(\InvalidArgumentException::class);
        $svc->generate(1, $other);                                          // tenant 1 cannot generate for tenant 2's booking
    }

    public function testBadStatusAndUnknownItemRefused(): void
    {
        $b = $this->booking(false); $this->traveler($b, 'Rohit');
        $svc = new ChecklistService(self::NOW); $svc->generate(1, $b);
        $id = $svc->forBooking(1, $b)['items'][0]['id'];
        try { $svc->setStatus(1, $id, 'done', null, 1); $this->fail(); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->expectException(\InvalidArgumentException::class);
        $svc->setStatus(2, $id, 'received', null, 1);                       // other tenant
    }
}
