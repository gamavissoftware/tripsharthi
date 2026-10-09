<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DealModel;
use App\Services\Crm\ReportService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase I2: report aggregation correctness — metrics, dimensions, win-rate,
 * date range, whitelist, tenant scope.
 */
class ReportServiceTest extends CIUnitTestCase
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
        (new DealModel())->setTenant($tenant)->insert(array_merge(['title' => 'D', 'pipeline_id' => 1, 'stage_id' => 1, 'status' => 'open', 'value_amount' => 0], $f), true);
    }

    public function testCountAndSumByNone(): void
    {
        $this->deal(1, ['value_amount' => 100000, 'stage_id' => 1]);
        $this->deal(1, ['value_amount' => 300000, 'stage_id' => 2]);

        $svc = new ReportService();
        $this->assertSame(2, $svc->run(1, ['entity' => 'deal', 'metric' => 'count', 'dimension' => 'none'])['total']);
        $this->assertSame(400000, $svc->run(1, ['entity' => 'deal', 'metric' => 'sum_value', 'dimension' => 'none'])['total']);
    }

    public function testSumByStageDimension(): void
    {
        $this->deal(1, ['value_amount' => 100000, 'stage_id' => 1]);
        $this->deal(1, ['value_amount' => 50000, 'stage_id' => 1]);
        $this->deal(1, ['value_amount' => 300000, 'stage_id' => 2]);

        $res = (new ReportService())->run(1, ['entity' => 'deal', 'metric' => 'sum_value', 'dimension' => 'stage']);
        $byVal = array_column($res['series'], 'value');
        sort($byVal);
        $this->assertSame([150000, 300000], $byVal);
        $this->assertSame(450000, $res['total']);
    }

    public function testWinRate(): void
    {
        $this->deal(1, ['status' => 'won']);
        $this->deal(1, ['status' => 'won']);
        $this->deal(1, ['status' => 'lost']);
        $this->deal(1, ['status' => 'open']);

        // 2 won of 4 → 50%.
        $this->assertSame(50, (new ReportService())->run(1, ['entity' => 'deal', 'metric' => 'win_rate', 'dimension' => 'none'])['total']);
    }

    public function testDateRangeFiltersAndTenantScope(): void
    {
        $now = strtotime('2026-06-01 12:00:00');
        // Raw insert: the model would overwrite created_at with "now".
        $db = db_connect();
        $db->table('deals')->insert(['tenant_id' => 1, 'title' => 'D', 'pipeline_id' => 1, 'stage_id' => 1, 'status' => 'open', 'value_amount' => 100000, 'created_at' => '2026-05-31 10:00:00']); // in last_7
        $db->table('deals')->insert(['tenant_id' => 1, 'title' => 'D', 'pipeline_id' => 1, 'stage_id' => 1, 'status' => 'open', 'value_amount' => 200000, 'created_at' => '2026-01-01 10:00:00']); // out
        $db->table('deals')->insert(['tenant_id' => 2, 'title' => 'D', 'pipeline_id' => 1, 'stage_id' => 1, 'status' => 'open', 'value_amount' => 999999, 'created_at' => '2026-05-31 10:00:00']); // other tenant

        $res = (new ReportService())->run(1, ['entity' => 'deal', 'metric' => 'sum_value', 'dimension' => 'none', 'preset' => 'last_7'], $now);
        $this->assertSame(100000, $res['total'], 'only the recent tenant-1 deal counts');
    }

    public function testCsvExportIntegrity(): void
    {
        $this->deal(1, ['value_amount' => 100000, 'stage_id' => 1]);
        $this->deal(1, ['value_amount' => 300000, 'stage_id' => 2]);

        $svc = new ReportService();
        $csv = $svc->toCsv($svc->run(1, ['entity' => 'deal', 'metric' => 'sum_value', 'dimension' => 'stage']));
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertStringContainsString('Label', $lines[0]);
        $this->assertStringContainsString('Sum_value', $lines[0]);
        $this->assertCount(3, $lines, 'header + 2 stage rows');
        $this->assertStringContainsString('300000', $csv);
    }

    public function testMonthDimensionIsChronological(): void
    {
        // Raw insert to control created_at (model would overwrite it).
        $db = db_connect();
        foreach ([['2026-01-10', 100000], ['2026-03-10', 500000], ['2026-02-10', 50000]] as [$d, $v]) {
            $db->table('deals')->insert(['tenant_id' => 1, 'title' => 'D', 'pipeline_id' => 1, 'stage_id' => 1, 'status' => 'open', 'value_amount' => $v, 'created_at' => $d . ' 10:00:00']);
        }
        $res    = (new ReportService())->run(1, ['entity' => 'deal', 'metric' => 'sum_value', 'dimension' => 'month']);
        $labels = array_column($res['series'], 'label');
        // Chronological, NOT by value (March's 500k would otherwise sort first).
        $this->assertSame(['2026-01', '2026-02', '2026-03'], $labels);
    }

    public function testDayDimensionBucketsChronologically(): void
    {
        $db = db_connect();
        // Two deals on the 10th, one on the 5th — same month, different days.
        foreach ([['2026-02-10', 100000], ['2026-02-05', 50000], ['2026-02-10', 25000]] as [$d, $v]) {
            $db->table('deals')->insert(['tenant_id' => 1, 'title' => 'D', 'pipeline_id' => 1, 'stage_id' => 1, 'status' => 'open', 'value_amount' => $v, 'created_at' => $d . ' 10:00:00']);
        }
        $res = (new ReportService())->run(1, ['entity' => 'deal', 'metric' => 'sum_value', 'dimension' => 'day']);

        $this->assertSame(['2026-02-05', '2026-02-10'], array_column($res['series'], 'label'), 'day buckets, oldest first');
        $this->assertSame(50000, $res['series'][0]['value']);
        $this->assertSame(125000, $res['series'][1]['value'], 'both 10th deals summed into one bucket');
    }

    public function testWeekDimensionIsAccepted(): void
    {
        $this->deal(1, ['value_amount' => 100000]);
        // Week bucketing is driver-specific SQL; assert it runs and returns a series.
        $res = (new ReportService())->run(1, ['entity' => 'deal', 'metric' => 'sum_value', 'dimension' => 'week']);
        $this->assertNotEmpty($res['series']);
        $this->assertSame(100000, $res['total']);
    }

    public function testUnknownEntityRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new ReportService())->run(1, ['entity' => 'messages', 'metric' => 'count', 'dimension' => 'none']);
    }

    public function testWhitelistFallsBackOnBadMetricDimension(): void
    {
        $this->deal(1, ['value_amount' => 100000]);
        // Garbage metric/dimension → falls back to count/none, never errors.
        $res = (new ReportService())->run(1, ['entity' => 'deal', 'metric' => 'DROP TABLE', 'dimension' => 'hacker']);
        $this->assertSame(1, $res['total']);
    }
}
