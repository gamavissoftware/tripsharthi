<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\PipelineStageModel;
use App\Models\SalesTargetModel;
use App\Services\Crm\ForecastService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase I3: weighted-forecast math + per-rep attainment.
 */
class ForecastServiceTest extends CIUnitTestCase
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

    private function stage(int $tenant, int $prob): int
    {
        return (int) (new PipelineStageModel())->setTenant($tenant)->insert(['pipeline_id' => 1, 'name' => "P{$prob}", 'probability' => $prob], true);
    }

    private function deal(int $tenant, array $f): void
    {
        // Raw insert so we control status/won_at/value without model timestamp overwrite.
        db_connect()->table('deals')->insert(array_merge(['tenant_id' => $tenant, 'title' => 'D', 'pipeline_id' => 1, 'status' => 'open', 'value_amount' => 0], $f));
    }

    public function testWeightedForecast(): void
    {
        $s20 = $this->stage(1, 20);
        $s80 = $this->stage(1, 80);
        $this->deal(1, ['owner_id' => 5, 'stage_id' => $s20, 'value_amount' => 100000, 'status' => 'open']); // 20% → 20000
        $this->deal(1, ['owner_id' => 5, 'stage_id' => $s80, 'value_amount' => 100000, 'status' => 'open']); // 80% → 80000
        $this->deal(1, ['owner_id' => 5, 'stage_id' => $s80, 'value_amount' => 50000, 'status' => 'won']);   // won → excluded

        $f = (new ForecastService())->weightedForecast(1);
        $this->assertSame(200000, $f['total_open'], 'open value sums the two open deals');
        $this->assertSame(100000, $f['total_weighted'], '20k + 80k weighted');
    }

    public function testAttainment(): void
    {
        $s = $this->stage(1, 50);
        // Won 600000 in June for user 5.
        $this->deal(1, ['owner_id' => 5, 'stage_id' => $s, 'value_amount' => 600000, 'status' => 'won', 'won_at' => '2026-06-10 10:00:00']);
        // An open deal (weighted 50%).
        $this->deal(1, ['owner_id' => 5, 'stage_id' => $s, 'value_amount' => 200000, 'status' => 'open']);
        // Target 1,000,000 for June.
        (new SalesTargetModel())->setTenant(1)->insert(['user_id' => 5, 'metric' => 'won_value', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'target_amount' => 1000000]);

        $rows = (new ForecastService())->attainment(1, '2026-06-01', '2026-06-30');
        $u5 = array_values(array_filter($rows, static fn ($r) => $r['user_id'] === 5))[0];
        $this->assertSame(600000, $u5['actual']);
        $this->assertSame(1000000, $u5['target']);
        $this->assertSame(60.0, $u5['attainment_pct']);
        $this->assertSame(100000, $u5['weighted'], 'open 200k × 50%');
    }
}
