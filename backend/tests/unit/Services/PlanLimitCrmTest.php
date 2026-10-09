<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DashboardModel;
use App\Models\DealModel;
use App\Services\Billing\PlanLimitChecker;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase K2: plan-limit enforcement for the new CRM dimensions, per tier.
 */
class PlanLimitCrmTest extends CIUnitTestCase
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

    private function setPlan(int $tenantId, string $plan): void
    {
        db_connect()->table('tenants')->where('id', $tenantId)->update(['plan' => $plan]);
    }

    public function testDashboardBoundaryOnFree(): void
    {
        $chk = new PlanLimitChecker();
        // free dashboards limit = 1; none yet → allowed.
        $r = $chk->check(1, 'dashboards');
        $this->assertTrue($r['allowed']);
        $this->assertSame(1, $r['limit']);

        (new DashboardModel())->setTenant(1)->insert(['name' => 'D', 'is_default' => 1]);
        $r = $chk->check(1, 'dashboards');
        $this->assertFalse($r['allowed'], 'at limit → blocked');
        $this->assertSame(1, $r['current']);
    }

    public function testCustomObjectsGatedByTier(): void
    {
        $chk = new PlanLimitChecker();
        // free custom_objects limit = 0 → always blocked.
        $this->assertFalse($chk->check(1, 'custom_objects')['allowed']);

        // growth raises it to 10 → now allowed.
        $this->setPlan(1, 'growth');
        $r = $chk->check(1, 'custom_objects');
        $this->assertTrue($r['allowed']);
        $this->assertSame(10, $r['limit']);
    }

    public function testUsageCountsIncludeCrmDimensions(): void
    {
        (new DealModel())->setTenant(1)->insert(['title' => 'A', 'pipeline_id' => 1, 'stage_id' => 1, 'status' => 'open'], true);
        (new DealModel())->setTenant(1)->insert(['title' => 'B', 'pipeline_id' => 1, 'stage_id' => 1, 'status' => 'open'], true);
        (new DashboardModel())->setTenant(1)->insert(['name' => 'D']);

        $usage = (new PlanLimitChecker())->usageCounts(1);
        $this->assertSame(2, $usage['deals']);
        $this->assertSame(1, $usage['dashboards']);
        $this->assertArrayHasKey('custom_objects', $usage);
        $this->assertArrayHasKey('pipelines', $usage);
    }
}
