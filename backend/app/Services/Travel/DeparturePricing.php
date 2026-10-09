<?php

declare(strict_types=1);

namespace App\Services\Travel;

/** Pure seat-pricing maths for a group departure. All money is integer paise. */
final class DeparturePricing
{
    /** @return array{sell:int,cost:int,seats:int} sell is EX-TAX; GST/TCS are added by the normal itinerary pricing */
    public static function quote(array $dep, int $adults, int $children, int $singleRooms): array
    {
        if ($adults < 1) { throw new \InvalidArgumentException('At least one adult is needed.'); }
        if ($children < 0 || $singleRooms < 0) { throw new \InvalidArgumentException('Counts cannot be negative.'); }
        if ($singleRooms > $adults) { throw new \InvalidArgumentException('Single rooms cannot exceed the number of adults.'); }
        $pct = max(0, min(100, (int) $dep['child_price_pct']));
        $sell = $adults * (int) $dep['price_pax'] + $children * (int) round((int) $dep['price_pax'] * $pct / 100) + $singleRooms * (int) $dep['single_supplement'];
        $cost = $adults * (int) $dep['cost_pax'] + $children * (int) round((int) $dep['cost_pax'] * $pct / 100) + $singleRooms * (int) $dep['single_supplement_cost'];
        return ['sell' => $sell, 'cost' => $cost, 'seats' => $adults + $children];
    }

    /** Seats still sellable given what is confirmed and what is held (unexpired). Never negative. */
    public static function available(int $total, int $confirmed, int $held): int { return max(0, $total - $confirmed - $held); }

    /** Display state of a departure. */
    public static function state(array $dep, int $available, int $confirmed, string $today): string
    {
        if ($dep['status'] === 'cancelled') { return 'cancelled'; }
        if ($dep['status'] === 'completed' || $dep['end_date'] < $today) { return 'completed'; }
        if ($dep['status'] === 'closed' || ($dep['sell_cutoff'] !== null && $dep['sell_cutoff'] < $today) || $dep['start_date'] <= $today) { return 'closed'; }
        return $available === 0 ? 'full' : 'open';
    }

    /** Go / no-go: a departure close to leaving without enough travellers needs a decision. */
    public static function atRisk(array $dep, int $confirmed, string $today, int $withinDays = 30): bool
    {
        if ((int) $dep['min_pax'] <= 0 || $confirmed >= (int) $dep['min_pax'] || in_array($dep['status'], ['cancelled', 'completed'], true)) { return false; }
        $days = (int) floor((strtotime($dep['start_date']) - strtotime($today)) / 86400);
        return $days >= 0 && $days <= $withinDays;
    }
}
