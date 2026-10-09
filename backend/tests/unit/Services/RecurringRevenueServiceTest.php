<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DealModel;
use App\Services\Crm\RecurringRevenueService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Recurring revenue (MRR/ARR) — Phase L subscription deals v1.
 *  - Per-cycle value normalized to monthly by interval.
 *  - Only WON + recurring deals count; ARR = MRR × 12; tenant-scoped.
 */
class RecurringRevenueServiceTest extends CIUnitTestCase
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
        db_connect()->table('deals')->insert(array_merge([
            'tenant_id' => $tenant, 'title' => 'D', 'pipeline_id' => 1, 'stage_id' => 1, 'status' => 'open',
            'value_amount' => 0, 'is_recurring' => 0, 'recurring_interval' => null,
        ], $f));
    }

    // ── Pure normalization ──────────────────────────────────────────────

    public function testMonthlyRevenueNormalization(): void
    {
        $this->assertSame(100000, DealModel::monthlyRevenue(100000, 'monthly'));
        $this->assertSame(100000, DealModel::monthlyRevenue(300000, 'quarterly')); // 300k×4/12
        $this->assertSame(100000, DealModel::monthlyRevenue(1200000, 'annual'));    // 1.2M×1/12
        $this->assertSame(0, DealModel::monthlyRevenue(500000, 'weekly'));          // unknown → 0
        $this->assertSame(0, DealModel::monthlyRevenue(500000, null));
    }

    // ── Aggregation ─────────────────────────────────────────────────────

    public function testSummaryAggregatesWonRecurringOnly(): void
    {
        // Three won recurring deals, each = ₹1,000/mo MRR via different intervals.
        $this->deal(1, ['status' => 'won', 'is_recurring' => 1, 'recurring_interval' => 'monthly',   'value_amount' => 100000]);
        $this->deal(1, ['status' => 'won', 'is_recurring' => 1, 'recurring_interval' => 'quarterly', 'value_amount' => 300000]);
        $this->deal(1, ['status' => 'won', 'is_recurring' => 1, 'recurring_interval' => 'annual',    'value_amount' => 1200000]);
        // Excluded: open recurring, won non-recurring.
        $this->deal(1, ['status' => 'open', 'is_recurring' => 1, 'recurring_interval' => 'monthly', 'value_amount' => 999999]);
        $this->deal(1, ['status' => 'won',  'is_recurring' => 0, 'value_amount' => 888888]);

        $s = (new RecurringRevenueService())->summary(1);

        $this->assertSame(300000, $s['mrr'], 'three deals × ₹1,000/mo');
        $this->assertSame(3600000, $s['arr'], 'ARR = MRR × 12');
        $this->assertSame(3, $s['count']);
        $this->assertSame(100000, $s['by_interval']['annual']);
    }

    public function testSummaryIsTenantScoped(): void
    {
        $this->deal(1, ['status' => 'won', 'is_recurring' => 1, 'recurring_interval' => 'monthly', 'value_amount' => 100000]);
        $this->deal(2, ['status' => 'won', 'is_recurring' => 1, 'recurring_interval' => 'monthly', 'value_amount' => 500000]);

        $this->assertSame(100000, (new RecurringRevenueService())->summary(1)['mrr']);
    }

    public function testEmptyWhenNoRecurringDeals(): void
    {
        $this->deal(1, ['status' => 'won', 'value_amount' => 500000]);
        $s = (new RecurringRevenueService())->summary(1);
        $this->assertSame(0, $s['mrr']);
        $this->assertSame(0, $s['count']);
    }
}
