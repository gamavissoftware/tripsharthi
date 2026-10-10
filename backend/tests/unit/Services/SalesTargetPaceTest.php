<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\SalesTargetService;
use PHPUnit\Framework\TestCase;

/** The pure pacing rules behind the dashboard progress bars. */
final class SalesTargetPaceTest extends TestCase
{
    public function testNoTargetMeansNoStatusAndNoDivisionByZero(): void
    {
        $p = SalesTargetService::pace(500, 0, 10, 31);
        $this->assertSame(['none', 0, 0], [$p['status'], $p['pct'], $p['to_go']]);
    }

    public function testHittingTheTargetIsAchievedEvenOnDayOne(): void
    {
        $this->assertSame('achieved', SalesTargetService::pace(1_000_000, 1_000_000, 1, 30)['status']);
        $this->assertSame(150, SalesTargetService::pace(1_500_000, 1_000_000, 20, 30)['pct']);            // over-achievement is shown, not capped
        $this->assertSame(0, SalesTargetService::pace(1_500_000, 1_000_000, 20, 30)['to_go']);
    }

    public function testPaceIsAStraightLineThroughTheMonth(): void
    {
        // target 300, day 15 of 30 -> expected 150
        $this->assertSame('ahead', SalesTargetService::pace(150, 300, 15, 30)['status']);        // exactly on the line counts as ahead
        $this->assertSame('on_track', SalesTargetService::pace(125, 300, 15, 30)['status']);     // 83% of expected
        $this->assertSame('behind', SalesTargetService::pace(100, 300, 15, 30)['status']);       // 67% of expected
        $p = SalesTargetService::pace(100, 300, 15, 30);
        $this->assertSame([33, 50, 200], [$p['pct'], $p['expected_pct'], $p['to_go']]);
    }

    public function testNothingIsBehindOnTheVeryFirstMomentsOfAMonth(): void
    {
        $this->assertSame('ahead', SalesTargetService::pace(0, 300, 0, 30)['status']);            // day 0: nothing expected yet
    }

    public function testMonthValidation(): void
    {
        foreach (['2026-10', '2027-01', '1999-12'] as $m) { $this->assertTrue(SalesTargetService::validMonth($m)); }
        foreach (['2026-13', '2026-00', '26-10', '2026-1', '*', '', '2026-10-01'] as $m) { $this->assertFalse(SalesTargetService::validMonth($m)); }
    }
}
