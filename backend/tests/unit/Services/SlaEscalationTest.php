<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\TicketModel;
use App\Services\Crm\BusinessHoursService;
use App\Services\Crm\SlaEscalationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase H5: business-hours SLA math + breach escalation (reassign, once-only).
 */
class SlaEscalationTest extends CIUnitTestCase
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

    private function user(int $tenant, int $id): void
    {
        db_connect()->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'name' => "U{$id}", 'role' => 'agent']);
    }

    private function ticket(int $tenant, array $f): int
    {
        return (int) (new TicketModel())->setTenant($tenant)
            ->insert(array_merge(['subject' => 'T', 'status' => 'open'], $f), true);
    }

    // ── Business-hours SLA math ─────────────────────────────────────────

    public function testBusinessHoursSlaSkipsAfterHoursAndWeekend(): void
    {
        $svc = new BusinessHoursService();
        $cfg = $svc->config(1); // defaults: Mon–Fri 09–18

        // Friday 2021-01-01 16:00 + 4 business hours → Monday 11:00.
        $fri16 = strtotime('2021-01-01 16:00:00'); // 2021-01-01 is a Friday
        $due   = $svc->addBusinessHours($cfg, $fri16, 4);
        $this->assertSame('2021-01-04 11:00:00', date('Y-m-d H:i:s', $due));
    }

    public function testBusinessHoursSlaIsMinutePrecise(): void
    {
        $svc = new BusinessHoursService();
        $cfg = $svc->config(1); // Mon–Fri 09–18

        // Friday 17:30 + 1 business hour = 30 min Fri (→18:00) + 30 min Mon morning = Mon 09:30.
        $fri1730 = strtotime('2021-01-01 17:30:00');
        $this->assertSame('2021-01-04 09:30:00', date('Y-m-d H:i:s', $svc->addBusinessHours($cfg, $fri1730, 1)));
    }

    public function testConfigOverride(): void
    {
        db_connect()->table('business_hours')->insert(['tenant_id' => 1, 'start_hour' => 8, 'end_hour' => 12, 'workdays' => '1,2,3,4,5']);
        $cfg = (new BusinessHoursService())->config(1);
        $this->assertSame(8, $cfg['start_hour']);
        $this->assertSame([1, 2, 3, 4, 5], $cfg['workdays']);
    }

    public function testMisconfiguredHoursFallBackToDefaults(): void
    {
        // end_hour <= start_hour would leave no working window; config() must fall
        // back to defaults so addBusinessHours can't spin to its iteration guard.
        db_connect()->table('business_hours')->insert(['tenant_id' => 1, 'start_hour' => 18, 'end_hour' => 9, 'workdays' => '1,2,3,4,5']);
        $svc = new BusinessHoursService();
        $cfg = $svc->config(1);
        $this->assertSame(9, $cfg['start_hour'], 'fell back to default start');
        $this->assertSame(18, $cfg['end_hour'], 'fell back to default end');

        // The SLA math terminates with a near-future (not far-future) due time.
        $from = strtotime('2021-01-04 10:00:00'); // Monday
        $this->assertSame('2021-01-04 12:00:00', date('Y-m-d H:i:s', $svc->addBusinessHours($cfg, $from, 2)));
    }

    // ── Escalation ──────────────────────────────────────────────────────

    public function testEscalateBreachedReassignsAndIsOnceOnly(): void
    {
        $this->user(1, 1); $this->user(1, 2);
        $now = strtotime('2021-06-01 12:00:00');

        $breached = $this->ticket(1, ['owner_id' => 1, 'status' => 'open', 'sla_due_at' => date('Y-m-d H:i:s', $now - 3600)]);
        $ontime   = $this->ticket(1, ['owner_id' => 1, 'status' => 'open', 'sla_due_at' => date('Y-m-d H:i:s', $now + 3600)]);

        $svc = new SlaEscalationService();
        $this->assertSame(1, $svc->run(1, $now));

        $t = (new TicketModel())->setTenant(1)->find($breached);
        $this->assertNotNull($t['escalated_at'], 'breach stamped');
        $this->assertSame(2, (int) $t['owner_id'], 'reassigned away from the original owner');
        $this->assertNull((new TicketModel())->setTenant(1)->find($ontime)['escalated_at'], 'on-time untouched');

        // Idempotent: already-escalated is not picked up again.
        $this->assertSame(0, $svc->run(1, $now));
    }

    public function testResolvedTicketNeverEscalates(): void
    {
        $this->user(1, 1); $this->user(1, 2);
        $now = strtotime('2021-06-01 12:00:00');
        $this->ticket(1, ['owner_id' => 1, 'status' => 'resolved', 'sla_due_at' => date('Y-m-d H:i:s', $now - 7200)]);

        $this->assertSame(0, (new SlaEscalationService())->run(1, $now));
    }
}
