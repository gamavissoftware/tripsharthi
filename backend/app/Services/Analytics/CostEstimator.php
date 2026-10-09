<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Turns billable message counts into an INR estimate.
 *
 * TravelPilot is never in the messaging-cost loop — Meta bills the customer's own
 * WABA directly (CLAUDE.md §1) — so this is a *forecast of the customer's Meta
 * invoice*, not a charge, and every surface that shows it must say "estimated".
 *
 * Rates are Meta's India per-message prices and they change; they are therefore
 * env-overridable rather than baked in, so a tenant on different rates (or a
 * different country) can correct them without a deploy.
 *
 * All amounts are in paise, matching the app-wide money convention (§9).
 */
final class CostEstimator
{
    /** Meta India per-message rates in paise, as of 2026-09. */
    public const DEFAULT_RATES = [
        'marketing'      => 78,  // ₹0.78
        'utility'        => 11,  // ₹0.11
        'authentication' => 13,  // ₹0.13
        'service'        => 0,   // free since Nov 2024
        'free_form'      => 0,   // only ever sent inside an open window
    ];

    /** Per-message rate in paise for a category, honouring env overrides. */
    public static function ratePaise(?string $category): int
    {
        $key = $category ?? 'marketing';

        $override = env('WA_RATE_' . strtoupper($key) . '_PAISE');
        if ($override !== null && $override !== '' && is_numeric($override)) {
            return max(0, (int) $override);
        }

        // An unrecognised category is priced as marketing: over-estimating the
        // customer's bill is the safe direction to be wrong in.
        return self::DEFAULT_RATES[$key] ?? self::DEFAULT_RATES['marketing'];
    }

    /**
     * Estimated spend, in paise, for a set of per-category billable counts.
     *
     * @param array<string, int> $billableByCategory category => billable message count
     */
    public static function estimate(array $billableByCategory): int
    {
        $total = 0;
        foreach ($billableByCategory as $category => $count) {
            $total += self::ratePaise((string) $category) * max(0, (int) $count);
        }

        return $total;
    }

    /** The full rate card, for display next to an estimate. */
    public static function rateCard(): array
    {
        $card = [];
        foreach (array_keys(self::DEFAULT_RATES) as $category) {
            $card[$category] = self::ratePaise($category);
        }

        return $card;
    }
}
