<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

/**
 * GSTR-1 table 12: HSN/SAC-wise summary of outward supplies. PURE.
 * One row per (SAC, GST rate). Credit notes REDUCE the row (negative values), bills of supply and 0% invoices appear at rate 0.
 * Services have no quantity, so UQC is "NA" and quantity 0. Reporting digits: 4 up to INR 5 Cr turnover, 6 above — the SAC on the profile
 * is 6 digits by default, which satisfies both; `digits` lets the caller truncate for the 4-digit rule.
 */
final class HsnSummary
{
    public const DESCRIPTION = 'Tour operator services';

    /**
     * @param array<int,array> $docs invoice rows (doc_type, status, sac, gst_rate, taxable_value, cgst, sgst, igst, total)
     * @return list<array{hsn:string,desc:string,uqc:string,qty:int,val:int,taxable:int,igst:int,cgst:int,sgst:int,cess:int,rate:float,docs:int}> paise
     */
    public static function build(array $docs, string $defaultSac = '998554', int $digits = 6): array
    {
        $rows = [];
        foreach ($docs as $d) {
            if (($d['status'] ?? '') !== 'issued' || ! in_array($d['doc_type'], ['tax_invoice', 'bill_of_supply', 'credit_note'], true)) { continue; }
            $sign = $d['doc_type'] === 'credit_note' ? -1 : 1;
            $sac = preg_replace('/\D/', '', (string) ($d['sac'] ?? '')) ?: $defaultSac;
            $sac = substr($sac, 0, max(4, min(8, $digits)));
            $rate = (float) $d['gst_rate'];
            $k = $sac . '|' . $rate;
            $rows[$k] ??= ['hsn' => $sac, 'desc' => self::DESCRIPTION, 'uqc' => 'NA', 'qty' => 0, 'val' => 0, 'taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0, 'cess' => 0, 'rate' => $rate, 'docs' => 0];
            $rows[$k]['val'] += $sign * (int) $d['total']; $rows[$k]['taxable'] += $sign * (int) $d['taxable_value'];
            $rows[$k]['igst'] += $sign * (int) $d['igst']; $rows[$k]['cgst'] += $sign * (int) $d['cgst']; $rows[$k]['sgst'] += $sign * (int) $d['sgst'];
            $rows[$k]['docs']++;
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => [$a['hsn'], $a['rate']] <=> [$b['hsn'], $b['rate']]);     // numeric rate order (a string key would put 18 before 5)
        return $rows;
    }

    /** The `hsn` block of the GSTR-1 JSON (rupees, two decimals). */
    public static function json(array $rows): array
    {
        $rs = static fn (int $p): float => round($p / 100, 2);
        $data = [];
        foreach ($rows as $i => $r) {
            $data[] = ['num' => $i + 1, 'hsn_sc' => $r['hsn'], 'desc' => $r['desc'], 'uqc' => $r['uqc'], 'qty' => 0, 'rt' => $r['rate'], 'val' => $rs($r['val']), 'txval' => $rs($r['taxable']),
                'iamt' => $rs($r['igst']), 'camt' => $rs($r['cgst']), 'samt' => $rs($r['sgst']), 'csamt' => 0.0];
        }
        return ['data' => $data];
    }

    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['HSN/SAC', 'Description', 'UQC', 'Quantity', 'GST rate %', 'Total value', 'Taxable value', 'IGST', 'CGST', 'SGST', 'Cess', 'Documents'], ',', '"', '');
        $f = static fn (int $p): string => number_format($p / 100, 2, '.', '');
        foreach ($rows as $r) { fputcsv($out, [$r['hsn'], $r['desc'], $r['uqc'], 0, (string) $r['rate'], $f($r['val']), $f($r['taxable']), $f($r['igst']), $f($r['cgst']), $f($r['sgst']), '0.00', $r['docs']], ',', '"', ''); }
        rewind($out);
        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }
}
