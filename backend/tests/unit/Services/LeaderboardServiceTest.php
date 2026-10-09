<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Crm\LeaderboardService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase I4: leaderboard composite scoring + ranking + date range.
 */
class LeaderboardServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCrmSchema();
    }

    private function deal(int $tenant, array $f): void
    {
        db_connect()->table('deals')->insert(array_merge(['tenant_id' => $tenant, 'title' => 'D', 'pipeline_id' => 1, 'stage_id' => 1, 'status' => 'open', 'value_amount' => 0, 'created_at' => '2026-06-10 10:00:00'], $f));
    }
    private function task(int $tenant, int $uid, string $completedAt): void
    {
        db_connect()->table('tasks')->insert(['tenant_id' => $tenant, 'title' => 'T', 'status' => 'done', 'assigned_user_id' => $uid, 'completed_at' => $completedAt]);
    }

    public function testRankingAndComposite(): void
    {
        // User 1: won ₹5,00,000 (1 conversion), 2 deals created, 3 completed tasks.
        //   score = floor(500000/10000)=50  + 1*20 + 2*5 + 3*3 = 89
        $this->deal(1, ['owner_id' => 1, 'status' => 'won', 'value_amount' => 50000000, 'won_at' => '2026-06-12 09:00:00', 'created_at' => '2026-06-05 09:00:00']);
        $this->deal(1, ['owner_id' => 1, 'created_at' => '2026-06-10 09:00:00']); // qualified (created in window)
        $this->task(1, 1, '2026-06-11 09:00:00');
        $this->task(1, 1, '2026-06-11 10:00:00');
        $this->task(1, 1, '2026-06-11 11:00:00');

        // User 2: just 1 created deal → score = 5
        $this->deal(1, ['owner_id' => 2, 'created_at' => '2026-06-10 09:00:00']);

        $rows = (new LeaderboardService())->ranking(1, '2026-06-01', '2026-06-30');
        $this->assertSame(1, $rows[0]['user_id'], 'top rep is user 1');
        $this->assertSame(89, $rows[0]['score']);
        $this->assertSame(1, $rows[0]['rank']);
        $this->assertSame(2, $rows[1]['user_id']);
        $this->assertSame(5, $rows[1]['score']);
    }

    public function testDateRangeExcludesOutsideWindow(): void
    {
        $this->deal(1, ['owner_id' => 1, 'status' => 'won', 'value_amount' => 99999999, 'won_at' => '2026-01-01 09:00:00', 'created_at' => '2026-01-01 09:00:00']);
        $rows = (new LeaderboardService())->ranking(1, '2026-06-01', '2026-06-30');
        $this->assertCount(0, $rows, 'a January win does not count for June');
    }
}
