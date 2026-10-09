<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

use App\Models\InvoiceModel;

/** Loads one month of tax documents for a tenant and hands them to the pure {@see GstReturns} builders. */
final class GstExportService
{
    public const FILES = ['gstr1.json' => 'application/json', 'gstr3b.json' => 'application/json', 'hsn.csv' => 'text/csv; charset=utf-8', 'register.csv' => 'text/csv; charset=utf-8', 'tally.xml' => 'application/xml'];
    private const HEADS = ['igst', 'cgst', 'sgst'];

    public function __construct(private readonly ?int $now = null) {}
    private function today(): string { return date('Y-m-d', $this->now ?? time()); }

    public static function validPeriod(string $p): bool { return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p) && (int) substr($p, 0, 4) >= 2017; }

    /** @return array<int,array> decoded invoice rows for the month (tax invoices, bills of supply, credit notes, receipts) */
    public function documents(int $tenantId, string $period): array
    {
        if (! self::validPeriod($period)) { throw new \InvalidArgumentException('Choose a month like 2026-10.'); }
        $from = $period . '-01'; $to = date('Y-m-t', strtotime($from));
        $rows = (new InvoiceModel())->setTenant($tenantId)->where('issue_date >=', $from)->where('issue_date <=', $to)->orderBy('issue_date', 'ASC')->orderBy('id', 'ASC')->findAll();
        $against = [];
        if ($ids = array_values(array_unique(array_filter(array_map(static fn ($r) => $r['related_id'], $rows))))) {
            foreach ((new InvoiceModel())->setTenant($tenantId)->whereIn('id', $ids)->findAll() as $o) { $against[(int) $o['id']] = $o['number']; }
        }
        foreach ($rows as &$r) {
            $r['buyer'] = json_decode((string) $r['buyer'], true) ?: [];
            $r['_against'] = $r['related_id'] ? ($against[(int) $r['related_id']] ?? '') : '';
        }
        return $rows;
    }

    private function gstin(int $tenantId): array
    {
        $p = (new BusinessProfileService())->get($tenantId);
        if (empty($p['gstin'])) { throw new \DomainException('Add your GSTIN in the business profile first — GSTR-1 applies only to GST-registered businesses.'); }
        return [$p['gstin'], $p['trade_name'] ?: $p['legal_name'] ?: ''];
    }

    public function summary(int $tenantId, string $period): array
    {
        [$gstin] = $this->gstin($tenantId);
        $r = GstReturns::gstr1($gstin, $period, $this->documents($tenantId, $period), $this->sac($tenantId));
        $missing = (new \App\Services\Einvoice\EinvoiceService())->missingIrn($tenantId, $period . '-01', date('Y-m-t', strtotime($period . '-01')));
        if ($missing > 0) { $r['warnings'][] = "{$missing} B2B tax invoice(s) issued while e-invoicing is on have no IRN. A B2B invoice without an IRN is not valid — raise a credit note and re-issue each one, or ask your CA."; }
        return ['period' => $period, 'gstin' => $gstin, 'summary' => $r['summary'], 'warnings' => $r['warnings']];
    }

    /** @return array{body:string,type:string,filename:string} */
    public function file(int $tenantId, string $period, string $file): array
    {
        if (! isset(self::FILES[$file])) { throw new \InvalidArgumentException('Unknown export file.'); }
        [$gstin, $company] = $this->gstin($tenantId);
        $docs = $this->documents($tenantId, $period);
        $body = match ($file) {
            'gstr1.json'   => (string) json_encode(GstReturns::gstr1($gstin, $period, $docs, $this->sac($tenantId))['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT),
            'gstr3b.json'  => (string) json_encode($this->gstr3b($tenantId, $period)['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT),
            'hsn.csv'      => HsnSummary::csv(HsnSummary::build($docs, $this->sac($tenantId))),
            'register.csv' => GstReturns::registerCsv($docs),
            'tally.xml'    => GstReturns::tallyXml($company, $docs),
        };
        $ext = substr($file, strrpos($file, '.'));
        $base = ['gstr1.json' => 'GSTR1_' . $gstin . '_' . $period, 'gstr3b.json' => 'GSTR3B_' . $gstin . '_' . $period, 'hsn.csv' => 'HSN_summary_' . $period, 'register.csv' => 'Sales_register_' . $period, 'tally.xml' => 'Tally_vouchers_' . $period][$file];
        return ['body' => $body, 'type' => self::FILES[$file], 'filename' => $base . $ext];
    }

    private function sac(int $tenantId): string { return (string) ((new BusinessProfileService())->get($tenantId)['sac_code'] ?: '998554'); }

    // ---- GSTR-3B ---------------------------------------------------------------------------------------------------

    /** @return array{igst:int,cgst:int,sgst:int} */
    private static function heads(array $row, string $prefix): array { return ['igst' => (int) ($row[$prefix . 'igst'] ?? 0), 'cgst' => (int) ($row[$prefix . 'cgst'] ?? 0), 'sgst' => (int) ($row[$prefix . 'sgst'] ?? 0)]; }

    private function entry(int $tenantId, string $period): ?array
    {
        return db_connect()->table('gst_itc_entries')->where('tenant_id', $tenantId)->where('period', $period)->get()->getRowArray() ?: null;
    }

    /** The credit-ledger balance brought into $period: typed by the user, or carried from last month's closing balance, or zero. */
    private function opening(int $tenantId, string $period, int $depth): array
    {
        $zero = ['igst' => 0, 'cgst' => 0, 'sgst' => 0];
        $e = $this->entry($tenantId, $period);
        if ($e && (int) $e['opening_set'] === 1) { return [self::heads($e, 'open_'), 'entered']; }
        $earlier = (int) db_connect()->table('gst_itc_entries')->where('tenant_id', $tenantId)->where('period <', $period)->countAllResults();
        if ($depth >= 12 || $earlier === 0) { return [$zero, 'none']; }          // never entered ITC before: nothing to carry
        $prev = date('Y-m', strtotime($period . '-01 -1 month'));
        return [$this->compute($tenantId, $prev, $depth + 1)['payment']['closing_credit'], 'carried'];
    }

    private function compute(int $tenantId, string $period, int $depth): array
    {
        [$gstin] = $this->gstin($tenantId);
        $e = $this->entry($tenantId, $period);
        [$opening, $src] = $this->opening($tenantId, $period, $depth);
        $r = Gstr3b::build($gstin, $period, $this->documents($tenantId, $period), $e ? self::heads($e, 'itc_') : ['igst' => 0, 'cgst' => 0, 'sgst' => 0], $opening, $e ? self::heads($e, 'rev_') : ['igst' => 0, 'cgst' => 0, 'sgst' => 0]);
        $r['itc']['opening_source'] = $src; $r['itc']['entry'] = $e ? ['notes' => $e['notes'], 'updated_at' => $e['updated_at']] : null;
        return $r;
    }

    /** GSTR-3B for a month + a reconciliation against GSTR-1 and the filing status. */
    public function gstr3b(int $tenantId, string $period): array
    {
        if (! self::validPeriod($period)) { throw new \InvalidArgumentException('Choose a month like 2026-10.'); }
        $r = $this->compute($tenantId, $period, 0);
        $g1 = GstReturns::gstr1($r['gstin'], $period, $this->documents($tenantId, $period), $this->sac($tenantId))['summary']['net'];
        $diff = [];
        foreach (self::HEADS as $h) { $out = (int) $r['outward']['taxable'][$h]; if ($out !== (int) $g1[$h]) { $diff[$h] = $out - (int) $g1[$h]; } }
        $r['reconciliation'] = ['matches_gstr1' => $diff === [], 'difference' => $diff];
        $f = db_connect()->table('gst_filings')->where('tenant_id', $tenantId)->where('period', $period)->get()->getResultArray();
        $r['filings'] = $this->filingStatus($period, $f);
        return $r;
    }

    /** Save the month's ITC (what TravelPilot cannot know). Amounts in rupees. */
    public function saveItc(int $tenantId, string $period, array $in, ?int $userId = null): array
    {
        if (! self::validPeriod($period)) { throw new \InvalidArgumentException('Choose a month like 2026-10.'); }
        $paise = static function ($v, string $label): int {
            $x = (float) $v;
            if ($x < 0 || $x > 1_000_000_000) { throw new \InvalidArgumentException("{$label} must be between 0 and 100 crore rupees."); }
            return (int) round($x * 100);
        };
        $row = ['notes' => isset($in['notes']) ? mb_substr(trim((string) $in['notes']), 0, 255) : null, 'updated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s')];
        foreach (self::HEADS as $h) {
            $row['itc_' . $h] = $paise($in['itc_' . $h] ?? 0, 'ITC ' . strtoupper($h));
            $row['rev_' . $h] = $paise($in['rev_' . $h] ?? 0, 'ITC reversal ' . strtoupper($h));
        }
        $row['opening_set'] = ! empty($in['opening_set']) ? 1 : 0;
        foreach (self::HEADS as $h) { $row['open_' . $h] = $row['opening_set'] ? $paise($in['open_' . $h] ?? 0, 'Opening ' . strtoupper($h)) : 0; }
        $db = db_connect();
        if ($this->entry($tenantId, $period)) { $db->table('gst_itc_entries')->where('tenant_id', $tenantId)->where('period', $period)->update($row); }
        else { $db->table('gst_itc_entries')->insert($row + ['tenant_id' => $tenantId, 'period' => $period, 'created_at' => date('Y-m-d H:i:s')]); }
        \App\Services\Crm\AuditLogger::log('gst.itc', 'gst_itc', null, null, ['period' => $period, 'itc_total' => $row['itc_igst'] + $row['itc_cgst'] + $row['itc_sgst']], $tenantId, $userId);
        return $this->gstr3b($tenantId, $period);
    }

    public function itcEntry(int $tenantId, string $period): array
    {
        $e = $this->entry($tenantId, $period);
        return ['period' => $period, 'itc' => self::heads($e ?? [], 'itc_'), 'reversed' => self::heads($e ?? [], 'rev_'), 'opening_set' => (bool) ($e['opening_set'] ?? false), 'opening' => self::heads($e ?? [], 'open_'), 'notes' => $e['notes'] ?? null];
    }

    public function hsn(int $tenantId, string $period): array
    {
        $rows = HsnSummary::build($this->documents($tenantId, $period), $this->sac($tenantId));
        return ['period' => $period, 'rows' => $rows, 'note' => 'Services have no quantity, so the unit is NA. Credit notes reduce their row. Use 4 digits up to INR 5 Cr turnover and 6 digits above — your SAC is on the Business profile.'];
    }

    // ---- filing tracker ---------------------------------------------------------------------------------------------

    private function filingStatus(string $period, array $rows, ?string $today = null): array
    {
        $today ??= $this->today();
        $by = [];
        foreach ($rows as $f) { $by[$f['return_type']] = $f; }
        $out = [];
        foreach (['GSTR1', 'GSTR3B'] as $t) {
            $out[$t] = GstDeadlines::status($period, $t, $by[$t]['filed_on'] ?? null, $today) + ['arn' => $by[$t]['arn'] ?? null, 'filed_on' => $by[$t]['filed_on'] ?? null, 'tax_paid_cash' => isset($by[$t]['tax_paid_cash']) ? (int) $by[$t]['tax_paid_cash'] : null];
        }
        return $out;
    }

    /** Twelve months of a financial year (fy = start year) with both returns' status. */
    public function filings(int $tenantId, int $fy, ?string $today = null): array
    {
        $today ??= $this->today();
        $all = db_connect()->table('gst_filings')->where('tenant_id', $tenantId)->where('period >=', sprintf('%04d-04', $fy))->where('period <=', sprintf('%04d-03', $fy + 1))->get()->getResultArray();
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $p = date('Y-m', strtotime(sprintf('%04d-04-01 +%d months', $fy, $i)));
            if (date('Y-m-t', strtotime($p . '-01')) >= $today) { break; }     // a month that has not ended yet has nothing to file
            $months[] = ['period' => $p] + $this->filingStatus($p, array_values(array_filter($all, static fn ($f) => $f['period'] === $p)), $today);
        }
        return ['fy' => $fy, 'months' => $months];
    }

    public function recordFiling(int $tenantId, string $period, string $type, array $in, ?int $userId = null): array
    {
        if (! self::validPeriod($period)) { throw new \InvalidArgumentException('Choose a month like 2026-10.'); }
        if (! in_array($type, ['GSTR1', 'GSTR3B'], true)) { throw new \InvalidArgumentException('Choose GSTR-1 or GSTR-3B.'); }
        $on = (string) ($in['filed_on'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || strtotime($on) === false || $on > $this->today()) { throw new \InvalidArgumentException('Enter the date you filed (not in the future).'); }
        $arn = strtoupper(trim((string) ($in['arn'] ?? '')));
        if ($arn !== '' && ! preg_match('/^[A-Z0-9]{15}$/', $arn)) { throw new \InvalidArgumentException('The ARN on the acknowledgement is 15 letters/digits.'); }
        $cash = isset($in['tax_paid_rs']) && $in['tax_paid_rs'] !== '' ? (int) round(((float) $in['tax_paid_rs']) * 100) : null;
        $db = db_connect();
        $row = ['filed_on' => $on, 'arn' => $arn !== '' ? $arn : null, 'tax_paid_cash' => $cash, 'notes' => isset($in['notes']) ? mb_substr(trim((string) $in['notes']), 0, 255) : null, 'created_by' => $userId];
        $have = $db->table('gst_filings')->where('tenant_id', $tenantId)->where('period', $period)->where('return_type', $type)->get()->getRowArray();
        if ($have) { $db->table('gst_filings')->where('id', $have['id'])->update($row); }
        else { $db->table('gst_filings')->insert($row + ['tenant_id' => $tenantId, 'period' => $period, 'return_type' => $type, 'created_at' => date('Y-m-d H:i:s')]); }
        \App\Services\Crm\AuditLogger::log('gst.filed', 'gst_filing', null, null, ['period' => $period, 'return' => $type, 'arn' => $arn], $tenantId, $userId);
        return $this->filingStatus($period, $db->table('gst_filings')->where('tenant_id', $tenantId)->where('period', $period)->get()->getResultArray());
    }

    public function removeFiling(int $tenantId, string $period, string $type, ?int $userId = null): void
    {
        db_connect()->table('gst_filings')->where('tenant_id', $tenantId)->where('period', $period)->where('return_type', $type)->delete();
        \App\Services\Crm\AuditLogger::log('gst.unfiled', 'gst_filing', null, null, ['period' => $period, 'return' => $type], $tenantId, $userId);
    }
}
