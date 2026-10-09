<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AssignmentRuleModel;
use App\Models\DealModel;
use App\Services\Crm\AssignmentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase H2: round-robin fairness, capacity cap, least-loaded, specific,
 * field match, and tenant-isolated pools.
 */
class AssignmentServiceTest extends CIUnitTestCase
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

    private function rule(int $tenant, array $f): int
    {
        return (int) (new AssignmentRuleModel())->setTenant($tenant)->insert(array_merge([
            'entity_type' => 'deal', 'name' => 'R', 'match_type' => 'any', 'strategy' => 'round_robin', 'capacity' => 50, 'is_active' => 1,
        ], $f), true);
    }

    private function openDeal(int $tenant, int $ownerId): void
    {
        (new DealModel())->setTenant($tenant)->insert(['title' => 'D', 'pipeline_id' => 1, 'stage_id' => 1, 'owner_id' => $ownerId, 'status' => 'open'], true);
    }

    public function testRoundRobinRotates(): void
    {
        $this->user(1, 1); $this->user(1, 2); $this->user(1, 3);
        $this->rule(1, ['strategy' => 'round_robin']);
        $svc = new AssignmentService();

        $seq = [];
        for ($i = 0; $i < 4; $i++) {
            $seq[] = $svc->assignee('deal', 1, ['status' => 'open']);
        }
        $this->assertSame([1, 2, 3, 1], $seq, 'rotates through the pool and wraps');
    }

    public function testRoundRobinSkipsAtCapacity(): void
    {
        $this->user(1, 1); $this->user(1, 2); $this->user(1, 3);
        $this->rule(1, ['capacity' => 1]);
        $this->openDeal(1, 1); // user 1 now at capacity (1)

        $this->assertSame(2, (new AssignmentService())->assignee('deal', 1, ['status' => 'open']), 'full rep skipped');
    }

    public function testLeastLoaded(): void
    {
        $this->user(1, 1); $this->user(1, 2); $this->user(1, 3);
        $this->rule(1, ['strategy' => 'least_loaded']);
        $this->openDeal(1, 1); $this->openDeal(1, 1); // user1: 2
        $this->openDeal(1, 3);                         // user3: 1, user2: 0

        $this->assertSame(2, (new AssignmentService())->assignee('deal', 1, ['status' => 'open']));
    }

    public function testSpecific(): void
    {
        $this->user(1, 1); $this->user(1, 2); $this->user(1, 3);
        $this->rule(1, ['strategy' => 'specific', 'assigned_user_id' => 3]);
        $this->assertSame(3, (new AssignmentService())->assignee('deal', 1, ['status' => 'open']));
    }

    public function testFieldMatchOnlyAppliesWhenMatched(): void
    {
        $this->user(1, 5);
        (new AssignmentRuleModel())->setTenant(1)->insert([
            'entity_type' => 'ticket', 'name' => 'urgent', 'match_type' => 'field', 'match_field' => 'priority',
            'match_value' => 'urgent', 'strategy' => 'specific', 'assigned_user_id' => 5, 'capacity' => 50, 'is_active' => 1,
        ]);
        $svc = new AssignmentService();
        $this->assertSame(5, $svc->assignee('ticket', 1, ['priority' => 'urgent']));
        $this->assertNull($svc->assignee('ticket', 1, ['priority' => 'low']), 'no rule matches → null');
    }

    public function testPoolIsTenantIsolated(): void
    {
        $this->user(1, 1);
        $this->user(2, 2);  // a different tenant's user
        $this->rule(1, ['strategy' => 'round_robin']);

        // Only tenant 1's user is eligible, no matter how many times we assign.
        $svc = new AssignmentService();
        $this->assertSame(1, $svc->assignee('deal', 1, ['status' => 'open']));
        $this->assertSame(1, $svc->assignee('deal', 1, ['status' => 'open']));
    }
}
