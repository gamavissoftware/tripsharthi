<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

/**
 * Pure TCS maths for overseas tour packages (s.206C(1G)). TCS is collected when money is RECEIVED, so a booking's TCS is
 * spread over its paid instalments in proportion to the amount received. Never exceeds the booking's TCS; when the booking
 * is fully paid the last instalment takes the exact remainder (no rounding drift).
 */
final class TcsLedger
{
    /**
     * @param array<int,array{id:int,amount:int,paid_at:string}> $paidPayments payments actually received
     * @return array<int,array{payment_id:int,date:string,received:int,tcs:int}>
     */
    public static function split(int $bookingTotal, int $bookingTcs, array $paidPayments): array
    {
        if ($bookingTcs <= 0 || $bookingTotal <= 0) { return []; }
        usort($paidPayments, static fn ($a, $b) => [$a['paid_at'], $a['id']] <=> [$b['paid_at'], $b['id']]);
        $out = []; $received = 0; $collected = 0;
        foreach ($paidPayments as $p) {
            $received += (int) $p['amount'];
            $tcs = $received >= $bookingTotal ? $bookingTcs - $collected : (int) round((int) $p['amount'] * $bookingTcs / $bookingTotal);
            $tcs = max(0, min($tcs, $bookingTcs - $collected));
            $collected += $tcs;
            $out[] = ['payment_id' => (int) $p['id'], 'date' => substr((string) $p['paid_at'], 0, 10), 'received' => (int) $p['amount'], 'tcs' => $tcs];
        }
        return $out;
    }

    /** Financial-year quarter => [from, to] dates. $fy = start year (2026 = FY 2026-27); $q = 1..4 (Q1 = Apr-Jun). */
    public static function quarter(int $fy, int $q): array
    {
        $startMonth = [1 => 4, 2 => 7, 3 => 10, 4 => 1][$q] ?? throw new \InvalidArgumentException('Quarter must be 1 to 4.');
        $year = $q === 4 ? $fy + 1 : $fy;
        $from = sprintf('%04d-%02d-01', $year, $startMonth);
        return [$from, date('Y-m-t', strtotime("$from +2 months"))];
    }

    /** @return string[] the three YYYY-MM periods of a quarter */
    public static function months(int $fy, int $q): array
    {
        [$from] = self::quarter($fy, $q);
        return array_map(static fn ($i) => date('Y-m', strtotime("$from +$i months")), [0, 1, 2]);
    }

    /** Challan due date: the 7th of the following month (the CA should confirm the special rule for March collections). */
    public static function dueDate(string $period): string { return date('Y-m-07', strtotime($period . '-01 +1 month')); }

    /** @return 'nil'|'paid'|'partial'|'due'|'overdue' */
    public static function status(int $collected, int $deposited, string $due, string $today): string
    {
        if ($collected <= 0) { return 'nil'; }
        if ($deposited >= $collected) { return 'paid'; }
        return $today > $due ? 'overdue' : ($deposited > 0 ? 'partial' : 'due');
    }

    public static function currentFy(string $date): int { return (int) substr($date, 5, 2) >= 4 ? (int) substr($date, 0, 4) : (int) substr($date, 0, 4) - 1; }
}
