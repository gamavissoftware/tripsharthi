<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DealModel;
use App\Services\Crm\RecurringDealRenewalService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Recurring deals part 2: renewal-deal generation.
 *  - A due, won, recurring deal spawns one OPEN renewal and advances its date.
 *  - Idempotent (re-run before the next cycle creates nothing).
 *  - Only won + recurring + due deals qualify.
 */
class RecurringDealRenewalServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCrmSchema();
        // A pipeline with a first stage for renewals to land in.
        db_connect()->table('pipelines')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Sales', 'is_default' => 1]);
        db_connect()->table('pipeline_stages')->insert(['id' => 1, 'tenant_id' => 1, 'pipeline_id' => 1, 'name' => 'New', 'position' => 1]);
    }

    private function deal(array $f): int
    {
        return (int) db_connect()->table('deals')->insert(array_merge([
            'tenant_id' => 1, 'title' => 'Sub', 'pipeline_id' => 1, 'stage_id' => 1, 'status' => 'won',
            'value_amount' => 100000, 'is_recurring' => 1, 'recurring_interval' => 'monthly',
        ], $f), true) ? (int) db_connect()->insertID() : 0;
    }

    // ── advanceRenewal math ─────────────────────────────────────────────

    public function testAdvanceRenewalByInterval(): void
    {
        $this->assertSame('2026-07-15', DealModel::advanceRenewal('2026-06-15', 'monthly'));
        $this->assertSame('2026-09-15', DealModel::advanceRenewal('2026-06-15', 'quarterly'));
        $this->assertSame('2027-06-15', DealModel::advanceRenewal('2026-06-15', 'annual'));
        $this->assertNull(DealModel::advanceRenewal('2026-06-15', 'weekly'));
        $this->assertNull(DealModel::advanceRenewal(null, 'monthly'));
    }

    // ── Generation ──────────────────────────────────────────────────────

    public function testDueDealSpawnsOpenRenewalAndAdvancesDate(): void
    {
        $src = $this->deal(['next_renewal_at' => '2026-06-01', 'recurring_interval' => 'monthly']);

        $n = (new RecurringDealRenewalService())->generateDue(1, '2026-06-10');
        $this->assertSame(1, $n);

        $renewal = db_connect()->table('deals')->where('source', 'renewal')->get()->getRowArray();
        $this->assertNotNull($renewal);
        $this->assertSame('open', $renewal['status']);
        $this->assertSame(1, (int) $renewal['is_recurring']);
        $this->assertSame(100000, (int) $renewal['value_amount']);
        $this->assertStringContainsString('(renewal)', $renewal['title']);

        // Source advanced one interval beyond its old date.
        $after = db_connect()->table('deals')->where('id', $src)->get()->getRowArray();
        $this->assertSame('2026-07-01', $after['next_renewal_at']);
    }

    public function testIdempotentBeforeNextCycle(): void
    {
        $this->deal(['next_renewal_at' => '2026-06-01']);
        $svc = new RecurringDealRenewalService();

        $this->assertSame(1, $svc->generateDue(1, '2026-06-10'));
        // Second run same day: source now due 2026-07-01, not <= today → nothing.
        $this->assertSame(0, $svc->generateDue(1, '2026-06-10'));
    }

    public function testExcludesNotDueOpenAndNonRecurring(): void
    {
        $this->deal(['next_renewal_at' => '2026-12-01']);                 // future → not due
        $this->deal(['status' => 'open', 'next_renewal_at' => '2026-06-01']); // open → excluded
        $this->deal(['is_recurring' => 0, 'next_renewal_at' => '2026-06-01']); // non-recurring → excluded

        $this->assertSame(0, (new RecurringDealRenewalService())->generateDue(1, '2026-06-10'));
    }
}
