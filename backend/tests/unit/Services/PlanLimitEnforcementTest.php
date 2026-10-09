<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\PlanLimitChecker;
use App\Services\Tenancy\FeatureGate;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Traits\FlowTestSchema;

/**
 * Tests for PlanLimitChecker and FeatureGate plan limits.
 *
 * Uses in-memory SQLite via FlowTestSchema (provides contacts, flows,
 * phone_numbers, tenants, users tables — enough for all four dimensions).
 *
 * The six cases:
 *   1. contacts at limit → allowed=false
 *   2. contacts under limit → allowed=true
 *   3. active_flows at limit → allowed=false
 *   4. self_hosted always allows (pro limits, large ceiling)
 *   5. limitExceededResponse() shape has all required keys
 *   6. halted-past-grace → tenant downgraded to free → limits resolve to free
 */
class PlanLimitEnforcementTest extends CIUnitTestCase
{
    use FlowTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlowSchema(); // also wipes rows and seeds tenant id=1
        FeatureGate::forceMode('saas');
    }

    protected function tearDown(): void
    {
        FeatureGate::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Set tenant's plan directly in the SQLite test DB. */
    private function setTenantPlan(string $plan): void
    {
        db_connect()->table('tenants')->where('id', 1)->update(['plan' => $plan]);
    }

    private function seedContacts(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            db_connect()->table('contacts')->insert([
                'tenant_id'  => 1,
                'wa_number'  => '+9199999' . str_pad((string)$i, 5, '0', STR_PAD_LEFT),
                'name'       => "Contact {$i}",
                'status'     => 'new',
                'source'     => 'manual',
                'opt_in'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function seedActiveFlows(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            db_connect()->table('flows')->insert([
                'tenant_id'      => 1,
                'name'           => "Flow {$i}",
                'status'         => 'active',
                'trigger_type'   => 'lead_created',
                'graph'          => '{}',
                'reentry_policy' => 'once',
                'version'        => 1,
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Test 1: contacts AT limit → blocked
    // ------------------------------------------------------------------

    public function testContactsAtLimitIsBlocked(): void
    {
        $this->setTenantPlan('free'); // free = 200 contacts
        $limit = FeatureGate::getPlanLimits('free')['contacts'];
        $this->seedContacts($limit); // exactly at limit

        $result = (new PlanLimitChecker())->check(1, 'contacts');

        $this->assertFalse($result['allowed'], 'Should be blocked when count equals limit.');
        $this->assertSame($limit, $result['current']);
        $this->assertSame($limit, $result['limit']);
    }

    // ------------------------------------------------------------------
    // Test 2: contacts under limit → allowed
    // ------------------------------------------------------------------

    public function testContactsUnderLimitIsAllowed(): void
    {
        $this->setTenantPlan('free');
        $limit = FeatureGate::getPlanLimits('free')['contacts'];
        $this->seedContacts($limit - 1); // one under

        $result = (new PlanLimitChecker())->check(1, 'contacts');

        $this->assertTrue($result['allowed']);
        $this->assertSame($limit - 1, $result['current']);
        $this->assertSame($limit, $result['limit']);
    }

    // ------------------------------------------------------------------
    // Test 3: active_flows at limit → blocked
    // ------------------------------------------------------------------

    public function testActiveFlowsAtLimitIsBlocked(): void
    {
        $this->setTenantPlan('free'); // free = 1 active flow
        $limit = FeatureGate::getPlanLimits('free')['active_flows'];
        $this->seedActiveFlows($limit);

        $result = (new PlanLimitChecker())->check(1, 'active_flows');

        $this->assertFalse($result['allowed']);
        $this->assertSame($limit, $result['current']);
    }

    // ------------------------------------------------------------------
    // Test 4: self_hosted always gets pro limits (large ceiling → always allowed)
    // ------------------------------------------------------------------

    public function testSelfHostedAlwaysAllowsBeyondFreeLimits(): void
    {
        FeatureGate::forceMode('self_hosted');
        $this->setTenantPlan('free');

        // Seed contacts beyond the free limit (200)
        $freeLimit = 200;
        $this->seedContacts($freeLimit + 50);

        $result = (new PlanLimitChecker())->check(1, 'contacts');

        // In self_hosted mode FeatureGate::getPlanLimits() always returns pro limits (25000)
        $this->assertTrue($result['allowed'],
            'self_hosted must always use pro limits regardless of tenants.plan.'
        );
        $this->assertSame(25_000, $result['limit']);
    }

    // ------------------------------------------------------------------
    // Test 5: limitExceededResponse() has the required 5-key shape
    // ------------------------------------------------------------------

    public function testLimitExceededResponseShapeHasAllRequiredKeys(): void
    {
        $checker  = new PlanLimitChecker();
        $response = $checker->limitExceededResponse('contacts', 201, 200);

        $this->assertSame('plan_limit_exceeded', $response['error']);
        $this->assertSame('contacts',            $response['limit_type']);
        $this->assertSame(201,                   $response['current']);
        $this->assertSame(200,                   $response['limit']);
        $this->assertArrayHasKey('upgrade_url',  $response);
        $this->assertNotEmpty($response['upgrade_url']);
    }

    // ------------------------------------------------------------------
    // Test 6: halted-past-grace → subscription:check sets plan='free'
    //         → PlanLimitChecker resolves to free limits
    // ------------------------------------------------------------------

    public function testHaltedPastGraceResolvesToFreeLimits(): void
    {
        // Start tenant on pro plan
        $this->setTenantPlan('pro');
        $proLimit = FeatureGate::getPlanLimits('pro')['contacts']; // 25000

        // Simulate what subscription:check does: downgrade to free
        db_connect()->table('tenants')->where('id', 1)->update(['plan' => 'free']);
        $freeLimit = FeatureGate::getPlanLimits('free')['contacts']; // 200

        // Seed contacts just above the free limit but below pro limit
        $this->seedContacts($freeLimit + 10); // 210 contacts

        $result = (new PlanLimitChecker())->check(1, 'contacts');

        // After downgrade, pro limit is gone; the 210 contacts exceed free (200)
        $this->assertSame($freeLimit, $result['limit'],
            'After subscription:check downgrade, limits must be free-tier.'
        );
        $this->assertFalse($result['allowed'],
            'Contacts above free limit must be blocked after halt-grace downgrade.'
        );
        $this->assertGreaterThan($freeLimit, $result['current'],
            'Current count should exceed the now-downgraded free limit.'
        );
        $this->assertLessThan($proLimit, $result['current'],
            'Sanity: current count should be below the old pro limit.'
        );
    }
}
