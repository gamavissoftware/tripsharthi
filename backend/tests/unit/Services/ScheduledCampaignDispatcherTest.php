<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\CampaignModel;
use App\Services\Leads\ScheduledCampaignDispatcher;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CampaignTestSchema;

/**
 * Tests the broadcast scheduler:
 *   - timezone → UTC conversion (pure)
 *   - dispatchDue() promotes only campaigns whose UTC time has arrived,
 *     flips them to 'processing', and enqueues a campaign_send job.
 */
class ScheduledCampaignDispatcherTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCampaignSchema();
        $this->createJobsTable();
    }

    private function createJobsTable(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}jobs (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id    INTEGER NOT NULL,
            type         TEXT NOT NULL,
            source_type  TEXT,
            source_id    INTEGER,
            payload      TEXT,
            run_at       TEXT NOT NULL,
            status       TEXT DEFAULT 'pending',
            attempts     INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 5,
            locked_at    TEXT,
            locked_by    TEXT,
            last_error   TEXT,
            created_at   TEXT, updated_at TEXT
        )");
        $db->query("DELETE FROM {$p}jobs");
    }

    // ── toUtc() ────────────────────────────────────────────────────────

    public function testToUtcConvertsIstToUtc(): void
    {
        // IST is UTC+5:30 → 10:00 IST == 04:30 UTC
        $this->assertSame(
            '2026-06-10 04:30:00',
            ScheduledCampaignDispatcher::toUtc('2026-06-10 10:00', 'Asia/Kolkata')
        );
    }

    public function testToUtcAcceptsLegacyAliasBrowsersReport(): void
    {
        // Chrome/Safari on many Indian machines report Intl zone "Asia/Calcutta".
        $this->assertSame(
            '2026-06-10 04:30:00',
            ScheduledCampaignDispatcher::toUtc('2026-06-10 10:00', 'Asia/Calcutta')
        );
    }

    public function testToUtcRejectsUnknownTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScheduledCampaignDispatcher::toUtc('2026-06-10 10:00', 'Mars/Olympus');
    }

    // ── dispatchDue() ──────────────────────────────────────────────────

    public function testDispatchesOnlyDueCampaigns(): void
    {
        $now = mktime(12, 0, 0, 6, 10, 2026); // 2026-06-10 12:00:00 UTC

        $tplId = $this->seedApprovedTemplate();
        $due   = $this->seedCampaign($tplId, [
            'status'       => 'scheduled',
            'scheduled_at' => gmdate('Y-m-d H:i:s', $now - 60),  // 1 min ago → due
        ]);
        $future = $this->seedCampaign($tplId, [
            'status'       => 'scheduled',
            'scheduled_at' => gmdate('Y-m-d H:i:s', $now + 3600), // 1h ahead → not due
        ]);

        $result = (new ScheduledCampaignDispatcher())->dispatchDue($now);

        $this->assertSame(1, $result['dispatched']);
        $this->assertSame([$due], $result['ids']);

        $model = new CampaignModel();
        $this->assertSame('processing', $model->setTenant(1)->find($due)['status']);
        $this->assertSame('scheduled',  $model->setTenant(1)->find($future)['status']);

        // Exactly one campaign_send job enqueued, for the due campaign.
        $jobs = db_connect()->table('jobs')->where('type', 'campaign_send')->get()->getResultArray();
        $this->assertCount(1, $jobs);
        $payload = json_decode($jobs[0]['payload'], true);
        $this->assertSame($due, (int) $payload['campaign_id']);
    }

    public function testIdempotentAfterDispatch(): void
    {
        $now   = mktime(12, 0, 0, 6, 10, 2026);
        $tplId = $this->seedApprovedTemplate();
        $this->seedCampaign($tplId, [
            'status'       => 'scheduled',
            'scheduled_at' => gmdate('Y-m-d H:i:s', $now - 60),
        ]);

        $svc = new ScheduledCampaignDispatcher();
        $first  = $svc->dispatchDue($now);
        $second = $svc->dispatchDue($now); // second pass in same minute

        $this->assertSame(1, $first['dispatched']);
        $this->assertSame(0, $second['dispatched'], 'A dispatched campaign must not be re-enqueued');
    }
}
