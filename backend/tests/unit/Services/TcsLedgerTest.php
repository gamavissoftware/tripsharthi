<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\Docs\TcsLedger;
use CodeIgniter\Test\CIUnitTestCase;

final class TcsLedgerTest extends CIUnitTestCase
{
    public function testTcsIsSpreadOverReceiptsAndSumsExactlyWhenFullyPaid(): void
    {
        // total ₹51,729.30 incl. TCS ₹1,014.30, paid in 3 uneven instalments
        $total = 5_172_930; $tcs = 101_430;
        $pays = [['id' => 1, 'amount' => 1_551_879, 'paid_at' => '2026-10-01 10:00:00'], ['id' => 2, 'amount' => 2_000_001, 'paid_at' => '2026-11-03 10:00:00'], ['id' => 3, 'amount' => 1_621_050, 'paid_at' => '2026-12-20 10:00:00']];
        $rows = TcsLedger::split($total, $tcs, $pays);
        $this->assertSame($tcs, array_sum(array_column($rows, 'tcs')));
        $this->assertSame(30_429, $rows[0]['tcs']);                     // 30% of 1,01,430, rounded
    }

    public function testPartPaymentsCollectOnlyTheirShareAndNeverExceedTheTotal(): void
    {
        $rows = TcsLedger::split(1_000_000, 20_000, [['id' => 1, 'amount' => 500_000, 'paid_at' => '2026-10-01 10:00:00']]);
        $this->assertSame(10_000, $rows[0]['tcs']);
        $over = TcsLedger::split(1_000_000, 20_000, [['id' => 1, 'amount' => 900_000, 'paid_at' => '2026-10-01'], ['id' => 2, 'amount' => 900_000, 'paid_at' => '2026-10-02']]);
        $this->assertSame(20_000, array_sum(array_column($over, 'tcs')));  // an overpayment cannot create extra TCS
    }

    public function testNoTcsMeansNoRows(): void
    {
        $this->assertSame([], TcsLedger::split(1_000_000, 0, [['id' => 1, 'amount' => 500_000, 'paid_at' => '2026-10-01']]));
    }

    public function testQuartersFollowTheIndianFinancialYear(): void
    {
        $this->assertSame(['2026-04-01', '2026-06-30'], TcsLedger::quarter(2026, 1));
        $this->assertSame(['2026-10-01', '2026-12-31'], TcsLedger::quarter(2026, 3));
        $this->assertSame(['2027-01-01', '2027-03-31'], TcsLedger::quarter(2026, 4));
        $this->assertSame(['2027-01', '2027-02', '2027-03'], TcsLedger::months(2026, 4));
        $this->assertSame(2026, TcsLedger::currentFy('2026-10-08'));
        $this->assertSame(2025, TcsLedger::currentFy('2026-03-31'));
    }

    public function testDueDateAndStatus(): void
    {
        $this->assertSame('2026-11-07', TcsLedger::dueDate('2026-10'));
        $this->assertSame('2027-01-07', TcsLedger::dueDate('2026-12'));
        $this->assertSame('nil', TcsLedger::status(0, 0, '2026-11-07', '2026-12-01'));
        $this->assertSame('due', TcsLedger::status(100, 0, '2026-11-07', '2026-11-07'));
        $this->assertSame('overdue', TcsLedger::status(100, 0, '2026-11-07', '2026-11-08'));
        $this->assertSame('partial', TcsLedger::status(100, 40, '2026-11-07', '2026-11-01'));
        $this->assertSame('paid', TcsLedger::status(100, 100, '2026-11-07', '2026-12-01'));
    }
}
