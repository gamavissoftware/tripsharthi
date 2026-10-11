<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Services\Billing\Docs\AmountInWords;
use App\Services\Billing\Docs\DocFormat;
use App\Services\Billing\Docs\Gst;
use App\Services\Billing\Docs\PdfRenderer;
use App\Services\Crm\AuditLogger;
use App\Services\Email\EmailLayout;
use App\Services\Email\EmailService;

/**
 * Credit notes against TripSarthi's own GST invoices: the GST document for a refund (full or partial).
 *
 * This records the refund; it does NOT move money. The team refunds the customer in Razorpay / the bank, then issues the credit note and types
 * the refund reference (the same "make the transfer first, then record it" rule as partner payouts).
 *
 * Rules (all tested):
 *  - The admin enters the refund AMOUNT INCLUDING GST (what the customer gets back). The note splits it at the invoice's rate and supply type
 *    (CGST+SGST or IGST). Credit notes can never exceed the invoice in total, taxable value or any tax head; a credit note that takes the whole
 *    REMAINDER uses the exact remaining components, so rounding can never leave a stray paisa on the invoice.
 *  - Numbers are gap-free per series + financial year, in their OWN series (credit prefix, default CN), created with the row and the PDF in one
 *    transaction. The invoice row is locked meanwhile, so two credit notes cannot over-credit it.
 *  - Immutable like invoices: parties copied from the invoice, PDF stored once with its sha256, never regenerated.
 *  - A FULL credit (the invoice is now fully credited) voids the partner commission that payment earned, if it is still pending; a commission
 *    already paid out is left alone and reported (settle it with the partner by hand).
 *
 * NOT built: refunding through the Razorpay API, ending the customer's plan automatically, credit notes for invoices of other series.
 */
final class PlatformCreditNoteService
{
    public const MIN_REASON = 10;

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(): string { return date('Y-m-d H:i:s', $this->ts()); }
    private function indiaDate(): string { return gmdate('Y-m-d', $this->ts() + 19800); }
    private function inv(): PlatformInvoiceService { return new PlatformInvoiceService($this->now); }

    /** What is still creditable on an invoice. @return array{taxable:int,cgst:int,sgst:int,igst:int,total:int,credited:int} */
    public function remaining(int $invoiceId): array
    {
        $db = db_connect();
        $i = $db->table('platform_invoices')->where('id', $invoiceId)->get()->getRowArray() ?: throw new \OutOfBoundsException('Invoice not found.');
        $c = $db->query('SELECT COALESCE(SUM(taxable_value),0) t, COALESCE(SUM(cgst),0) c, COALESCE(SUM(sgst),0) s, COALESCE(SUM(igst),0) g, COALESCE(SUM(total),0) n FROM platform_credit_notes WHERE invoice_id = ?', [$invoiceId])->getRowArray();
        return ['taxable' => (int) $i['taxable_value'] - (int) $c['t'], 'cgst' => (int) $i['cgst'] - (int) $c['c'], 'sgst' => (int) $i['sgst'] - (int) $c['s'], 'igst' => (int) $i['igst'] - (int) $c['g'], 'total' => (int) $i['total'] - (int) $c['n'], 'credited' => (int) $c['n']];
    }

    /**
     * @param int|null $totalPaise refund amount INCLUDING GST; null = everything still creditable
     * @return array the credit note row
     */
    public function issue(int $invoiceId, ?int $totalPaise, string $reason, ?string $refundRef, int $adminId): array
    {
        $reason = trim(preg_replace('/\s+/u', ' ', strip_tags($reason)) ?? '');
        if (mb_strlen($reason) < self::MIN_REASON) { throw new \InvalidArgumentException('Write the reason for the credit note (at least ' . self::MIN_REASON . ' characters). It is printed on the document.'); }
        $reason = mb_substr($reason, 0, 300);
        $refundRef = $refundRef !== null ? mb_substr(trim(strip_tags($refundRef)), 0, 80) : null; $refundRef = $refundRef === '' ? null : $refundRef;
        $s = $this->inv()->settings();
        if ($errs = PlatformInvoiceService::validate($s)) { throw new \DomainException('Invoice settings are incomplete: ' . implode(' ', $errs)); }

        $db = db_connect(); $path = null;
        $db->transStart();
        try {
            $inv = $db->query('SELECT * FROM platform_invoices WHERE id = ? FOR UPDATE', [$invoiceId])->getRowArray() ?: throw new \OutOfBoundsException('Invoice not found.');
            $rem = $this->remaining($invoiceId);
            if ($rem['total'] <= 0) { throw new \DomainException('This invoice is already fully credited.'); }
            $total = $totalPaise ?? $rem['total'];
            if ($total <= 0) { throw new \InvalidArgumentException('Enter an amount greater than zero.'); }
            if ($total > $rem['total']) { throw new \InvalidArgumentException('That is more than can still be credited on this invoice (' . DocFormat::inr($rem['total']) . ' left).'); }

            if ($total === $rem['total']) { $parts = ['taxable' => $rem['taxable'], 'cgst' => $rem['cgst'], 'sgst' => $rem['sgst'], 'igst' => $rem['igst']]; }   // the whole remainder: exact, no rounding drift
            else {
                $rate = (float) $inv['gst_rate'];
                $taxable = (int) round($total * 100 / (100 + $rate)); $tax = $total - $taxable;
                $intra = $inv['supply_type'] === 'intra';                                    // same rule as the invoice: intra = CGST + SGST (odd paisa to SGST), inter = IGST
                $cgst = $intra ? min(intdiv($tax, 2), $rem['cgst']) : 0; $sgst = $intra ? min($tax - intdiv($tax, 2), $rem['sgst']) : 0; $igst = $intra ? 0 : min($tax, $rem['igst']);
                $taxable = $total - $cgst - $sgst - $igst;
                if ($taxable > $rem['taxable'] || $taxable < 0) { throw new \InvalidArgumentException('That amount cannot be split within what is left on the invoice. Try the full remaining amount.'); }
                $parts = ['taxable' => $taxable, 'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst];
            }

            $date = $this->indiaDate(); $fy = Gst::fy($date); $series = strtoupper($s['credit_prefix']);
            $db->query('INSERT IGNORE INTO platform_doc_sequences (series, fy, last_seq) VALUES (?, ?, 0)', [$series, $fy]);
            $seq = (int) $db->query('SELECT last_seq FROM platform_doc_sequences WHERE series = ? AND fy = ? FOR UPDATE', [$series, $fy])->getRowArray()['last_seq'] + 1;
            $db->query('UPDATE platform_doc_sequences SET last_seq = ? WHERE series = ? AND fy = ?', [$seq, $series, $fy]);
            $number = Gst::invoiceNumber($series, $fy, $seq);
            $row = ['number' => $number, 'series' => $series, 'fy' => $fy, 'seq' => $seq, 'issue_date' => $date, 'invoice_id' => $invoiceId, 'invoice_number' => $inv['number'], 'invoice_date' => $inv['issue_date'],
                'tenant_id' => (int) $inv['tenant_id'], 'source' => $inv['source'], 'payment_ref' => $inv['payment_ref'], 'plan' => $inv['plan'], 'buyer_type' => $inv['buyer_type'], 'buyer_name' => $inv['buyer_name'], 'buyer_gstin' => $inv['buyer_gstin'],
                'place_of_supply' => $inv['place_of_supply'], 'supply_type' => $inv['supply_type'], 'sac' => $inv['sac'], 'gst_rate' => $inv['gst_rate'], 'taxable_value' => $parts['taxable'], 'cgst' => $parts['cgst'], 'sgst' => $parts['sgst'], 'igst' => $parts['igst'],
                'total' => $total, 'reason' => $reason, 'refund_ref' => $refundRef, 'seller' => $inv['seller'], 'buyer' => $inv['buyer'], 'email_to' => $inv['email_to'], 'created_by' => $adminId, 'created_at' => $this->stamp()];
            $db->table('platform_credit_notes')->insert($row);
            $id = (int) $db->insertID();
            $bytes = $this->renderPdf($row + ['id' => $id], $inv);
            $rel = $fy . '/' . str_replace('/', '-', $number) . '.pdf'; $abs = $this->inv()->storageDir() . '/' . $rel;
            if (! is_dir(dirname($abs)) && ! mkdir(dirname($abs), 0775, true) && ! is_dir(dirname($abs))) { throw new \RuntimeException('Could not create the folder for the credit note.'); }
            if (file_put_contents($abs, $bytes) === false) { throw new \RuntimeException('Could not store the credit note PDF.'); }
            $path = $abs;
            $db->table('platform_credit_notes')->where('id', $id)->update(['pdf_path' => $rel, 'pdf_sha256' => hash('sha256', $bytes)]);
            $db->transComplete();
            if (! $db->transStatus()) { throw new \RuntimeException('Could not save the credit note.'); }
        } catch (\Throwable $e) {
            $db->transRollback(); if ($path) { @unlink($path); }
            throw $e;
        }
        $note = $db->table('platform_credit_notes')->where('id', $id)->get()->getRowArray();
        AuditLogger::log('admin.creditnote.issue', 'tenant', (int) $note['tenant_id'], null, ['credit_note' => $note['number'], 'invoice' => $note['invoice_number'], 'total_paise' => $total, 'reason' => $reason], (int) $note['tenant_id'], $adminId);
        return $note + ['commission' => $this->voidCommissionIfFullyCredited((int) $inv['subscription_id'], $invoiceId, $note['number'], $adminId)];
    }

    /** A fully credited payment earns no commission: void a pending one; report one already paid out. */
    private function voidCommissionIfFullyCredited(?int $subscriptionId, int $invoiceId, string $number, int $adminId): string
    {
        if (! $subscriptionId || $this->remaining($invoiceId)['total'] > 0) { return 'none'; }
        $db = db_connect();
        $c = $db->table('partner_commissions')->where('subscription_id', $subscriptionId)->get()->getRowArray();
        if (! $c) { return 'none'; }
        if ($c['status'] === 'paid') { return 'paid_out'; }
        if ($c['status'] === 'void') { return 'none'; }
        try { (new \App\Services\Partner\PartnerService($this->now))->voidCommission((int) $c['id'], "Refunded: credit note {$number}", $adminId); return 'voided'; }
        catch (\Throwable $e) { log_message('error', 'Could not void the partner commission after a refund: ' . $e->getMessage()); return 'failed'; }
    }

    // ---- the PDF ----------------------------------------------------------------------------------------------------------------------------

    private function renderPdf(array $cn, array $inv): string
    {
        $seller = json_decode((string) $cn['seller'], true); $buyer = json_decode((string) $cn['buyer'], true);
        $mark = is_file(APPPATH . 'Views/pdf/ts-mark.png') ? 'data:image/png;base64,' . base64_encode((string) file_get_contents(APPPATH . 'Views/pdf/ts-mark.png')) : null;
        $html = view('pdf/platform_credit_note', ['mark' => $mark, 'seller' => $seller, 'buyer' => $buyer, 'doc' => [
            'number' => $cn['number'], 'date' => DocFormat::date($cn['issue_date']), 'invoice_number' => $cn['invoice_number'], 'invoice_date' => DocFormat::date($cn['invoice_date']),
            'place_name' => Gst::STATES[$cn['place_of_supply']] ?? '', 'place_of_supply' => $cn['place_of_supply'], 'supply_type' => $cn['supply_type'], 'sac' => $cn['sac'], 'gst_rate' => (float) $cn['gst_rate'],
            'description' => $inv['description'], 'plan' => ucfirst((string) $cn['plan']), 'taxable_value' => (int) $cn['taxable_value'], 'cgst' => (int) $cn['cgst'], 'sgst' => (int) $cn['sgst'], 'igst' => (int) $cn['igst'],
            'total' => (int) $cn['total'], 'words' => AmountInWords::paise((int) $cn['total']), 'reason' => $cn['reason'], 'refund_ref' => $cn['refund_ref'], 'invoice_total' => (int) $inv['total']]]);
        return PdfRenderer::render($html, $cn['number'] . ' · ' . $seller['legal_name'] . ' · GSTIN ' . $seller['gstin'], 'Credit note ' . $cn['number']);
    }

    /** The stored PDF; refuses a missing or altered file. */
    public function pdf(int $id, ?int $onlyTenantId = null): array
    {
        $n = db_connect()->table('platform_credit_notes')->where('id', $id)->get()->getRowArray();
        if (! $n || ($onlyTenantId !== null && (int) $n['tenant_id'] !== $onlyTenantId)) { throw new \OutOfBoundsException('Credit note not found.'); }
        $path = $this->inv()->storageDir() . '/' . $n['pdf_path'];
        $bytes = $n['pdf_path'] && is_file($path) ? (string) file_get_contents($path) : null;
        if ($bytes === null) { throw new \DomainException('The stored PDF for ' . $n['number'] . ' is missing. Restore it from the backup of writable/uploads/platform-invoices.'); }
        if (! hash_equals((string) $n['pdf_sha256'], hash('sha256', $bytes))) { throw new \DomainException('The stored PDF for ' . $n['number'] . ' no longer matches what was issued, so it was not opened.'); }
        return ['bytes' => $bytes, 'filename' => str_replace('/', '-', (string) $n['number']) . '.pdf', 'credit_note' => $n];
    }

    // ---- e-mail -----------------------------------------------------------------------------------------------------------------------------

    /** @return array{sent:bool,to:?string,error:?string} */
    public function email(int $id): array
    {
        $db = db_connect(); $f = $this->pdf($id); $n = $f['credit_note'];
        $buyer = json_decode((string) $n['buyer'], true); $seller = json_decode((string) $n['seller'], true);
        $to = trim((string) ($n['email_to'] ?: ($buyer['email'] ?? '')));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $db->table('platform_credit_notes')->where('id', $id)->update(['email_attempts' => (int) $n['email_attempts'] + 1, 'email_error' => 'This customer has no valid e-mail address.']);
            return ['sent' => false, 'to' => null, 'error' => 'This customer has no valid e-mail address.'];
        }
        $rupees = DocFormat::inr((int) $n['total']);
        $m = EmailLayout::build(['subject' => "TripSarthi credit note {$n['number']}", 'preheader' => "Credit note {$n['number']} for {$rupees} against invoice {$n['invoice_number']} is attached.", 'eyebrow' => 'Credit note', 'title' => 'Your credit note is ready',
            'blocks' => [
                ['p', 'We have issued a credit note against your invoice. It is attached to this e-mail as a PDF.'],
                ['facts', array_values(array_filter([['Credit note no.', $n['number']], ['Date', DocFormat::date($n['issue_date'])], ['Against invoice', $n['invoice_number'] . ' (' . DocFormat::date($n['invoice_date']) . ')'],
                    ['Amount credited', $rupees . ' (' . DocFormat::inr((int) $n['taxable_value']) . ' + GST ' . DocFormat::inr((int) $n['cgst'] + (int) $n['sgst'] + (int) $n['igst']) . ')'], ['Reason', $n['reason']], $n['refund_ref'] ? ['Refund reference', $n['refund_ref']] : null]))],
                ['p', 'Keep this document with the original invoice: it reduces the GST on that invoice, which matters if you claimed input tax credit on it. Questions? Reply to this e-mail or use Help & support inside TripSarthi.'],
            ], 'footer_note' => $seller['legal_name'] . ' · GSTIN ' . $seller['gstin']]);
        $bcc = trim((string) $this->inv()->settings()['copy_to']) ?: null;
        $subject = "TripSarthi credit note {$n['number']} - {$rupees}"; $att = [['filename' => $f['filename'], 'content' => $f['bytes'], 'mime' => 'application/pdf']];
        $ok = PlatformInvoiceService::$mailer !== null ? (bool) (PlatformInvoiceService::$mailer)($to, $subject, $m['html'], $m['text'], $att, $bcc) : EmailService::send($to, $subject, $m['html'], $m['text'], null, [], $att, $bcc);
        $err = $ok ? null : mb_substr((string) (EmailService::$lastError ?: 'The mail server refused the message.'), 0, 300);
        $db->table('platform_credit_notes')->where('id', $id)->update(['email_to' => $to, 'email_attempts' => (int) $n['email_attempts'] + 1, 'emailed_at' => $ok ? $this->stamp() : $n['emailed_at'], 'email_error' => $err]);
        return ['sent' => $ok, 'to' => $to, 'error' => $err];
    }

    // ---- reading ----------------------------------------------------------------------------------------------------------------------------

    private function where(array $p): array
    {
        $w = ['1=1']; $b = [];
        $month = (string) ($p['month'] ?? ''); $from = (string) ($p['from'] ?? ''); $to = (string) ($p['to'] ?? ''); $fy = (string) ($p['fy'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $month)) { $from = $month . '-01'; $to = date('Y-m-t', strtotime($from)); }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w[] = 'c.issue_date >= ?'; $b[] = $from; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $w[] = 'c.issue_date <= ?'; $b[] = $to; }
        if (preg_match('/^\d{4}-\d{2}$/', $fy)) { $w[] = 'c.fy = ?'; $b[] = $fy; }
        $q = trim((string) ($p['q'] ?? ''));
        if ($q !== '') { $w[] = '(c.number LIKE ? OR c.invoice_number LIKE ? OR c.buyer_name LIKE ? OR c.buyer_gstin LIKE ? OR c.email_to LIKE ? OR c.refund_ref LIKE ? OR c.tenant_id = ?)'; $like = '%' . $q . '%'; array_push($b, $like, $like, $like, $like, $like, $like, ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : 0); }
        if (in_array($p['buyer_type'] ?? '', ['b2b', 'b2c'], true)) { $w[] = 'c.buyer_type = ?'; $b[] = $p['buyer_type']; }
        if (in_array($p['supply_type'] ?? '', ['intra', 'inter'], true)) { $w[] = 'c.supply_type = ?'; $b[] = $p['supply_type']; }
        if (! empty($p['plan'])) { $w[] = 'c.plan = ?'; $b[] = (string) $p['plan']; }
        if (in_array($p['source'] ?? '', ['online', 'offline'], true)) { $w[] = 'c.source = ?'; $b[] = $p['source']; }
        if (! empty($p['pos']) && preg_match('/^\d{2}$/', (string) $p['pos'])) { $w[] = 'c.place_of_supply = ?'; $b[] = $p['pos']; }
        if (! empty($p['tenant_id'])) { $w[] = 'c.tenant_id = ?'; $b[] = (int) $p['tenant_id']; }
        if (! empty($p['invoice_id'])) { $w[] = 'c.invoice_id = ?'; $b[] = (int) $p['invoice_id']; }
        match ($p['email'] ?? '') { 'sent' => $w[] = 'c.emailed_at IS NOT NULL', 'failed' => $w[] = 'c.emailed_at IS NULL AND c.email_error IS NOT NULL', 'pending' => $w[] = 'c.emailed_at IS NULL', default => null };
        if (isset($p['min']) && $p['min'] !== '') { $w[] = 'c.total >= ?'; $b[] = (int) round((float) $p['min'] * 100); }
        if (isset($p['max']) && $p['max'] !== '') { $w[] = 'c.total <= ?'; $b[] = (int) round((float) $p['max'] * 100); }
        return [implode(' AND ', $w), $b];
    }

    /** @return array{rows:list<array>,total:int,page:int,per_page:int,totals:array} */
    public function list(array $p = []): array
    {
        $db = db_connect(); [$where, $b] = $this->where($p);
        $per = max(5, min(1000, (int) ($p['per_page'] ?? 25))); $page = max(1, (int) ($p['page'] ?? 1));
        $order = match ($p['sort'] ?? 'newest') { 'oldest' => 'c.issue_date ASC, c.id ASC', 'amount' => 'c.total DESC, c.id DESC', 'customer' => 'c.buyer_name ASC, c.id DESC', default => 'c.issue_date DESC, c.id DESC' };
        $t = $db->query("SELECT COUNT(*) n, COALESCE(SUM(c.taxable_value),0) taxable, COALESCE(SUM(c.cgst),0) cgst, COALESCE(SUM(c.sgst),0) sgst, COALESCE(SUM(c.igst),0) igst, COALESCE(SUM(c.total),0) total FROM platform_credit_notes c WHERE {$where}", $b)->getRowArray();
        $rows = $db->query("SELECT c.id, c.number, c.issue_date, c.fy, c.invoice_id, c.invoice_number, c.invoice_date, c.tenant_id, c.source, c.payment_ref, c.plan, c.buyer_type, c.buyer_name, c.buyer_gstin, c.place_of_supply, c.supply_type, c.sac, c.gst_rate,
                c.taxable_value, c.cgst, c.sgst, c.igst, c.total, c.reason, c.refund_ref, c.email_to, c.emailed_at, c.email_attempts, c.email_error FROM platform_credit_notes c WHERE {$where} ORDER BY {$order} LIMIT {$per} OFFSET " . (($page - 1) * $per), $b)->getResultArray();
        foreach ($rows as &$r) { foreach (['id', 'invoice_id', 'tenant_id', 'taxable_value', 'cgst', 'sgst', 'igst', 'total', 'email_attempts'] as $k) { $r[$k] = (int) $r[$k]; } $r['gst_rate'] = (float) $r['gst_rate']; $r['place_name'] = Gst::STATES[$r['place_of_supply']] ?? ''; $r['gst'] = $r['cgst'] + $r['sgst'] + $r['igst']; } unset($r);
        return ['rows' => $rows, 'total' => (int) $t['n'], 'page' => $page, 'per_page' => $per,
            'totals' => ['count' => (int) $t['n'], 'taxable' => (int) $t['taxable'], 'cgst' => (int) $t['cgst'], 'sgst' => (int) $t['sgst'], 'igst' => (int) $t['igst'], 'gst' => (int) $t['cgst'] + (int) $t['sgst'] + (int) $t['igst'], 'total' => (int) $t['total']]];
    }

    /** B2B (by GSTIN) and B2C (by place of supply) credit-note totals for the GST summary. */
    public function summary(array $p = []): array
    {
        $db = db_connect(); [$where, $b] = $this->where($p);
        $agg = 'COUNT(*) n, SUM(c.taxable_value) taxable, SUM(c.cgst) cgst, SUM(c.sgst) sgst, SUM(c.igst) igst, SUM(c.total) total';
        $fix = static function (array $rows): array { foreach ($rows as &$r) { foreach (['n', 'taxable', 'cgst', 'sgst', 'igst', 'total'] as $k) { $r[$k] = (int) $r[$k]; } $r['gst'] = $r['cgst'] + $r['sgst'] + $r['igst']; } return $rows; };
        $b2b = $fix($db->query("SELECT c.buyer_gstin gstin, MAX(c.buyer_name) name, {$agg} FROM platform_credit_notes c WHERE {$where} AND c.buyer_type = 'b2b' GROUP BY c.buyer_gstin ORDER BY total DESC", $b)->getResultArray());
        $b2c = $fix($db->query("SELECT c.place_of_supply pos, c.gst_rate rate, {$agg} FROM platform_credit_notes c WHERE {$where} AND c.buyer_type = 'b2c' GROUP BY c.place_of_supply, c.gst_rate ORDER BY total DESC", $b)->getResultArray());
        foreach ($b2c as &$r) { $r['place_name'] = Gst::STATES[$r['pos']] ?? ''; $r['rate'] = (float) $r['rate']; } unset($r);
        return ['totals' => $this->list($p + ['per_page' => 5])['totals'], 'b2b' => $b2b, 'b2c' => $b2c];
    }

    /** A workspace's own credit notes (customer app). */
    public function forTenant(int $tenantId): array
    {
        $rows = db_connect()->query('SELECT id, number, issue_date, invoice_number, reason, total FROM platform_credit_notes WHERE tenant_id = ? ORDER BY issue_date DESC, id DESC', [$tenantId])->getResultArray();
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['total'] = (int) $r['total']; } unset($r);
        return $rows;
    }
}
