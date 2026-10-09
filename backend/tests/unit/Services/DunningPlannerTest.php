<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\DunningPlanner;
use PHPUnit\Framework\TestCase;

final class DunningPlannerTest extends TestCase
{
    private const NOW = 1_791_500_000; // 2026-10-08 ~21:33 UTC

    private function pay(string $due, string $status = 'pending', string $created = '2026-09-01 10:00:00'): array
    {
        return ['status' => $status, 'due_date' => $due, 'created_at' => $created];
    }

    private function keys(array $plan): array { return array_column($plan['run'], 'key'); }

    public function testNothingBeforeFirstStepWindow(): void
    {
        $p = DunningPlanner::plan($this->pay('2026-10-20'), '2026-10-08', DunningPlanner::DEFAULT_STEPS, [], self::NOW);
        $this->assertSame([], $p['run']);
    }

    public function testThreeDaysBeforeDue(): void
    {
        $p = DunningPlanner::plan($this->pay('2026-10-11'), '2026-10-08', DunningPlanner::DEFAULT_STEPS, [], self::NOW);
        $this->assertSame(['before_3d'], $this->keys($p));
    }

    public function testMissedStepsAreSkippedNotBlasted(): void
    {
        // Cron was off: payment is 3 days overdue and nothing was ever sent.
        $p = DunningPlanner::plan($this->pay('2026-10-05', 'overdue'), '2026-10-08', DunningPlanner::DEFAULT_STEPS, [], self::NOW);
        $this->assertSame(['overdue_2d'], $this->keys($p));
        $this->assertEqualsCanonicalizing(['before_3d', 'due_today'], $p['skip']);
    }

    public function testDoneStepsAreNotRepeated(): void
    {
        $p = DunningPlanner::plan($this->pay('2026-10-05', 'overdue'), '2026-10-08', DunningPlanner::DEFAULT_STEPS, ['before_3d', 'due_today', 'overdue_2d'], self::NOW);
        $this->assertSame([], $p['run']);
    }

    public function testEscalationTaskRunsAlongsideLatestMessage(): void
    {
        $p = DunningPlanner::plan($this->pay('2026-10-01', 'overdue'), '2026-10-08', DunningPlanner::DEFAULT_STEPS, ['before_3d', 'due_today', 'overdue_2d'], self::NOW);
        $this->assertEqualsCanonicalizing(['overdue_5d', 'escalate_7d'], $this->keys($p));
    }

    public function testPaidOrWaivedNeverReminded(): void
    {
        foreach (['paid', 'waived'] as $st) {
            $this->assertSame([], DunningPlanner::plan($this->pay('2026-10-01', $st), '2026-10-08', DunningPlanner::DEFAULT_STEPS, [], self::NOW)['run']);
        }
    }

    public function testFreshInstalmentGetsGracePeriod(): void
    {
        $created = date('Y-m-d H:i:s', self::NOW - 3600);
        $p = DunningPlanner::plan($this->pay('2026-10-08', 'pending', $created), '2026-10-08', DunningPlanner::DEFAULT_STEPS, [], self::NOW);
        $this->assertSame([], $p['run']);
    }

    public function testCustomersAreNotMessagedAfterCutoffButHumanTaskStillRuns(): void
    {
        $p = DunningPlanner::plan($this->pay('2026-08-01', 'overdue'), '2026-10-08', DunningPlanner::DEFAULT_STEPS, [], self::NOW);
        $this->assertSame(['escalate_7d'], $this->keys($p));
        $this->assertContains('overdue_5d', $p['skip']);
    }

    public function testSendingHoursUseIst(): void
    {
        // 2026-10-08 21:33 UTC = 03:03 IST next day -> outside 9-20
        $this->assertFalse(DunningPlanner::withinSendingHours(self::NOW, 9, 20));
        // 05:00 UTC = 10:30 IST
        $this->assertTrue(DunningPlanner::withinSendingHours(strtotime('2026-10-08 05:00:00 UTC'), 9, 20));
    }

    public function testFreeFormTextIsTransactionalAndCarriesLink(): void
    {
        $t = DunningPlanner::freeFormText('overdue_2d', ['name' => 'Rohit', 'ref' => 'TP-2026-0001', 'due' => '5 Oct', 'amount' => '₹18,105', 'link' => 'https://rzp.io/i/x']);
        $this->assertStringContainsString('TP-2026-0001', $t);
        $this->assertStringContainsString('https://rzp.io/i/x', $t);
        $this->assertStringContainsString('already paid', $t);
    }
}
