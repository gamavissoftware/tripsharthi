<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

/**
 * Single source of truth for mode-dependent feature flags.
 *
 * Feature code MUST use this class instead of reading APP_MODE directly.
 * This keeps mode-switching logic in one place and makes tests easy.
 *
 * Plan limits are intentionally generous for free/dev; tighten in Sprint 6
 * when Razorpay billing is wired.
 */
class FeatureGate
{
    private static ?string $mode = null;

    private static array $planLimits = [
        // CRM dimensions (deals, pipelines, custom_objects, dashboards) added in Phase K2.
        'free'    => ['contacts' => 200,   'agents' => 1,  'active_flows' => 1,  'waba_numbers' => 1, 'ai_replies' => 0,     'deals' => 50,     'pipelines' => 1,  'custom_objects' => 0,  'dashboards' => 1],
        'starter' => ['contacts' => 1000,  'agents' => 3,  'active_flows' => 5,  'waba_numbers' => 1, 'ai_replies' => 200,   'deals' => 500,    'pipelines' => 2,  'custom_objects' => 2,  'dashboards' => 3],
        'growth'  => ['contacts' => 5000,  'agents' => 10, 'active_flows' => 20, 'waba_numbers' => 2, 'ai_replies' => 2000,  'deals' => 5000,   'pipelines' => 5,  'custom_objects' => 10, 'dashboards' => 10],
        'pro'     => ['contacts' => 25000, 'agents' => 50, 'active_flows' => 100,'waba_numbers' => 5, 'ai_replies' => 10000, 'deals' => 100000, 'pipelines' => 20, 'custom_objects' => 50, 'dashboards' => 50],
    ];

    private static function mode(): string
    {
        if (self::$mode === null) {
            self::$mode = env('APP_MODE', 'saas');
        }
        return self::$mode;
    }

    /** Reset cached mode — for testing only. */
    public static function reset(): void
    {
        self::$mode = null;
    }

    /** Force a mode — for testing only. */
    public static function forceMode(string $mode): void
    {
        self::$mode = $mode;
    }

    public static function isSaas(): bool
    {
        return self::mode() === 'saas';
    }

    public static function isSelfHosted(): bool
    {
        return self::mode() === 'self_hosted';
    }

    /** Registration is open only in SaaS mode. */
    public static function isRegistrationOpen(): bool
    {
        return self::isSaas();
    }

    /** Razorpay billing UI is shown only in SaaS mode. */
    public static function isBillingEnabled(): bool
    {
        return self::isSaas();
    }

    /** License validation is required only in self_hosted mode. */
    public static function isLicenseRequired(): bool
    {
        return self::isSelfHosted();
    }

    /**
     * Returns true when the system must block write operations.
     *
     * Only ever true in self_hosted mode after the license grace period expires.
     * In SaaS mode always returns false.
     *
     * Uses LicenseService::checkStatus() which is a pure DB read — no network.
     * Controllers/services that need to check read-only status outside the filter
     * (e.g. to show a UI banner) can call this directly.
     */
    public static function isReadOnly(): bool
    {
        if (! self::isSelfHosted()) {
            return false;
        }
        return (new \App\Services\Licensing\LicenseService())->checkStatus()->isReadOnly;
    }

    /**
     * Return plan limits for a tenant.
     *
     * In self_hosted mode the tenant always gets pro limits (the customer
     * paid a one-time fee for the source code).
     *
     * @return array{contacts:int, agents:int, active_flows:int, waba_numbers:int}
     */
    public static function getPlanLimits(string $plan): array
    {
        if (self::isSelfHosted()) {
            return self::$planLimits['pro'];
        }
        return self::$planLimits[$plan] ?? self::$planLimits['free'];
    }
}
