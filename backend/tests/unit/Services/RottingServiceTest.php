<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use App\Models\DealModel;
use App\Models\PipelineStageModel;
use App\Services\Crm\RottingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase H3: rotting-deal detection (stage threshold + boundary) and
 * idempotent stale-lead re-queue, both tenant-scoped.
 */
class RottingServiceTest extends CIUnitTestCase
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

    private const NOW = 1_700_000_000;

    private function stage(int $tenant, ?int $rottingDays): int
    {
        return (int) (new PipelineStageModel())->setTenant($tenant)
            ->insert(['pipeline_id' => 1, 'name' => 'S', 'position' => 0, 'rotting_days' => $rottingDays], true);
    }

    private function deal(int $tenant, int $stageId, string $lastActivity): int
    {
        return (int) (new DealModel())->setTenant($tenant)
            ->insert(['title' => 'D', 'pipeline_id' => 1, 'stage_id' => $stageId, 'status' => 'open', 'last_activity_at' => $lastActivity], true);
    }

    public function testRottingDetection(): void
    {
        $stage = $this->stage(1, 7); // rots after 7 days
        $fresh = $this->deal(1, $stage, date('Y-m-d H:i:s', self::NOW - 2 * 86400));   // 2 days idle → fine
        $rotten = $this->deal(1, $stage, date('Y-m-d H:i:s', self::NOW - 10 * 86400)); // 10 days → rotting

        // A stage with no threshold never rots.
        $noThresh = $this->stage(1, null);
        $this->deal(1, $noThresh, date('Y-m-d H:i:s', self::NOW - 99 * 86400));

        $ids = (new RottingService())->rottingDealIds(1, self::NOW);
        $this->assertContains($rotten, $ids);
        $this->assertNotContains($fresh, $ids);
        $this->assertCount(1, $ids);
    }

    public function testStaleLeadRequeueIsIdempotent(): void
    {
        $svc = new RottingService();
        $stale  = (new ContactModel())->setTenant(1)->insert(['wa_number' => '+9601', 'status' => 'new', 'source' => 'manual', 'lifecycle_stage' => 'lead', 'owner_id' => 5, 'last_inbound_at' => date('Y-m-d H:i:s', self::NOW - 30 * 86400)], true);
        $recent = (new ContactModel())->setTenant(1)->insert(['wa_number' => '+9602', 'status' => 'new', 'source' => 'manual', 'lifecycle_stage' => 'lead', 'owner_id' => 5, 'last_inbound_at' => date('Y-m-d H:i:s', self::NOW - 1 * 86400)], true);

        $this->assertSame(1, $svc->rerouteStaleContacts(1, self::NOW), 'only the stale one is re-queued');
        $this->assertNull((new ContactModel())->setTenant(1)->find($stale)['owner_id'], 'owner cleared');
        $this->assertSame(5, (int) (new ContactModel())->setTenant(1)->find($recent)['owner_id'], 'recent untouched');

        // Second pass: nothing left to do (idempotent).
        $this->assertSame(0, $svc->rerouteStaleContacts(1, self::NOW));
    }

    public function testRottingIsTenantScoped(): void
    {
        $s1 = $this->stage(1, 5);
        $this->deal(1, $s1, date('Y-m-d H:i:s', self::NOW - 10 * 86400));
        $s2 = $this->stage(2, 5);
        $this->deal(2, $s2, date('Y-m-d H:i:s', self::NOW - 10 * 86400));

        $this->assertCount(1, (new RottingService())->rottingDealIds(1, self::NOW));
    }
}
