<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\TeamMemberModel;
use App\Models\TeamModel;
use App\Services\Crm\TeamVisibilityService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase K3: team visibility — a user sees self + teammates, never others,
 * and is tenant-scoped.
 */
class TeamVisibilityTest extends CIUnitTestCase
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

    private function team(int $tenant, string $name): int
    {
        return (int) (new TeamModel())->setTenant($tenant)->insert(['name' => $name], true);
    }
    private function member(int $tenant, int $team, int $user): void
    {
        (new TeamMemberModel())->setTenant($tenant)->insert(['team_id' => $team, 'user_id' => $user]);
    }

    public function testSeesSelfAndTeammatesOnly(): void
    {
        $alpha = $this->team(1, 'Alpha');
        $this->member(1, $alpha, 1);
        $this->member(1, $alpha, 2);
        // User 3 is on a different team; user 9 on none.
        $beta = $this->team(1, 'Beta');
        $this->member(1, $beta, 3);

        $ids = (new TeamVisibilityService())->visibleUserIds(1, 1);
        sort($ids);
        $this->assertSame([1, 2], $ids, 'self + teammate, not the other team');
    }

    public function testUserWithNoTeamSeesOnlySelf(): void
    {
        $this->assertSame([9], (new TeamVisibilityService())->visibleUserIds(1, 9));
    }

    public function testTenantScoped(): void
    {
        // Tenant 2 has a team with users 1 and 2; tenant 1 user 1 must not inherit it.
        $t2team = $this->team(2, 'T2');
        $this->member(2, $t2team, 1);
        $this->member(2, $t2team, 2);

        $this->assertSame([1], (new TeamVisibilityService())->visibleUserIds(1, 1), 'no cross-tenant team leak');
    }
}
