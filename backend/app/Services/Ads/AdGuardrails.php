<?php

declare(strict_types=1);

namespace App\Services\Ads;

/**
 * Spend guardrails (pure — no I/O). Every path that can START or INCREASE spend goes through check().
 * Returns human-readable violations; empty list = allowed. Reductions and pauses are never blocked.
 *
 * The rules, in the order a user would hit them:
 *  1. Spend management must be switched on: a daily spend cap greater than zero is mandatory.
 *  2. INR accounts only (money handling here is paise; other currencies are refused, not guessed at).
 *  3. A campaign cannot run below the minimum daily budget (platforms reject tiny budgets anyway).
 *  4. The sum of ALL active daily budgets (TravelPilot-created and synced alike) may not exceed the cap.
 *  5. A single edit may raise a budget by at most max_increase_pct (also keeps Meta from resetting learning).
 */
final class AdGuardrails
{
    /**
     * @param array{daily_spend_cap:int,min_daily_budget:int,max_increase_pct:int} $s settings
     * @param int      $activeTotal sum of daily budgets of every ACTIVE campaign, INCLUDING this one at its old value
     * @param int      $newDaily    the budget being requested (paise)
     * @param int|null $oldDaily    the campaign's current budget, null when creating/launching a new one
     * @param bool     $willBeActive true when the change results in an active campaign (launch / edit of an active one)
     * @return list<string>
     */
    public static function check(array $s, string $currency, int $activeTotal, int $newDaily, ?int $oldDaily = null, bool $willBeActive = true): array
    {
        $v = [];
        if ((int) $s['daily_spend_cap'] <= 0) {
            return ['Set a daily spend cap in Ad settings first. TravelPilot will not launch or raise spend until you do.'];
        }
        if (strtoupper($currency) !== 'INR') {
            $v[] = "This ad account bills in {$currency}. TravelPilot manages INR accounts only.";
        }
        if ($newDaily < (int) $s['min_daily_budget']) {
            $v[] = 'Daily budget is below the minimum of ' . self::inr((int) $s['min_daily_budget']) . '.';
        }
        // Spend only ever goes DOWN or stays the same: always allowed (apart from the checks above for creation).
        $isReduction = $oldDaily !== null && $newDaily <= $oldDaily;
        if ($isReduction) {
            return array_values(array_filter($v, static fn ($m) => ! str_contains($m, 'below the minimum')));
        }
        if ($willBeActive) {
            $projected = $activeTotal - (int) ($oldDaily ?? 0) + $newDaily;
            if ($projected > (int) $s['daily_spend_cap']) {
                $v[] = 'This would bring total active daily spend to ' . self::inr($projected) . ', above your cap of ' . self::inr((int) $s['daily_spend_cap']) . '.';
            }
        }
        if ($oldDaily !== null && $oldDaily > 0) {
            $limit = (int) floor($oldDaily * (1 + ((int) $s['max_increase_pct']) / 100));
            if ($newDaily > $limit) {
                $v[] = "A single change can raise a budget by at most {$s['max_increase_pct']}% (to " . self::inr($limit) . '). Raise it in steps.';
            }
        }
        return $v;
    }

    public static function inr(int $paise): string
    {
        return '₹' . number_format($paise / 100, 0, '.', ',');
    }

    /** Rupees (float/string from a form) -> paise. */
    public static function toPaise(int|float|string $rupees): int
    {
        return (int) round(((float) $rupees) * 100);
    }

    /** Google Ads micros (1,000,000 = 1 unit of account currency) -> paise. */
    public static function microsToPaise(int|string $micros): int
    {
        return (int) round(((int) $micros) / 10_000);
    }

    public static function paiseToMicros(int $paise): int
    {
        return $paise * 10_000;
    }
}
