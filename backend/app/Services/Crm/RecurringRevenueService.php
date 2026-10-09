<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\DealModel;

/**
 * Recurring-revenue reporting (v1). Aggregates won, recurring deals into MRR
 * (monthly recurring revenue) and ARR (= MRR × 12), normalizing each deal's
 * per-cycle value_amount by its billing interval. All amounts in paise.
 */
final class RecurringRevenueService
{
    /**
     * @return array{mrr:int, arr:int, count:int, by_interval:array<string,int>}
     */
    public function summary(int $tenantId): array
    {
        $rows = (new DealModel())->setTenant($tenantId)
            ->where('status', 'won')
            ->where('is_recurring', 1)
            ->where('recurring_interval IS NOT NULL', null, false)
            ->select('value_amount, recurring_interval')
            ->findAll();

        $mrr        = 0;
        $byInterval = [];
        foreach ($rows as $r) {
            $interval = (string) ($r['recurring_interval'] ?? '');
            $monthly  = DealModel::monthlyRevenue((int) ($r['value_amount'] ?? 0), $interval);
            $mrr += $monthly;
            $byInterval[$interval] = ($byInterval[$interval] ?? 0) + $monthly;
        }

        return [
            'mrr'         => $mrr,
            'arr'         => $mrr * 12,
            'count'       => count($rows),
            'by_interval' => $byInterval,
        ];
    }
}
