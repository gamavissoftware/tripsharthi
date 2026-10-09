<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Services\Tenancy\FeatureGate;

/**
 * Checks whether a create/activate operation would exceed the tenant's plan limits.
 *
 * ── The four enforced dimensions ─────────────────────────────────────────
 *   contacts     — total non-deleted contact rows for the tenant
 *   agents       — total non-deleted user rows for the tenant
 *   active_flows — flows where status='active' and not deleted
 *   waba_numbers — phone_numbers rows for the tenant (no soft-delete)
 *
 * ── Enforcement rule: block new, never delete existing ───────────────────
 * When limit is reached, new creations return allowed=false (422).
 * Existing rows above the limit (e.g. after a plan downgrade) are NOT deleted.
 * Callers must not interpret "at limit" as "delete something".
 *
 * ── Error response shape ─────────────────────────────────────────────────
 * Controllers call limitExceededResponse() to build a consistent 422 body.
 * The shape is typed so tests can assert it by key, not by magic string.
 */
class PlanLimitChecker
{
    /**
     * Check whether the tenant can create one more of $limitType.
     *
     * @param  int    $tenantId
     * @param  string $limitType  'contacts' | 'agents' | 'active_flows' | 'waba_numbers'
     * @return array{allowed: bool, current: int, limit: int}
     */
    public function check(int $tenantId, string $limitType): array
    {
        $db     = db_connect();
        $tenant = $db->table('tenants')->where('id', $tenantId)->get()->getRowArray();

        if ($tenant === null) {
            return ['allowed' => false, 'current' => 0, 'limit' => 0];
        }

        $limits  = FeatureGate::getPlanLimits($tenant['plan'] ?? 'free');
        $limit   = $limits[$limitType] ?? 0;
        $current = $this->countCurrent($db, $tenantId, $limitType);

        return [
            'allowed' => $current < $limit,
            'current' => $current,
            'limit'   => $limit,
        ];
    }

    /**
     * Build the standard 422 response body for a limit-exceeded error.
     * Controllers: return $this->fail($checker->limitExceededResponse(...), 422)
     */
    public function limitExceededResponse(string $limitType, int $current, int $limit): array
    {
        return [
            'error'       => 'plan_limit_exceeded',
            'limit_type'  => $limitType,
            'current'     => $current,
            'limit'       => $limit,
            'upgrade_url' => '/billing',
        ];
    }

    /**
     * Current usage across all four enforced dimensions for a tenant.
     * Uses the same per-dimension counting as check(), so the numbers a UI
     * shows always match what enforcement will block on.
     *
     * @return array{contacts:int, agents:int, active_flows:int, waba_numbers:int}
     */
    public function usageCounts(int $tenantId): array
    {
        $db = db_connect();

        $dims = ['contacts', 'agents', 'active_flows', 'waba_numbers', 'deals', 'pipelines', 'custom_objects', 'dashboards'];
        $out  = [];
        foreach ($dims as $d) {
            $out[$d] = $this->countCurrent($db, $tenantId, $d);
        }
        return $out;
    }

    // ------------------------------------------------------------------

    private function countCurrent(mixed $db, int $tenantId, string $limitType): int
    {
        return match ($limitType) {
            'contacts' => (int) $db->table('contacts')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at IS NULL', null, false)
                ->countAllResults(),

            'agents' => (int) $db->table('users')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at IS NULL', null, false)
                ->countAllResults(),

            'active_flows' => (int) $db->table('flows')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->where('deleted_at IS NULL', null, false)
                ->countAllResults(),

            'waba_numbers' => (int) $db->table('phone_numbers')
                ->where('tenant_id', $tenantId)
                ->countAllResults(),

            // CRM dimensions (Phase K2).
            'deals', 'pipelines', 'custom_objects', 'dashboards' => (int) $db->table($limitType)
                ->where('tenant_id', $tenantId)
                ->where('deleted_at IS NULL', null, false)
                ->countAllResults(),

            default => 0,
        };
    }
}
