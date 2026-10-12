<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Tenancy\FeatureGate;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** The Billing page must show the limits the API actually enforces: they come from one table, served by GET /billing/status (group "mysql"). */
#[Group('mysql')]
final class PlanLimitsApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['subscriptions', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->table('tenants')->insert(['id' => 1, 'name' => 'Agency', 'slug' => 'a', 'plan' => 'starter', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $this->token = bin2hex(random_bytes(32));
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Owner', 'email' => 'o@example.com', 'password_hash' => 'x', 'role' => 'owner', 'api_token' => hash('sha256', $this->token), 'created_at' => '2026-01-01 00:00:00']);
    }

    protected function tearDown(): void { db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    public function testBillingStatusServesTheEnforcedLimitsOfEveryPlan(): void
    {
        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->get('api/v1/billing/status');
        $r->assertStatus(200);
        $limits = json_decode($r->getJSON(), true)['plan_limits'];
        $names = array_keys($limits); sort($names);
        $this->assertSame(['free', 'growth', 'pro', 'starter'], $names);
        foreach (['free', 'starter', 'growth', 'pro'] as $plan) {
            foreach (['contacts', 'agents', 'active_flows', 'waba_numbers'] as $dim) {
                $this->assertSame(FeatureGate::getPlanLimits($plan)[$dim], $limits[$plan][$dim], "$plan.$dim must equal what the API enforces");
            }
        }
        $this->assertSame(1000, $limits['starter']['contacts'], 'a guard against the old, more generous numbers coming back');
        $this->assertLessThan(999999, $limits['pro']['contacts'], 'no plan is unlimited');
    }
}
