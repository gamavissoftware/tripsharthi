<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\TaskModel;
use App\Services\Crm\TaskDueService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase H4: task_due reminder firing — matured selection, no-double-fire,
 * and that only contact-linked tasks fire (while all matured are marked sent).
 */
class TaskDueServiceTest extends CIUnitTestCase
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

    private function task(int $tenant, array $f): int
    {
        return (int) (new TaskModel())->setTenant($tenant)
            ->insert(array_merge(['title' => 'T', 'status' => 'open'], $f), true);
    }

    public function testMaturedContactTaskFiresOnceOnly(): void
    {
        $matured = $this->task(1, ['related_type' => 'contact', 'related_id' => 9, 'reminder_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        $future  = $this->task(1, ['related_type' => 'contact', 'related_id' => 9, 'reminder_at' => date('Y-m-d H:i:s', self::NOW + 3600)]);

        $svc = new TaskDueService();
        $r   = $svc->run(1, self::NOW);
        $this->assertSame(1, $r['matured']);
        $this->assertSame(1, $r['fired']);
        $this->assertSame(1, (int) (new TaskModel())->setTenant(1)->find($matured)['reminder_sent']);
        $this->assertSame(0, (int) (new TaskModel())->setTenant(1)->find($future)['reminder_sent'], 'future task untouched');

        // No double-fire on a second pass.
        $this->assertSame(['matured' => 0, 'fired' => 0], $svc->run(1, self::NOW));
    }

    public function testDoneTaskAndDueDateFallback(): void
    {
        // A 'done' task never fires even if matured.
        $this->task(1, ['related_type' => 'contact', 'related_id' => 9, 'status' => 'done', 'reminder_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        // No reminder_at → falls back to due_at.
        $byDue = $this->task(1, ['related_type' => 'contact', 'related_id' => 9, 'due_at' => date('Y-m-d H:i:s', self::NOW - 60)]);

        $r = (new TaskDueService())->run(1, self::NOW);
        $this->assertSame(1, $r['matured'], 'only the due_at task matured');
        $this->assertSame(1, (int) (new TaskModel())->setTenant(1)->find($byDue)['reminder_sent']);
    }

    public function testMaturedTaskWithoutContactIsMarkedButNotFired(): void
    {
        $deal = $this->task(1, ['related_type' => 'deal', 'related_id' => 3, 'reminder_at' => date('Y-m-d H:i:s', self::NOW - 60)]);
        $r = (new TaskDueService())->run(1, self::NOW);
        $this->assertSame(1, $r['matured']);
        $this->assertSame(0, $r['fired'], 'no contact → nothing to message');
        $this->assertSame(1, (int) (new TaskModel())->setTenant(1)->find($deal)['reminder_sent'], 'still marked so it never re-checks');
    }
}
