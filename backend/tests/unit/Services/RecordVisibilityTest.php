<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DealModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Record-level permissions (Phase M). Enforcement lives in BaseModel::scopeVisibility,
 * so DealModel is exercised here as the representative ownable model — every other
 * ownable model shares the identical code path.
 *
 * Agents: uid 2 (team A), uid 4 (team A teammate), uid 3 & 5 (other owners).
 */
class RecordVisibilityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentUser::reset();
        $this->createCrmSchema();

        $db = db_connect();
        // owners: A=uid2 (self), B=uid3 (other), C=uid3 shared→uid2,
        // D=uid4 (uid2's teammate), E=uid5 (other team)
        foreach ([['A', 2], ['B', 3], ['C', 3], ['D', 4], ['E', 5]] as [$title, $owner]) {
            $db->table('deals')->insert([
                'tenant_id' => 1, 'title' => $title, 'pipeline_id' => 1, 'stage_id' => 1,
                'owner_id' => $owner, 'status' => 'open', 'value_amount' => 100,
            ]);
        }
        // Team A = {uid2, uid4}.
        $db->table('teams')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'A']);
        $db->table('team_members')->insert(['tenant_id' => 1, 'team_id' => 1, 'user_id' => 2]);
        $db->table('team_members')->insert(['tenant_id' => 1, 'team_id' => 1, 'user_id' => 4]);
    }

    protected function tearDown(): void
    {
        CurrentUser::reset(); // must not leak an authenticated user into other test classes
        parent::tearDown();
    }

    private function mode(string $m): void
    {
        db_connect()->table('tenants')->where('id', 1)->update(['record_visibility' => $m]);
    }

    private function actAs(int $id, string $role): void
    {
        CurrentUser::set(['id' => $id, 'tenant_id' => 1, 'role' => $role]);
    }

    private function titles(): array
    {
        $rows = (new DealModel())->setTenant(1)->orderBy('title', 'ASC')->findAll();
        return array_column($rows, 'title');
    }

    // ── open mode (default) — regression guard ──────────────────────────

    public function testOpenModeAgentSeesAll(): void
    {
        $this->mode('open');
        $this->actAs(2, 'agent');
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->titles());
    }

    public function testUnauthenticatedSystemSeesAll(): void
    {
        // No CurrentUser set → system/CLI/webhook context → no scoping (inbound/flows safe).
        $this->mode('owner');
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->titles());
    }

    // ── owner mode ──────────────────────────────────────────────────────

    public function testOwnerModeAgentSeesOnlyOwnAndShared(): void
    {
        $db = db_connect();
        $db->table('record_shares')->insert([
            'tenant_id' => 1, 'entity_type' => 'deal', 'entity_id' => 3, // deal C
            'grantee_type' => 'user', 'grantee_id' => 2, 'access' => 'edit',
        ]);
        $this->mode('owner');
        $this->actAs(2, 'agent');
        // A (own) + C (shared), not B/D/E.
        $this->assertSame(['A', 'C'], $this->titles());
    }

    public function testOwnerModeFindOfInvisibleReturnsNull(): void
    {
        $this->mode('owner');
        $this->actAs(2, 'agent');
        $this->assertNull((new DealModel())->setTenant(1)->find(2));  // deal B (uid3)
        $this->assertNotNull((new DealModel())->setTenant(1)->find(1)); // deal A (own)
    }

    public function testOwnerModePrivilegedSeesAll(): void
    {
        $this->mode('owner');
        $this->actAs(99, 'owner');
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->titles());
    }

    // ── team mode ───────────────────────────────────────────────────────

    public function testTeamModeSeesTeammatesNotOthers(): void
    {
        $this->mode('team');
        $this->actAs(2, 'agent');
        // own A + teammate uid4's D; not B/C (uid3) or E (uid5).
        $this->assertSame(['A', 'D'], $this->titles());
    }

    public function testTeamShareGrantsAccess(): void
    {
        db_connect()->table('record_shares')->insert([
            'tenant_id' => 1, 'entity_type' => 'deal', 'entity_id' => 5, // deal E (uid5)
            'grantee_type' => 'team', 'grantee_id' => 1, 'access' => 'edit',
        ]);
        $this->mode('team');
        $this->actAs(2, 'agent');
        $this->assertSame(['A', 'D', 'E'], $this->titles());
    }

    // ── write protection (via find-null → controller 404) ───────────────

    public function testCanEditMirrorsVisibility(): void
    {
        $this->mode('owner');
        $this->actAs(2, 'agent');
        $this->assertTrue((new DealModel())->setTenant(1)->canEdit(1));   // own
        $this->assertFalse((new DealModel())->setTenant(1)->canEdit(2));  // uid3's
    }

    // ── read vs edit shares (Phase M follow-up) ─────────────────────────

    public function testReadShareIsVisibleButNotEditable(): void
    {
        db_connect()->table('record_shares')->insert([
            'tenant_id' => 1, 'entity_type' => 'deal', 'entity_id' => 3, // deal C (uid3)
            'grantee_type' => 'user', 'grantee_id' => 2, 'access' => 'read',
        ]);
        $this->mode('owner');
        $this->actAs(2, 'agent');

        // Visible via the read share…
        $this->assertSame(['A', 'C'], $this->titles());
        // …but not editable.
        $this->assertFalse((new DealModel())->setTenant(1)->canEdit(3));
    }

    public function testEditShareIsEditable(): void
    {
        db_connect()->table('record_shares')->insert([
            'tenant_id' => 1, 'entity_type' => 'deal', 'entity_id' => 3,
            'grantee_type' => 'user', 'grantee_id' => 2, 'access' => 'edit',
        ]);
        $this->mode('owner');
        $this->actAs(2, 'agent');
        $this->assertTrue((new DealModel())->setTenant(1)->canEdit(3));
    }

    public function testTeamModeTeammateRecordIsEditable(): void
    {
        // In team mode a teammate's record (uid4's D) is editable — owner is in the
        // agent's visible set; the read/edit split only applies to explicit shares.
        $this->mode('team');
        $this->actAs(2, 'agent');
        $this->assertTrue((new DealModel())->setTenant(1)->canEdit(4));  // deal D (uid4, teammate)
        $this->assertFalse((new DealModel())->setTenant(1)->canEdit(2)); // deal B (uid3, other)
    }
}
