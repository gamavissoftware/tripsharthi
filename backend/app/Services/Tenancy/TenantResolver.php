<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\TenantModel;

/**
 * Resolves the current tenant from an authenticated user.
 *
 * Strategy (v1): tenant_id is carried on the users row — one user belongs
 * to exactly one tenant. No subdomain or header-based resolution needed.
 *
 * In self_hosted mode the resolved tenant is always id=1.
 */
class TenantResolver
{
    public function __construct(private TenantModel $tenantModel) {}

    /**
     * Resolve tenant from the authenticated user's tenant_id.
     *
     * @param array|object $user  The authenticated user row.
     * @return array|object|null  The tenant row, or null if not found.
     */
    public function resolveFromUser(array|object $user): array|object|null
    {
        $tenantId = is_array($user) ? ($user['tenant_id'] ?? 0) : ($user->tenant_id ?? 0);

        if ($tenantId <= 0) {
            return null;
        }

        if (FeatureGate::isSelfHosted()) {
            // In self_hosted there is only ever one tenant.
            $tenantId = 1;
        }

        return $this->tenantModel->find($tenantId);
    }
}
