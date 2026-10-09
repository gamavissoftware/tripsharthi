<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Tenancy\FeatureGate;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for FeatureGate.
 *
 * No database required — FeatureGate only reads env state.
 */
class FeatureGateTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FeatureGate::reset();
    }

    protected function tearDown(): void
    {
        FeatureGate::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // SaaS mode
    // ------------------------------------------------------------------

    public function testSaasModeDetected(): void
    {
        FeatureGate::forceMode('saas');

        $this->assertTrue(FeatureGate::isSaas());
        $this->assertFalse(FeatureGate::isSelfHosted());
    }

    public function testSaasRegistrationIsOpen(): void
    {
        FeatureGate::forceMode('saas');

        $this->assertTrue(FeatureGate::isRegistrationOpen());
    }

    public function testSaasBillingEnabled(): void
    {
        FeatureGate::forceMode('saas');

        $this->assertTrue(FeatureGate::isBillingEnabled());
    }

    public function testSaasLicenseNotRequired(): void
    {
        FeatureGate::forceMode('saas');

        $this->assertFalse(FeatureGate::isLicenseRequired());
    }

    public function testSaasPlanLimitsRespected(): void
    {
        FeatureGate::forceMode('saas');

        $free    = FeatureGate::getPlanLimits('free');
        $starter = FeatureGate::getPlanLimits('starter');
        $pro     = FeatureGate::getPlanLimits('pro');

        $this->assertSame(200, $free['contacts']);
        $this->assertSame(1000, $starter['contacts']);
        $this->assertSame(25000, $pro['contacts']);

        // limits are strictly increasing
        $this->assertGreaterThan($free['contacts'], $starter['contacts']);
        $this->assertGreaterThan($starter['contacts'], $pro['contacts']);
    }

    public function testSaasUnknownPlanFallsBackToFree(): void
    {
        FeatureGate::forceMode('saas');

        $limits = FeatureGate::getPlanLimits('nonexistent');

        $this->assertSame(200, $limits['contacts']);
    }

    // ------------------------------------------------------------------
    // Self-hosted mode
    // ------------------------------------------------------------------

    public function testSelfHostedModeDetected(): void
    {
        FeatureGate::forceMode('self_hosted');

        $this->assertTrue(FeatureGate::isSelfHosted());
        $this->assertFalse(FeatureGate::isSaas());
    }

    public function testSelfHostedRegistrationClosed(): void
    {
        FeatureGate::forceMode('self_hosted');

        $this->assertFalse(FeatureGate::isRegistrationOpen());
    }

    public function testSelfHostedBillingDisabled(): void
    {
        FeatureGate::forceMode('self_hosted');

        $this->assertFalse(FeatureGate::isBillingEnabled());
    }

    public function testSelfHostedLicenseRequired(): void
    {
        FeatureGate::forceMode('self_hosted');

        $this->assertTrue(FeatureGate::isLicenseRequired());
    }

    public function testSelfHostedAlwaysGetProLimits(): void
    {
        FeatureGate::forceMode('self_hosted');

        // Self-hosted customers paid once — always get pro limits regardless of plan string.
        $freeLimits = FeatureGate::getPlanLimits('free');
        $proLimits  = FeatureGate::getPlanLimits('pro');

        $this->assertSame($proLimits, $freeLimits);
        $this->assertSame(25000, $freeLimits['contacts']);
    }

    // ------------------------------------------------------------------
    // Switching modes
    // ------------------------------------------------------------------

    public function testModeSwitchIsIsolated(): void
    {
        FeatureGate::forceMode('saas');
        $this->assertTrue(FeatureGate::isRegistrationOpen());

        FeatureGate::forceMode('self_hosted');
        $this->assertFalse(FeatureGate::isRegistrationOpen());

        FeatureGate::forceMode('saas');
        $this->assertTrue(FeatureGate::isRegistrationOpen());
    }

    public function testPlanLimitsContainRequiredKeys(): void
    {
        FeatureGate::forceMode('saas');

        $limits = FeatureGate::getPlanLimits('pro');

        $this->assertArrayHasKey('contacts', $limits);
        $this->assertArrayHasKey('agents', $limits);
        $this->assertArrayHasKey('active_flows', $limits);
        $this->assertArrayHasKey('waba_numbers', $limits);
    }
}
