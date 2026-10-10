<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\TripService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Open-task summary shown on the Trips board (group "mysql"). */
#[Group('mysql')]
final class TripTaskSummaryTest extends CIUnitTestCase
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
        foreach (['tasks', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
    }

    private function task(array $o): int
    {
        db_connect()->table('tasks')->insert($o + ['tenant_id' => 1, 'title' => 'Call', 'type' => 'call', 'status' => 'open', 'priority' => 'medium', 'related_type' => 'deal', 'related_id' => 10, 'created_at' => '2026-10-01 10:00:00']);
        return (int) db_connect()->insertID();
    }

    public function testNextIsTheEarliestDueAndUndatedTasksComeLast(): void
    {
        $this->task(['title' => 'Undated', 'due_at' => null]);
        $b = $this->task(['title' => 'Later', 'due_at' => '2026-10-20 10:00:00']);
        $a = $this->task(['title' => 'Sooner', 'type' => 'whatsapp', 'due_at' => '2026-10-12 09:00:00']);
        $s = (new TripService())->taskSummary(1, [10]);
        $this->assertSame(3, $s[10]['open']);
        $this->assertSame([$a, 'Sooner', 'whatsapp', '2026-10-12 09:00:00'], array_values($s[10]['next']));
        $this->assertNotSame($b, $s[10]['next']['id']);
    }

    public function testDoneDeletedOtherDealOtherTenantAndOtherTypesAreIgnored(): void
    {
        $this->task(['status' => 'done', 'due_at' => '2026-10-11 10:00:00']);
        $this->task(['deleted_at' => '2026-10-02 10:00:00', 'due_at' => '2026-10-11 10:00:00']);
        $this->task(['related_id' => 11, 'due_at' => '2026-10-11 10:00:00']);
        $this->task(['tenant_id' => 2, 'due_at' => '2026-10-11 10:00:00']);
        $this->task(['related_type' => 'contact', 'due_at' => '2026-10-11 10:00:00']);
        $s = (new TripService())->taskSummary(1, [10, 0, 10]);
        $this->assertSame([], $s);                                    // nothing open on deal 10 for tenant 1
        $this->assertSame([11], array_keys((new TripService())->taskSummary(1, [11])));
        $this->assertSame([], (new TripService())->taskSummary(1, []));
    }
}
