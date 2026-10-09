<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AI\AiUsageService;
use App\Services\Tenancy\FeatureGate;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\BlankSlateSchema;

/**
 * Tests monthly AI metering: plan limits, increment, remaining, period rollover.
 */
class AiUsageServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use BlankSlateSchema;

    protected $migrate = false;
    protected $refresh = true;

    private int $jun;
    private int $jul;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
        FeatureGate::forceMode('saas'); // plan-based limits (not self-hosted=pro)
        $this->jun = mktime(12, 0, 0, 6, 15, 2026);
        $this->jul = mktime(12, 0, 0, 7, 1, 2026);
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        FeatureGate::reset();
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, plan TEXT DEFAULT 'free')");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}ai_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, period TEXT,
            used INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT, UNIQUE(tenant_id, period)
        )");
        $db->query("DELETE FROM {$p}tenants");
        $db->query("DELETE FROM {$p}ai_usage");
        $db->query("INSERT INTO {$p}tenants (id,name,slug,plan) VALUES (1,'Starter','s','starter'),(2,'Free','f','free')");
    }

    public function testLimitFromPlan(): void
    {
        $svc = new AiUsageService();
        $this->assertSame(200, $svc->limitFor(1)); // starter
        $this->assertSame(0,   $svc->limitFor(2)); // free → AI disabled
    }

    public function testFreePlanCannotUse(): void
    {
        $this->assertFalse((new AiUsageService())->canUse(2, $this->jun));
    }

    public function testRecordIncrementsAndDecrementsRemaining(): void
    {
        $svc = new AiUsageService();
        $this->assertSame(200, $svc->remaining(1, $this->jun));

        $svc->record(1, $this->jun);
        $svc->record(1, $this->jun);

        $this->assertSame(2,   $svc->used(1, $this->jun));
        $this->assertSame(198, $svc->remaining(1, $this->jun));
        $this->assertTrue($svc->canUse(1, $this->jun));
    }

    public function testUsageIsPerPeriod(): void
    {
        $svc = new AiUsageService();
        $svc->record(1, $this->jun);
        $svc->record(1, $this->jun);

        $this->assertSame(2, $svc->used(1, $this->jun));
        $this->assertSame(0, $svc->used(1, $this->jul), 'July starts fresh');
    }

    public function testCannotUseWhenAtLimit(): void
    {
        $db = db_connect();
        // Pre-fill starter (limit 200) to the cap for June.
        $db->table('ai_usage')->insert([
            'tenant_id' => 1, 'period' => gmdate('Y-m', $this->jun), 'used' => 200,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);

        $svc = new AiUsageService();
        $this->assertSame(0, $svc->remaining(1, $this->jun));
        $this->assertFalse($svc->canUse(1, $this->jun));
    }

    public function testSnapshotShape(): void
    {
        $svc = new AiUsageService();
        $svc->record(1, $this->jun);
        $snap = $svc->snapshot(1, $this->jun);

        $this->assertSame(gmdate('Y-m', $this->jun), $snap['period']);
        $this->assertSame(1, $snap['used']);
        $this->assertSame(200, $snap['limit']);
        $this->assertSame(199, $snap['remaining']);
    }
}
