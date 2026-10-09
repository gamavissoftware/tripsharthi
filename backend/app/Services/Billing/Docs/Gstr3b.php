<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

/**
 * GSTR-3B (monthly summary return + tax payment) from the outward supplies TravelPilot issued. PURE.
 *
 * What is derived from your invoices: 3.1(a) taxable outward supplies, 3.1(c) nil-rated/exempt, 3.2 inter-state supplies to unregistered
 * buyers by place of supply, and the output tax liability. What is NOT known to TravelPilot and must be ENTERED (by you / your CA):
 * input tax credit (we do not capture suppliers' tax invoices), reverse-charge purchases, interest and late fee. Credit notes issued in the
 * month reduce that month's liability; if they exceed it the liability floors at zero and the excess is flagged (never silently carried).
 * Set-off follows Rule 88A: IGST credit first (against IGST, then CGST, then SGST), then CGST credit (CGST then IGST), then SGST credit
 * (SGST then IGST); CGST and SGST credits never pay each other's liability.
 */
final class Gstr3b
{
    /** @return array{igst:int,cgst:int,sgst:int} */
    private static function z(): array { return ['igst' => 0, 'cgst' => 0, 'sgst' => 0]; }

    /**
     * Rule 88A utilisation.
     * @param array{igst:int,cgst:int,sgst:int} $liab  output tax payable (paise)
     * @param array{igst:int,cgst:int,sgst:int} $credit available ITC incl. brought-forward balance (paise)
     * @return array{used:array<string,int>,cash:array{igst:int,cgst:int,sgst:int},closing:array{igst:int,cgst:int,sgst:int}}
     */
    public static function setOff(array $liab, array $credit): array
    {
        $l = $liab; $c = $credit;
        $used = ['igst_by_igst' => 0, 'cgst_by_igst' => 0, 'sgst_by_igst' => 0, 'cgst_by_cgst' => 0, 'igst_by_cgst' => 0, 'sgst_by_sgst' => 0, 'igst_by_sgst' => 0];
        $take = static function (string $payHead, string $creditHead, string $key) use (&$l, &$c, &$used): void {
            $x = min($c[$creditHead], $l[$payHead]);
            if ($x > 0) { $c[$creditHead] -= $x; $l[$payHead] -= $x; $used[$key] += $x; }
        };
        $take('igst', 'igst', 'igst_by_igst'); $take('cgst', 'igst', 'cgst_by_igst'); $take('sgst', 'igst', 'sgst_by_igst');   // IGST credit exhausts first
        $take('cgst', 'cgst', 'cgst_by_cgst'); $take('igst', 'cgst', 'igst_by_cgst');
        $take('sgst', 'sgst', 'sgst_by_sgst'); $take('igst', 'sgst', 'igst_by_sgst');
        return ['used' => $used, 'cash' => $l, 'closing' => $c];
    }

    /**
     * @param array<int,array> $docs invoice rows of the period (decoded buyer)
     * @param array{igst:int,cgst:int,sgst:int} $itc eligible ITC for the month (entered)
     * @param array{igst:int,cgst:int,sgst:int} $opening credit-ledger balance brought forward
     * @return array<string,mixed>
     */
    public static function build(string $gstin, string $period, array $docs, array $itc, array $opening, array $reverse = ['igst' => 0, 'cgst' => 0, 'sgst' => 0]): array
    {
        $sellerState = substr($gstin, 0, 2);
        $warnings = [];
        $a = ['taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0];      // 3.1(a)
        $nil = 0; $unreg = [];                                              // 3.1(c), 3.2
        $counts = ['invoices' => 0, 'credit_notes' => 0, 'skipped' => 0];
        foreach ($docs as $d) {
            if (($d['status'] ?? '') !== 'issued') { $counts['skipped']++; continue; }
            $isCn = $d['doc_type'] === 'credit_note';
            if (! in_array($d['doc_type'], ['tax_invoice', 'bill_of_supply', 'credit_note'], true)) { $counts['skipped']++; continue; }   // receipts are not supplies
            $sign = $isCn ? -1 : 1;
            $isCn ? $counts['credit_notes']++ : $counts['invoices']++;
            $tax = (int) $d['igst'] + (int) $d['cgst'] + (int) $d['sgst'];
            $tv = $sign * (int) $d['taxable_value'];
            if ($d['doc_type'] === 'bill_of_supply' || ((float) $d['gst_rate'] === 0.0 && $tax === 0)) { $nil += $tv; continue; }   // 3.1(c)
            $a['taxable'] += $tv; $a['igst'] += $sign * (int) $d['igst']; $a['cgst'] += $sign * (int) $d['cgst']; $a['sgst'] += $sign * (int) $d['sgst'];
            $pos = (string) ($d['place_of_supply'] ?? '');
            if ($pos !== '' && $pos !== $sellerState && empty($d['buyer']['gstin'])) {                                          // 3.2: inter-state to unregistered
                $unreg[$pos] ??= ['pos' => $pos, 'taxable' => 0, 'igst' => 0];
                $unreg[$pos]['taxable'] += $tv; $unreg[$pos]['igst'] += $sign * (int) $d['igst'];
            }
        }
        $liab = ['igst' => $a['igst'], 'cgst' => $a['cgst'], 'sgst' => $a['sgst']];
        $excess = [];
        foreach ($liab as $h => $v) { if ($v < 0) { $excess[$h] = -$v; $liab[$h] = 0; } }
        if ($excess) { $warnings[] = 'Credit notes issued this month exceed the tax on invoices (' . implode(', ', array_map(static fn ($h, $v) => strtoupper($h) . ' ₹' . number_format($v / 100, 2), array_keys($excess), $excess)) . '). The liability is shown as zero; ask your CA how to adjust the excess in a later month.'; }

        $netItc = ['igst' => max(0, $itc['igst'] - $reverse['igst']), 'cgst' => max(0, $itc['cgst'] - $reverse['cgst']), 'sgst' => max(0, $itc['sgst'] - $reverse['sgst'])];
        $credit = ['igst' => $netItc['igst'] + $opening['igst'], 'cgst' => $netItc['cgst'] + $opening['cgst'], 'sgst' => $netItc['sgst'] + $opening['sgst']];
        $set = self::setOff($liab, $credit);
        $cashTotal = array_sum($set['cash']);

        // The 5% no-ITC package scheme: a non-zero ITC is almost always a mistake worth a second look.
        $rates = array_values(array_unique(array_map(static fn ($d) => (float) $d['gst_rate'], array_filter($docs, static fn ($d) => $d['doc_type'] === 'tax_invoice' && ($d['status'] ?? '') === 'issued'))));
        if (array_sum($itc) > 0 && $rates === [5.0]) { $warnings[] = 'You entered input tax credit, but every invoice this month is at 5%. Tour packages at 5% do not allow ITC on the package itself — confirm with your CA before claiming it.'; }
        if (array_sum($itc) === 0 && in_array(18.0, $rates, true)) { $warnings[] = 'You have invoices at 18% (the ITC scheme) but entered no input tax credit. Enter the ITC from your suppliers\' invoices, otherwise you will pay more tax than needed.'; }
        if ($a['taxable'] < 0) { $warnings[] = 'Net taxable value is negative for the month (credit notes exceed supplies).'; }
        $warnings[] = 'Reverse-charge purchases, interest and late fee are not calculated here — add them with your CA if they apply.';

        $rs = static fn (int $p): float => round($p / 100, 2);
        $head = static fn (array $x): array => ['iamt' => $rs($x['igst']), 'camt' => $rs($x['cgst']), 'samt' => $rs($x['sgst']), 'csamt' => 0.0];
        $json = [
            'gstin' => $gstin, 'ret_period' => substr($period, 5, 2) . substr($period, 0, 4),
            'sup_details' => [
                'osup_det' => ['txval' => $rs(max(0, $a['taxable']))] + $head($liab),
                'osup_zero' => ['txval' => 0.0, 'iamt' => 0.0, 'csamt' => 0.0],
                'osup_nil_exmp' => ['txval' => $rs(max(0, $nil))],
                'isup_rev' => ['txval' => 0.0, 'iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0],
                'osup_nongst' => ['txval' => 0.0],
            ],
            'inter_sup' => ['unreg_details' => array_values(array_filter(array_map(static fn ($u) => $u['taxable'] > 0 ? ['pos' => $u['pos'], 'txval' => $rs($u['taxable']), 'iamt' => $rs(max(0, $u['igst']))] : null, $unreg))), 'comp_details' => [], 'uin_details' => []],
            'itc_elg' => [
                'itc_avl' => [['ty' => 'IMPG', 'iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0], ['ty' => 'IMPS', 'iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0],
                              ['ty' => 'ISRC', 'iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0], ['ty' => 'ISD', 'iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0], ['ty' => 'OTH'] + $head($itc)],
                'itc_rev' => [['ty' => 'RUL'] + $head(self::z()), ['ty' => 'OTH'] + $head($reverse)],
                'itc_net' => $head($netItc),
                'itc_inelg' => [['ty' => 'RUL', 'iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0], ['ty' => 'OTH', 'iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0]],
            ],
            'inward_sup' => ['isup_details' => [['ty' => 'GST', 'inter' => 0.0, 'intra' => 0.0], ['ty' => 'NONGST', 'inter' => 0.0, 'intra' => 0.0]]],
            'intr_ltfee' => ['intr_details' => ['iamt' => 0.0, 'camt' => 0.0, 'samt' => 0.0, 'csamt' => 0.0]],
        ];

        return ['period' => $period, 'gstin' => $gstin,
            'outward' => ['taxable' => $a, 'liability' => $liab, 'nil_exempt' => max(0, $nil), 'unregistered_interstate' => array_values($unreg)],
            'itc' => ['entered' => $itc, 'reversed' => $reverse, 'net' => $netItc, 'opening' => $opening, 'available' => $credit],
            'payment' => ['liability' => $liab, 'by_itc' => $set['used'], 'cash' => $set['cash'], 'cash_total' => $cashTotal, 'closing_credit' => $set['closing']],
            'counts' => $counts, 'warnings' => $warnings, 'json' => $json];
    }
}
