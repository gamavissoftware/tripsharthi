<?php

declare(strict_types=1);

namespace App\Services\Travel;

/**
 * Itinerary pricing: supplier cost -> markup -> discount -> GST -> TCS.
 *
 * All amounts are integer paise. Rates are inputs, never hard-coded in the
 * maths, because they change by Finance Act:
 *   - GST on tour-operator services: 5% (no ITC) or 18% (with ITC).
 *   - TCS on overseas tour packages: 2% flat from 1 Apr 2026 (was 5% up to
 *     INR 10L and 20% above). Collected on the amount charged incl. GST;
 *     have the tenant's CA confirm the base before relying on it.
 * TCS applies only to international trips.
 */
final class PricingService
{
    public const DEFAULT_GST_RATE = 5.0;
    public const DEFAULT_TCS_RATE = 2.0;

    /**
     * @param int    $costTotal   sum of supplier costs (paise)
     * @param string $markupType  'percent' (of cost) or 'flat' (paise)
     * @param float  $markupValue percent, or paise when flat
     * @return array{cost_total:int,sell_subtotal:int,discount_amount:int,gst_rate:float,gst_amount:int,tcs_rate:float,tcs_amount:int,grand_total:int,margin_amount:int}
     */
    public static function price(
        int $costTotal,
        string $markupType = 'percent',
        float $markupValue = 15.0,
        int $discount = 0,
        bool $international = false,
        float $gstRate = self::DEFAULT_GST_RATE,
        float $tcsRate = self::DEFAULT_TCS_RATE,
    ): array {
        $costTotal = max(0, $costTotal);
        $markup    = $markupType === 'flat'
            ? max(0, (int) round($markupValue))
            : (int) round($costTotal * max(0.0, $markupValue) / 100);

        $gross    = $costTotal + $markup;
        $discount = min(max(0, $discount), $gross);
        $subtotal = $gross - $discount;

        $gst = (int) round($subtotal * max(0.0, $gstRate) / 100);
        $tcsRateApplied = $international ? max(0.0, $tcsRate) : 0.0;
        $tcs = (int) round(($subtotal + $gst) * $tcsRateApplied / 100);

        return [
            'cost_total'      => $costTotal,
            'sell_subtotal'   => $subtotal,
            'discount_amount' => $discount,
            'gst_rate'        => $gstRate,
            'gst_amount'      => $gst,
            'tcs_rate'        => $tcsRateApplied,
            'tcs_amount'      => $tcs,
            'grand_total'     => $subtotal + $gst + $tcs,
            'margin_amount'   => $subtotal - $costTotal,
        ];
    }

    /**
     * Split a total into a booking amount + equal instalments before travel.
     * The last instalment absorbs rounding so the schedule sums exactly.
     *
     * @return list<array{label:string,due_date:string,amount:int}>
     */
    public static function paymentSchedule(int $total, string $today, ?string $travelStart, int $depositPct = 30, int $instalments = 2): array
    {
        $deposit = (int) round($total * min(100, max(0, $depositPct)) / 100);
        $rows    = [['label' => 'Booking amount', 'due_date' => $today, 'amount' => $deposit]];
        $rest    = $total - $deposit;
        if ($rest <= 0) {
            return $rows;
        }
        $instalments = max(1, $instalments);
        $per         = intdiv($rest, $instalments);
        // Balance is due 15 days before travel; earlier instalments spread evenly from today.
        $end   = $travelStart ? strtotime($travelStart . ' -15 days') : strtotime($today . ' +30 days');
        $start = strtotime($today);
        if ($end <= $start) {
            $end = strtotime($today . ' +7 days');
        }
        $accum = 0;
        for ($i = 1; $i <= $instalments; $i++) {
            $amount = $i === $instalments ? $rest - $accum : $per;
            $accum += $amount;
            $due    = $start + (int) (($end - $start) * $i / $instalments);
            $rows[] = [
                'label'    => $i === $instalments ? 'Balance' : "Instalment {$i}",
                'due_date' => date('Y-m-d', $due),
                'amount'   => $amount,
            ];
        }
        return $rows;
    }
}
