<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MeetingModel;
use App\Services\Flow\FlowTriggerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Phase L: native calendar / meetings.
 *  - MeetingModel agenda windowing + per-contact lookup + tenant scoping.
 *  - The meeting_scheduled flow trigger enqueues a matching active flow.
 */
class MeetingTest extends CIUnitTestCase
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

    private function meeting(int $tenant, array $f): int
    {
        return (int) (new MeetingModel())->setTenant($tenant)
            ->insert(array_merge(['title' => 'M', 'start_at' => '2026-06-20 10:00:00'], $f), true);
    }

    // ── Agenda windowing ────────────────────────────────────────────────

    public function testAgendaReturnsOnlyMeetingsInWindowChronological(): void
    {
        $this->meeting(1, ['title' => 'Late',   'start_at' => '2026-06-25 09:00:00']);
        $this->meeting(1, ['title' => 'Early',  'start_at' => '2026-06-21 09:00:00']);
        $this->meeting(1, ['title' => 'Before', 'start_at' => '2026-06-10 09:00:00']); // outside
        $this->meeting(1, ['title' => 'After',  'start_at' => '2026-07-10 09:00:00']); // outside

        $agenda = (new MeetingModel())->agenda(1, '2026-06-20 00:00:00', '2026-06-30 23:59:59');

        $this->assertCount(2, $agenda, 'only the two in-window meetings');
        $this->assertSame('Early', $agenda[0]['title'], 'sorted by start_at ascending');
        $this->assertSame('Late',  $agenda[1]['title']);
    }

    public function testForContactScopesToContact(): void
    {
        $this->meeting(1, ['contact_id' => 7, 'title' => 'Mine']);
        $this->meeting(1, ['contact_id' => 8, 'title' => 'Theirs']);

        $rows = (new MeetingModel())->forContact(1, 7);

        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['title']);
    }

    public function testTenantScopingIsolatesMeetings(): void
    {
        $this->meeting(1, ['title' => 'T1']);
        $this->meeting(2, ['title' => 'T2']);

        $agenda = (new MeetingModel())->agenda(1, '2026-06-01 00:00:00', '2026-06-30 23:59:59');
        $this->assertCount(1, $agenda);
        $this->assertSame('T1', $agenda[0]['title']);
    }

    // ── Flow trigger ────────────────────────────────────────────────────

    private function seedActiveFlow(string $triggerType): int
    {
        db_connect()->table('flows')->insert([
            'tenant_id'      => 1,
            'name'           => "Test {$triggerType}",
            'status'         => 'active',
            'trigger_type'   => $triggerType,
            'trigger_config' => null,
            'reentry_policy' => 'always',
            'graph'          => json_encode(['nodes' => [], 'edges' => []]),
            'version'        => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        return (int) db_connect()->insertID();
    }

    public function testMeetingScheduledTriggerEnqueuesFlow(): void
    {
        $contactId = $this->seedContact('+919994100001');
        $this->seedActiveFlow('meeting_scheduled');

        $count = FlowTriggerService::fire('meeting_scheduled', 1, $contactId, ['meeting_id' => 42]);

        $this->assertSame(1, $count, 'active meeting_scheduled flow must be enqueued');
        $this->assertSame(1, (int) db_connect()->table('jobs')->where('type', 'flow_start')->countAllResults());
    }

    public function testMeetingScheduledDoesNotFireUnrelatedTriggers(): void
    {
        $contactId = $this->seedContact('+919994100002');
        $this->seedActiveFlow('lead_created');

        $count = FlowTriggerService::fire('meeting_scheduled', 1, $contactId);

        $this->assertSame(0, $count, 'a lead_created flow must not fire on meeting_scheduled');
    }
}
