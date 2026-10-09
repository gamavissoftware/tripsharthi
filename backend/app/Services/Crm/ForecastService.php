<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\SalesTargetModel;
use App\Models\UserModel;

/**
 * Pipeline forecasting + target attainment (Phase I3).
 *
 * Weighted forecast = Σ (open deal value × stage.probability). Attainment compares
 * each rep's won value in a period against their sales_target, alongside their
 * weighted open forecast. Read-only aggregation, tenant-scoped.
 */
final class ForecastService
{
    /** @return array{total_open:int, total_weighted:int, by_owner:array} */
    public function weightedForecast(int $tenantId): array
    {
        $rows = db_connect()->table('deals d')
            ->select('d.owner_id, SUM(d.value_amount) AS open_value, SUM(d.value_amount * s.probability / 100.0) AS weighted')
            ->join('pipeline_stages s', 's.id = d.stage_id', 'left')
            ->where('d.tenant_id', $tenantId)->where('d.status', 'open')->where('d.deleted_at', null)
            ->groupBy('d.owner_id')
            ->get()->getResultArray();

        $byOwner = array_map(fn ($r) => [
            'owner_id' => (int) $r['owner_id'],
            'name'     => $this->ownerName($tenantId, (int) $r['owner_id']),
            'open'     => (int) round((float) $r['open_value']),
            'weighted' => (int) round((float) $r['weighted']),
        ], $rows);

        return [
            'total_open'     => array_sum(array_column($byOwner, 'open')),
            'total_weighted' => array_sum(array_column($byOwner, 'weighted')),
            'by_owner'       => $byOwner,
        ];
    }

    /**
     * Per-rep attainment for a period (won value vs target + weighted forecast).
     * @return array<int, array>
     */
    public function attainment(int $tenantId, string $from, string $to): array
    {
        $db = db_connect();

        // Won value per owner in the window (by won_at, falling back to updated_at).
        $won = [];
        foreach ($db->table('deals')->select('owner_id, SUM(value_amount) AS v, COUNT(*) AS n')
            ->where('tenant_id', $tenantId)->where('status', 'won')->where('deleted_at', null)
            ->where('COALESCE(won_at, updated_at) >=', $from . ' 00:00:00')
            ->where('COALESCE(won_at, updated_at) <=', $to . ' 23:59:59')
            ->groupBy('owner_id')->get()->getResultArray() as $r) {
            $won[(int) $r['owner_id']] = ['value' => (int) $r['v'], 'count' => (int) $r['n']];
        }

        // Targets overlapping the window.
        $targets = [];
        foreach ((new SalesTargetModel())->setTenant($tenantId)
            ->where('period_start <=', $to)->where('period_end >=', $from)->findAll() as $t) {
            $targets[(int) $t['user_id']] = $t;
        }

        $forecast = [];
        foreach ($this->weightedForecast($tenantId)['by_owner'] as $o) {
            $forecast[$o['owner_id']] = $o['weighted'];
        }

        $userIds = array_unique(array_merge(array_keys($won), array_keys($targets), array_keys($forecast)));
        $out = [];
        foreach ($userIds as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $target  = (int) ($targets[$uid]['target_amount'] ?? 0);
            $metric  = $targets[$uid]['metric'] ?? 'won_value';
            $actual  = $metric === 'won_count' ? (int) ($won[$uid]['count'] ?? 0) : (int) ($won[$uid]['value'] ?? 0);
            $out[] = [
                'user_id'        => $uid,
                'name'           => $this->ownerName($tenantId, $uid),
                'metric'         => $metric,
                'target'         => $target,
                'actual'         => $actual,
                'weighted'       => (int) ($forecast[$uid] ?? 0),
                'attainment_pct' => $target > 0 ? round($actual / $target * 100, 1) : null,
            ];
        }
        usort($out, static fn ($a, $b) => $b['actual'] <=> $a['actual']);
        return $out;
    }

    private function ownerName(int $tenantId, int $userId): string
    {
        if ($userId <= 0) {
            return 'Unassigned';
        }
        $u = (new UserModel())->setTenant($tenantId)->find($userId);
        return $u['name'] ?? ('User #' . $userId);
    }
}
