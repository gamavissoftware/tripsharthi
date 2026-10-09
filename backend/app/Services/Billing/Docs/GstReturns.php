<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

/**
 * Pure builders for the GSTR-1 outward-supply return, a sales register and a Tally import file.
 * Input = rows of the `invoices` table (already decoded: buyer as array) for ONE period. No DB, no clock.
 *
 * Section rules (GSTN GSTR-1):
 *  - B2B   : buyer has a GSTIN.
 *  - B2CL  : no GSTIN, inter-state, invoice value above INR 2.5 lakh (threshold since 1 Aug 2024).
 *  - B2CS  : every other unregistered supply, summarised by (intra/inter, place of supply, rate).
 *  - CDNR  : credit notes against B2B invoices.   CDNUR: credit notes against B2CL invoices.
 *  - Credit notes against small B2C supplies are NETTED into B2CS (negative values), as GSTR-1 requires.
 *  - Receipts are not tax documents and Bills of Supply carry no GST, so neither is reported here.
 * Amounts in the GSTN JSON are rupees with two decimals; everything internal stays integer paise.
 */
final class GstReturns
{
    public const B2CL_LIMIT = 25_000_000;   // INR 2,50,000 in paise

    private static function rs(int $paise): float { return round($paise / 100, 2); }
    private static function dmy(string $ymd): string { return date('d-m-Y', strtotime($ymd)); }

    /**
     * @param array<int,array> $docs invoices rows: doc_type, number, issue_date, status, buyer(array), place_of_supply, supply_type, taxable_value, gst_rate, cgst, sgst, igst, tcs, total, related_id, id
     * @return array{json:array,summary:array,warnings:string[]}
     */
    public static function gstr1(string $sellerGstin, string $period, array $docs, string $defaultSac = '998554'): array
    {
        $warnings = [];
        $invoices = []; $notes = []; $skipped = ['bill_of_supply' => 0, 'receipt' => 0, 'cancelled' => 0];
        $byId = [];
        foreach ($docs as $d) { $byId[(int) $d['id']] = $d; }

        foreach ($docs as $d) {
            if ($d['status'] !== 'issued') { $skipped['cancelled']++; continue; }
            if ($d['doc_type'] === 'bill_of_supply') { $skipped['bill_of_supply']++; continue; }
            if ($d['doc_type'] === 'receipt') { $skipped['receipt']++; continue; }
            if (! in_array($d['doc_type'], ['tax_invoice', 'credit_note'], true)) { continue; }
            $gstin = (string) ($d['buyer']['gstin'] ?? '');
            if ($gstin !== '' && ! Gst::validGstin($gstin)) { $warnings[] = "{$d['number']}: the customer GSTIN {$gstin} is not valid — fix with a credit note and re-issue."; }
            $pos = (string) ($d['place_of_supply'] ?? '');
            if ($pos === '') { $warnings[] = "{$d['number']}: no place of supply recorded."; continue; }
            $tax = (int) $d['cgst'] + (int) $d['sgst'] + (int) $d['igst'];
            if ((int) $d['taxable_value'] + $tax + (int) $d['tcs'] !== (int) $d['total']) { $warnings[] = "{$d['number']}: taxable value + GST + TCS does not equal the total — check before filing."; }
            $d['_gstin'] = $gstin;
            $d['_val'] = (int) $d['total'];
            $d['_inter'] = $pos !== Gst::code(substr($sellerGstin, 0, 2));
            if ($d['doc_type'] === 'tax_invoice') { $invoices[] = $d; } else { $notes[] = $d; }
        }

        $item = static fn (array $d): array => ['num' => 1, 'itm_det' => ['txval' => self::rs((int) $d['taxable_value']), 'rt' => (float) $d['gst_rate'],
            'iamt' => self::rs((int) $d['igst']), 'camt' => self::rs((int) $d['cgst']), 'samt' => self::rs((int) $d['sgst']), 'csamt' => 0.0]];

        // ---- B2B / B2CL / B2CS
        $b2b = []; $b2cl = []; $b2cs = [];
        $b2clIds = [];
        foreach ($invoices as $d) {
            if ($d['_gstin'] !== '') {
                $b2b[$d['_gstin']][] = ['inum' => $d['number'], 'idt' => self::dmy($d['issue_date']), 'val' => self::rs($d['_val']), 'pos' => $d['place_of_supply'], 'rchrg' => 'N', 'inv_typ' => 'R', 'itms' => [$item($d)]];
            } elseif ($d['_inter'] && $d['_val'] > self::B2CL_LIMIT) {
                $b2cl[$d['place_of_supply']][] = ['inum' => $d['number'], 'idt' => self::dmy($d['issue_date']), 'val' => self::rs($d['_val']), 'itms' => [$item($d)]];
                $b2clIds[(int) $d['id']] = true;
            } else {
                self::addB2cs($b2cs, $d, 1);
            }
        }

        // ---- credit notes
        $cdnr = []; $cdnur = [];
        foreach ($notes as $n) {
            $orig = $n['related_id'] ? ($byId[(int) $n['related_id']] ?? null) : null;
            $note = ['ntty' => 'C', 'nt_num' => $n['number'], 'nt_dt' => self::dmy($n['issue_date']), 'val' => self::rs($n['_val']), 'pos' => $n['place_of_supply'], 'rchrg' => 'N', 'inv_typ' => 'R', 'itms' => [$item($n)]];
            if ($n['_gstin'] !== '') { $cdnr[$n['_gstin']][] = $note; }
            elseif (($orig && isset($b2clIds[(int) $orig['id']])) || (! $orig && $n['_inter'] && $n['_val'] > self::B2CL_LIMIT)) {
                $cdnur[] = ['ntty' => 'C', 'nt_num' => $n['number'], 'nt_dt' => self::dmy($n['issue_date']), 'val' => self::rs($n['_val']), 'typ' => 'B2CL', 'pos' => $n['place_of_supply'], 'itms' => [$item($n)]];
            } else {
                self::addB2cs($b2cs, $n, -1);
            }
        }

        // ---- document summary (table 13): numbering ranges per series, with gap detection
        $docIssue = []; $series = ['tax_invoice' => 1, 'credit_note' => 5];
        foreach ($series as $type => $no) {
            $rows = array_values(array_filter($docs, static fn ($d) => $d['doc_type'] === $type));
            if (! $rows) { continue; }
            usort($rows, static fn ($a, $b) => (int) $a['seq'] <=> (int) $b['seq']);
            $from = $rows[0]; $to = $rows[count($rows) - 1];
            $cancelled = count(array_filter($rows, static fn ($r) => $r['status'] !== 'issued'));
            $seqs = array_map(static fn ($r) => (int) $r['seq'], $rows);
            for ($i = 1; $i < count($seqs); $i++) { if ($seqs[$i] !== $seqs[$i - 1] + 1 && $seqs[$i] !== $seqs[$i - 1]) { $warnings[] = ucfirst(str_replace('_', ' ', $type)) . ' numbering has a gap between ' . $rows[$i - 1]['number'] . ' and ' . $rows[$i]['number'] . ' — explain it to your CA.'; } }
            $docIssue[] = ['doc_num' => $no, 'docs' => [['num' => 1, 'from' => $from['number'], 'to' => $to['number'], 'totnum' => count($rows), 'cancel' => $cancelled, 'net_issue' => count($rows) - $cancelled]]];
        }

        $fp = substr($period, 5, 2) . substr($period, 0, 4);
        $json = ['gstin' => $sellerGstin, 'fp' => $fp, 'version' => 'GST3.0.4', 'hash' => 'hash',
            'b2b' => array_map(static fn ($ctin, $inv) => ['ctin' => $ctin, 'inv' => $inv], array_keys($b2b), $b2b),
            'b2cl' => array_map(static fn ($pos, $inv) => ['pos' => (string) $pos, 'inv' => $inv], array_keys($b2cl), $b2cl),
            'b2cs' => array_values(array_map(static fn ($r) => ['sply_ty' => $r['inter'] ? 'INTER' : 'INTRA', 'pos' => $r['pos'], 'typ' => 'OE', 'rt' => (float) $r['rt'],
                'txval' => self::rs($r['tx']), 'iamt' => self::rs($r['i']), 'camt' => self::rs($r['c']), 'samt' => self::rs($r['s']), 'csamt' => 0.0], $b2cs)),
            'cdnr' => array_map(static fn ($ctin, $nt) => ['ctin' => $ctin, 'nt' => $nt], array_keys($cdnr), $cdnr),
            'cdnur' => $cdnur, 'doc_issue' => ['doc_det' => $docIssue]];
        if ($hsn = HsnSummary::build($docs, $defaultSac)) { $json['hsn'] = HsnSummary::json($hsn); }     // table 12
        $json = array_filter($json, static fn ($v) => ! is_array($v) || $v !== []);
        if (isset($json['doc_issue']) && $json['doc_issue']['doc_det'] === []) { unset($json['doc_issue']); }

        $sum = static function (array $rows): array {
            $t = ['taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0, 'tcs' => 0, 'total' => 0, 'count' => count($rows)];
            foreach ($rows as $r) { $t['taxable'] += (int) $r['taxable_value']; $t['igst'] += (int) $r['igst']; $t['cgst'] += (int) $r['cgst']; $t['sgst'] += (int) $r['sgst']; $t['tcs'] += (int) $r['tcs']; $t['total'] += $r['_val']; }
            return $t;
        };
        $inv = $sum($invoices); $cn = $sum($notes);
        $net = [];
        foreach (['taxable', 'igst', 'cgst', 'sgst', 'tcs', 'total'] as $k) { $net[$k] = $inv[$k] - $cn[$k]; }
        $summary = [
            'invoices' => $inv, 'credit_notes' => $cn, 'net' => $net,
            'sections' => ['b2b' => array_sum(array_map('count', $b2b)), 'b2cl' => array_sum(array_map('count', $b2cl)), 'b2cs_rows' => count($b2cs), 'cdnr' => array_sum(array_map('count', $cdnr)), 'cdnur' => count($cdnur)],
            'skipped' => $skipped,
        ];
        return ['json' => $json, 'summary' => $summary, 'warnings' => $warnings];
    }

    private static function addB2cs(array &$acc, array $d, int $sign): void
    {
        $k = ($d['_inter'] ? 'I' : 'A') . '|' . $d['place_of_supply'] . '|' . (float) $d['gst_rate'];
        $acc[$k] ??= ['inter' => $d['_inter'], 'pos' => $d['place_of_supply'], 'rt' => (float) $d['gst_rate'], 'tx' => 0, 'i' => 0, 'c' => 0, 's' => 0];
        $acc[$k]['tx'] += $sign * (int) $d['taxable_value']; $acc[$k]['i'] += $sign * (int) $d['igst']; $acc[$k]['c'] += $sign * (int) $d['cgst']; $acc[$k]['s'] += $sign * (int) $d['sgst'];
    }

    /** One line per tax document — what a CA opens in Excel. Credit notes are negative. */
    public static function registerCsv(array $docs): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Date', 'Document', 'Number', 'Against', 'Customer', 'Customer GSTIN', 'Place of supply', 'SAC', 'GST rate %', 'Taxable value', 'CGST', 'SGST', 'IGST', 'TCS', 'Total', 'Status'], ',', '"', '');
        foreach ($docs as $d) {
            if (! in_array($d['doc_type'], ['tax_invoice', 'bill_of_supply', 'credit_note'], true)) { continue; }
            $s = $d['doc_type'] === 'credit_note' ? -1 : 1;
            $f = static fn (int $p): string => number_format($s * $p / 100, 2, '.', '');
            fputcsv($out, [$d['issue_date'], str_replace('_', ' ', $d['doc_type']), self::csvSafe((string) $d['number']), self::csvSafe((string) ($d['_against'] ?? '')), self::csvSafe((string) ($d['buyer']['name'] ?? '')),
                (string) ($d['buyer']['gstin'] ?? ''), (string) ($d['place_of_supply'] ?? ''), (string) ($d['sac'] ?? ''), (string) (float) $d['gst_rate'], $f((int) $d['taxable_value']), $f((int) $d['cgst']), $f((int) $d['sgst']), $f((int) $d['igst']), $f((int) $d['tcs']), $f((int) $d['total']), (string) $d['status']], ',', '"', '');
        }
        rewind($out);
        return "\xEF\xBB\xBF" . stream_get_contents($out);   // BOM so Excel reads UTF-8 names correctly
    }

    /** Neutralise spreadsheet formula injection from customer-supplied text. */
    public static function csvSafe(string $v): string { return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v; }

    /**
     * Tally Prime/ERP9 "Import Data > Vouchers" XML. Ledgers must already exist in Tally (names configurable).
     * Sales: customer Dr = total; sales Cr = taxable; GST ledgers Cr; TCS Cr. Credit notes mirror it with the opposite signs.
     */
    public static function tallyXml(string $company, array $docs, array $ledgers = []): string
    {
        $L = $ledgers + ['sales' => 'Tour Package Sales', 'cgst' => 'Output CGST', 'sgst' => 'Output SGST', 'igst' => 'Output IGST', 'tcs' => 'TCS Payable'];
        $x = static fn (string $s): string => htmlspecialchars(preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $amt = static fn (int $p): string => number_format($p / 100, 2, '.', '');
        $entry = static function (string $ledger, bool $debit, int $paise) use ($x, $amt): string {
            return '<ALLLEDGERENTRIES.LIST><LEDGERNAME>' . $x($ledger) . '</LEDGERNAME><ISDEEMEDPOSITIVE>' . ($debit ? 'Yes' : 'No') . '</ISDEEMEDPOSITIVE><AMOUNT>' . ($debit ? '-' : '') . $amt($paise) . '</AMOUNT></ALLLEDGERENTRIES.LIST>';
        };
        $msgs = '';
        foreach ($docs as $d) {
            if (! in_array($d['doc_type'], ['tax_invoice', 'credit_note'], true) || $d['status'] !== 'issued') { continue; }
            $credit = $d['doc_type'] === 'credit_note';           // credit note: customer Cr, sales Dr
            $party = (string) ($d['buyer']['name'] ?? 'Customer');
            $lines = $entry($party, ! $credit, (int) $d['total']) . $entry($L['sales'], $credit, (int) $d['taxable_value']);
            foreach (['cgst', 'sgst', 'igst'] as $k) { if ((int) $d[$k] > 0) { $lines .= $entry($L[$k], $credit, (int) $d[$k]); } }
            if ((int) $d['tcs'] > 0) { $lines .= $entry($L['tcs'], $credit, (int) $d['tcs']); }
            $type = $credit ? 'Credit Note' : 'Sales';
            $msgs .= '<TALLYMESSAGE xmlns:UDF="TallyUDF"><VOUCHER VCHTYPE="' . $type . '" ACTION="Create" OBJVIEW="Accounting Voucher View"><DATE>' . date('Ymd', strtotime($d['issue_date'])) . '</DATE>'
                . '<VOUCHERTYPENAME>' . $type . '</VOUCHERTYPENAME><VOUCHERNUMBER>' . $x((string) $d['number']) . '</VOUCHERNUMBER><PARTYLEDGERNAME>' . $x($party) . '</PARTYLEDGERNAME>'
                . '<NARRATION>' . $x(($credit ? 'Credit note' : 'Tax invoice') . ' ' . $d['number'] . (($d['reason'] ?? '') !== '' ? ' — ' . $d['reason'] : '')) . '</NARRATION>' . $lines . '</VOUCHER></TALLYMESSAGE>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><ENVELOPE><HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER><BODY><IMPORTDATA><REQUESTDESC><REPORTNAME>Vouchers</REPORTNAME>'
            . '<STATICVARIABLES><SVCURRENTCOMPANY>' . $x($company) . '</SVCURRENTCOMPANY></STATICVARIABLES></REQUESTDESC><REQUESTDATA>' . $msgs . '</REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>';
    }
}
