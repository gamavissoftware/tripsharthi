<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\Tenancy\FeatureGate;

/**
 * Monthly AI-reply metering against the tenant's plan (ai_replies limit).
 *
 * canUse() gates a generation; record() increments the counter AFTER a
 * successful generation (so failed/blocked attempts aren't charged). The
 * check→record window is a soft race (a tenant could momentarily exceed by a
 * few under heavy concurrency) — acceptable for a usage meter, not a security
 * control.
 */
class AiUsageService
{
    /** Plan limit of AI replies per month for this tenant (0 = AI disabled). */
    public function limitFor(int $tenantId): int
    {
        $tenant = db_connect()->table('tenants')->where('id', $tenantId)->get()->getRowArray();
        $limits = FeatureGate::getPlanLimits($tenant['plan'] ?? 'free');
        return (int) ($limits['ai_replies'] ?? 0);
    }

    /** AI replies used by the tenant in the given period (default: now). */
    public function used(int $tenantId, ?int $nowTs = null): int
    {
        $row = db_connect()->table('ai_usage')
            ->where('tenant_id', $tenantId)
            ->where('period', self::period($nowTs))
            ->get()->getRowArray();
        return $row ? (int) $row['used'] : 0;
    }

    public function remaining(int $tenantId, ?int $nowTs = null): int
    {
        return max(0, $this->limitFor($tenantId) - $this->used($tenantId, $nowTs));
    }

    /** Whether the tenant may make one more AI generation right now. */
    public function canUse(int $tenantId, ?int $nowTs = null): bool
    {
        return $this->remaining($tenantId, $nowTs) > 0;
    }

    /** Record one AI generation for the current period (upsert + increment). */
    public function record(int $tenantId, ?int $nowTs = null): void
    {
        $db     = db_connect();
        $period = self::period($nowTs);
        $now    = date('Y-m-d H:i:s');

        $existing = $db->table('ai_usage')
            ->where('tenant_id', $tenantId)->where('period', $period)
            ->get()->getRowArray();

        if ($existing) {
            $db->table('ai_usage')
                ->where('id', $existing['id'])
                ->set('used', 'used + 1', false)
                ->update(['updated_at' => $now]);
        } else {
            $db->table('ai_usage')->insert([
                'tenant_id'  => $tenantId, 'period' => $period, 'used' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** Usage snapshot for the API/UI. */
    public function snapshot(int $tenantId, ?int $nowTs = null): array
    {
        $limit = $this->limitFor($tenantId);
        $used  = $this->used($tenantId, $nowTs);
        return [
            'period'    => self::period($nowTs),
            'used'      => $used,
            'limit'     => $limit,
            'remaining' => max(0, $limit - $used),
        ];
    }

    private static function period(?int $nowTs): string
    {
        return gmdate('Y-m', $nowTs ?? time());
    }
}
