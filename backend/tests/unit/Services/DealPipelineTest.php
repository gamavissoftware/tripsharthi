<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DealLineItemModel;
use App\Models\DealModel;
use App\Models\PipelineModel;
use App\Models\PipelineStageModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase B: default-pipeline provisioning, line-item CPQ math, board/open
 * filtering, and tenant isolation for deals.
 */
class DealPipelineTest extends CIUnitTestCase
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

    // ── Default pipeline provisioning ──────────────────────────────────

    public function testEnsureDefaultProvisionsPipelineWithStages(): void
    {
        $pipeline = (new PipelineModel())->ensureDefault(1);

        $this->assertSame('Sales Pipeline', $pipeline['name']);
        $this->assertSame(1, (int) $pipeline['is_default']);

        $stages = (new PipelineStageModel())->forPipeline(1, (int) $pipeline['id']);
        $names  = array_column($stages, 'name');
        $this->assertSame(['Lead', 'Qualified', 'Proposal', 'Negotiation', 'Won', 'Lost'], $names);

        $won  = array_values(array_filter($stages, static fn ($s) => (int) $s['is_won'] === 1));
        $lost = array_values(array_filter($stages, static fn ($s) => (int) $s['is_lost'] === 1));
        $this->assertSame('Won', $won[0]['name']);
        $this->assertSame(100, (int) $won[0]['probability']);
        $this->assertSame('Lost', $lost[0]['name']);
    }

    public function testEnsureDefaultIsIdempotent(): void
    {
        $pm = new PipelineModel();
        $a  = $pm->ensureDefault(1);
        $b  = $pm->ensureDefault(1);

        $this->assertSame((int) $a['id'], (int) $b['id'], 'Second call returns the same pipeline');
        $this->assertCount(1, (new PipelineModel())->setTenant(1)->where('entity_type', 'deal')->findAll());
        $this->assertCount(6, (new PipelineStageModel())->setTenant(1)->findAll(), 'No duplicate stages');
    }

    public function testPipelinesAreTenantScoped(): void
    {
        (new PipelineModel())->ensureDefault(1);
        (new PipelineModel())->ensureDefault(2);

        $this->assertCount(1, (new PipelineModel())->setTenant(1)->findAll());
        $this->assertCount(6, (new PipelineStageModel())->setTenant(2)->findAll());
    }

    // ── CPQ line-item math ─────────────────────────────────────────────

    public function testLineTotalWithTax(): void
    {
        // 12 × ₹10,000 = ₹120,000; +18% tax = ₹141,600 → 14,160,000 paise
        $this->assertSame(14160000, DealLineItemModel::lineTotal(12, 1000000, 0, 18));
    }

    public function testLineTotalWithDiscountAndTax(): void
    {
        // 1 × ₹1,000 = ₹1,000; -10% = ₹900; +18% = ₹1,062 → 106200 paise
        $this->assertSame(106200, DealLineItemModel::lineTotal(1, 100000, 10, 18));
    }

    // ── Board / open filtering + tenant isolation ──────────────────────

    public function testForPipelineReturnsOpenDealsOnly(): void
    {
        $pipeline = (new PipelineModel())->ensureDefault(1);
        $stages   = (new PipelineStageModel())->forPipeline(1, (int) $pipeline['id']);
        $stageId  = (int) $stages[0]['id'];

        $dm = new DealModel();
        $dm->setTenant(1)->insert(['title' => 'Open A',  'pipeline_id' => $pipeline['id'], 'stage_id' => $stageId, 'status' => 'open']);
        $dm->setTenant(1)->insert(['title' => 'Won B',   'pipeline_id' => $pipeline['id'], 'stage_id' => $stageId, 'status' => 'won']);

        $open = (new DealModel())->forPipeline(1, (int) $pipeline['id']);
        $this->assertCount(1, $open);
        $this->assertSame('Open A', $open[0]['title']);

        $all = (new DealModel())->forPipeline(1, (int) $pipeline['id'], true);
        $this->assertCount(2, $all);
    }

    public function testDealsAreTenantScoped(): void
    {
        $p1 = (new PipelineModel())->ensureDefault(1);
        $p2 = (new PipelineModel())->ensureDefault(2);
        $s1 = (int) (new PipelineStageModel())->forPipeline(1, (int) $p1['id'])[0]['id'];
        $s2 = (int) (new PipelineStageModel())->forPipeline(2, (int) $p2['id'])[0]['id'];

        (new DealModel())->setTenant(1)->insert(['title' => 'T1 deal', 'pipeline_id' => $p1['id'], 'stage_id' => $s1]);
        (new DealModel())->setTenant(2)->insert(['title' => 'T2 deal', 'pipeline_id' => $p2['id'], 'stage_id' => $s2]);

        $t1 = (new DealModel())->setTenant(1)->findAll();
        $this->assertCount(1, $t1);
        $this->assertSame('T1 deal', $t1[0]['title']);
    }
}
