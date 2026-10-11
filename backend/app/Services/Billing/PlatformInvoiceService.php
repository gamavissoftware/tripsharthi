<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Services\Admin\PlatformSettings;
use App\Services\Billing\Docs\AmountInWords;
use App\Services\Billing\Docs\BusinessProfileService;
use App\Services\Billing\Docs\DocFormat;
use App\Services\Billing\Docs\Gst;
use App\Services\Billing\Docs\PdfRenderer;
use App\Services\Crm\AuditLogger;
use App\Services\Email\EmailLayout;
use App\Services\Email\EmailService;

/**
 * TripSarthi's own GST TAX INVOICES to its customers (a SaaS sale by the software owner, Gamavis Software Solutions).
 *
 * Issued automatically the moment a paid period starts (online Razorpay payment, or an offline payment the team records by hand) and e-mailed to the
 * workspace owner with the PDF attached. Complimentary / free periods (amount 0) get no invoice.
 *
 * Rules (all tested):
 *  - Plan prices EXCLUDE GST: checkout charges price + GST, the order records the split (subscriptions.taxable_paise / gst_paise) and the invoice uses it
 *    (price_basis 'exclusive'). A payment recorded by hand, or one that predates this rule, carries no split: the amount received is treated as
 *    GST-inclusive and carved out (price_basis 'inclusive'). Either way taxable + CGST + SGST + IGST equals the total to the paisa.
 *  - Intra-state (buyer in the seller's state) = CGST + SGST; otherwise IGST. Place of supply = the buyer's state (from a valid GSTIN, else the state in
 *    their business profile); with neither, the seller's state.
 *  - B2B only when the buyer has a checksum-valid GSTIN (their Settings -> Business & invoicing profile); otherwise B2C.
 *  - Numbers are gap-free per series + financial year ("GS/26-27/00001", max 16 characters); the number, the row and the PDF are created in ONE
 *    transaction, so a failure leaves no gap. One invoice per paid subscription row, ever (UNIQUE), so a webhook/browser race cannot issue two.
 *  - Issued invoices are immutable: seller and buyer are snapshots; the PDF is stored once with its sha256 and never regenerated; a missing or
 *    altered file refuses to open.
 *  - A failure here must NEVER undo a payment: callers wrap it in try/catch; the admin page lists paid periods that still have no invoice.
 *
 * NOT built (needs the CA / later): credit notes for refunds, e-invoicing (IRN; only needed above INR 5 Cr turnover), reverse charge, TCS/TDS.
 */
final class PlatformInvoiceService
{
    /** Test seam: replaces the real mailer. fn(string $to, string $subject, string $html, string $text, array $attachments, ?string $bcc): bool */
    public static ?\Closure $mailer = null;
    /** Tests switch automatic issuing on explicitly; everywhere else it is always on. */
    public static bool $enableInTests = false;
    private static array $queue = [];
    private static bool $shutdownHooked = false;

    /** Settings keys in platform_settings (all prefixed inv_). */
    public const FIELDS = ['legal_name', 'trade_name', 'gstin', 'pan', 'address_line1', 'address_line2', 'city', 'state_code', 'pincode', 'phone', 'email', 'website',
        'sac_code', 'gst_rate', 'prefix', 'credit_prefix', 'signatory', 'terms', 'bank_name', 'bank_account_name', 'bank_account_no', 'bank_ifsc', 'upi_id', 'copy_to', 'auto_email'];

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(?int $t = null): string { return date('Y-m-d H:i:s', $t ?? $this->ts()); }
    /** The GST rate charged on plan prices (percent), from the invoice settings. */
    public static function gstRate(): float
    {
        $r = PlatformSettings::get('inv_gst_rate');
        return $r !== null && is_numeric($r) ? (float) $r : (float) self::defaults()['gst_rate'];
    }

    /**
     * GST charged ON TOP of a price. @return array{rate:float,taxable:int,gst:int,total:int} amounts in paise; total is what the customer pays.
     */
    public static function gstOn(int $basePaise): array
    {
        $rate = self::gstRate(); $gst = (int) round($basePaise * $rate / 100);
        return ['rate' => $rate, 'taxable' => $basePaise, 'gst' => $gst, 'total' => $basePaise + $gst];
    }

    /** Today's date in India (invoice dates are Indian dates). */
    private function indiaDate(): string { return gmdate('Y-m-d', $this->ts() + 19800); }

    // ================================================================================================================================
    // seller details (the software owner)
    // ================================================================================================================================

    /** Seller defaults come from the GST registration certificate; every field can be changed in the admin app. */
    public static function defaults(): array
    {
        return ['legal_name' => 'Gamavis Software Solutions', 'trade_name' => 'Gamavis Software Solutions', 'gstin' => '06ABCFG6551H1ZG', 'pan' => 'ABCFG6551H',
            'address_line1' => 'Cabin No. 1, Basement Floor, 2/14/3, Mathura Road', 'address_line2' => 'Near Idea Shacks Coworking, Sector 32', 'city' => 'Faridabad', 'state_code' => '06', 'pincode' => '121003',
            'phone' => '', 'email' => (string) (env('CONTACT_NOTIFY_EMAIL') ?: ''), 'website' => 'https://tripsarthi.com', 'sac_code' => '998314', 'gst_rate' => '18', 'prefix' => 'GS', 'credit_prefix' => 'CN',
            'signatory' => 'Mangleesh Kumar Upadhyay', 'terms' => "Subscription fees are non-refundable except as required by law. GST is charged extra on plan prices at the applicable rate.\nBilling errors are corrected by credit note.",
            'bank_name' => '', 'bank_account_name' => '', 'bank_account_no' => '', 'bank_ifsc' => '', 'upi_id' => '', 'copy_to' => '', 'auto_email' => '1'];
    }

    public function settings(): array
    {
        $out = self::defaults();
        foreach (self::FIELDS as $f) { $v = PlatformSettings::get('inv_' . $f); if ($v !== null) { $out[$f] = $v; } }
        return $out;
    }

    /** @return list<string> problems (empty = valid) */
    public static function validate(array $d): array
    {
        $e = [];
        if (trim((string) ($d['legal_name'] ?? '')) === '') { $e[] = 'Legal name is required.'; }
        $gstin = strtoupper(trim((string) ($d['gstin'] ?? '')));
        if ($gstin === '') { $e[] = 'GSTIN is required to issue GST invoices.'; }
        elseif (! Gst::validGstin($gstin)) { $e[] = 'GSTIN is not valid: check every character (the last one is a check character).'; }
        else {
            if (! empty($d['state_code']) && Gst::stateOfGstin($gstin) !== Gst::code((string) $d['state_code'])) { $e[] = 'State does not match the first two digits of the GSTIN (' . Gst::stateOfGstin($gstin) . ').'; }
            if (! empty($d['pan']) && strtoupper(trim((string) $d['pan'])) !== Gst::panOfGstin($gstin)) { $e[] = 'PAN does not match the PAN inside the GSTIN (' . Gst::panOfGstin($gstin) . ').'; }
        }
        if (empty($d['state_code']) || ! isset(Gst::STATES[Gst::code((string) $d['state_code'])])) { $e[] = 'Choose the state of registration.'; }
        if (trim((string) ($d['address_line1'] ?? '')) === '') { $e[] = 'Address is required on a tax invoice.'; }
        if (! empty($d['pincode']) && ! preg_match('/^\d{6}$/', (string) $d['pincode'])) { $e[] = 'PIN code must be 6 digits.'; }
        if (! preg_match('/^\d{4,8}$/', (string) ($d['sac_code'] ?? ''))) { $e[] = 'SAC code must be 4-8 digits.'; }
        if (! preg_match('/^[A-Za-z0-9]{1,4}$/', (string) ($d['credit_prefix'] ?? ''))) { $e[] = 'Credit note number prefix is 1-4 letters/digits.'; }
        elseif (strtoupper((string) ($d['credit_prefix'] ?? '')) === strtoupper((string) ($d['prefix'] ?? ''))) { $e[] = 'The credit note prefix must differ from the invoice prefix (each document type has its own series).'; }
        $rate = (float) ($d['gst_rate'] ?? 0);
        if ($rate < 0 || $rate > 40) { $e[] = 'GST rate must be between 0 and 40.'; }
        if (! preg_match('/^[A-Za-z0-9]{1,4}$/', (string) ($d['prefix'] ?? ''))) { $e[] = 'Invoice number prefix is 1-4 letters/digits (GST limits an invoice number to 16 characters).'; }
        if (! empty($d['bank_ifsc']) && ! Gst::validIfsc((string) $d['bank_ifsc'])) { $e[] = 'IFSC is not valid.'; }
        if (! empty($d['upi_id']) && ! Gst::validUpi((string) $d['upi_id'])) { $e[] = 'UPI ID is not valid.'; }
        foreach (['email', 'copy_to'] as $k) { if (! empty($d[$k]) && ! filter_var($d[$k], FILTER_VALIDATE_EMAIL)) { $e[] = ($k === 'email' ? 'Contact e-mail' : 'Accountant e-mail') . ' is not valid.'; } }
        return $e;
    }

    public function saveSettings(array $in, int $adminId): array
    {
        $cur = $this->settings(); $d = $cur;
        foreach (self::FIELDS as $f) {
            if (! array_key_exists($f, $in)) { continue; }
            $v = is_string($in[$f]) ? trim($in[$f]) : (string) $in[$f];
            $d[$f] = in_array($f, ['gstin', 'pan'], true) ? strtoupper($v) : $v;
        }
        $d['state_code'] = $d['state_code'] !== '' ? Gst::code((string) $d['state_code']) : '';
        $d['auto_email'] = ! empty($in['auto_email']) && $in['auto_email'] !== '0' ? '1' : (array_key_exists('auto_email', $in) ? '0' : $cur['auto_email']);
        if ($errs = self::validate($d)) { throw new \InvalidArgumentException(implode(' ', $errs)); }
        foreach (self::FIELDS as $f) { PlatformSettings::set('inv_' . $f, (string) $d[$f], $adminId); }
        AuditLogger::log('admin.invoice.settings', 'tenant', null, null, ['gstin' => $d['gstin'], 'prefix' => $d['prefix'], 'sac' => $d['sac_code'], 'rate' => $d['gst_rate']], null, $adminId);
        return $this->settings();
    }

    /** @return array the seller block frozen onto an invoice */
    private function sellerSnapshot(array $s): array
    {
        $state = Gst::code((string) $s['state_code']);
        return ['legal_name' => $s['legal_name'], 'trade_name' => $s['trade_name'], 'gstin' => $s['gstin'], 'pan' => $s['pan'], 'address' => DocFormat::addressLines($s), 'state_code' => $state, 'state_name' => Gst::STATES[$state] ?? '',
            'phone' => $s['phone'], 'email' => $s['email'], 'website' => $s['website'], 'signatory' => $s['signatory'], 'terms' => $s['terms'],
            'bank' => array_filter(['name' => $s['bank_name'], 'account_name' => $s['bank_account_name'], 'account_no' => $s['bank_account_no'], 'ifsc' => $s['bank_ifsc'], 'upi' => $s['upi_id']], static fn ($v) => $v !== '' && $v !== null)];
    }

    // ================================================================================================================================
    // issuing
    // ================================================================================================================================

    /**
     * The hook used after a payment: issues the invoice and queues its e-mail for after the response. Never throws (a payment must not be undone by
     * paperwork): problems are logged and the admin page lists paid periods that still lack an invoice.
     */
    public static function onPaid(int $subscriptionId, string $source = 'online'): void
    {
        if (ENVIRONMENT === 'testing' && ! self::$enableInTests) { return; }
        try {
            $svc = new self();
            $inv = $svc->issueForSubscription($subscriptionId, $source);
            if ($inv !== null && $svc->settings()['auto_email'] === '1') { self::scheduleEmail((int) $inv['id']); }
        } catch (\Throwable $e) {
            log_message('critical', "PlatformInvoiceService: no invoice for subscription {$subscriptionId}: " . $e->getMessage());
        }
    }

    /** @return array|null the invoice row, or null when nothing is billable (amount 0) */
    public function issueForSubscription(int $subscriptionId, string $source = 'online'): ?array
    {
        $db = db_connect();
        $existing = $db->table('platform_invoices')->where('subscription_id', $subscriptionId)->get()->getRowArray();
        if ($existing) { return $existing; }
        $sub = $db->table('subscriptions')->where('id', $subscriptionId)->get()->getRowArray() ?: throw new \OutOfBoundsException('Payment not found.');
        $total = (int) $sub['amount_paise'];
        if ($total <= 0 || ! in_array($sub['status'], ['active', 'cancelled', 'downgraded'], true)) { return null; }   // complimentary, free, or never actually paid

        $s = $this->settings();
        if ($errs = self::validate($s)) { throw new \DomainException('Invoice settings are incomplete: ' . implode(' ', $errs)); }
        $seller = $this->sellerSnapshot($s);
        $buyer = $this->buyerSnapshot((int) $sub['tenant_id']);
        $sellerState = $seller['state_code'];
        $buyerState = $buyer['gstin'] !== '' ? Gst::stateOfGstin($buyer['gstin']) : ($buyer['state_code'] ?: null);
        $pos = Gst::placeOfSupply($buyerState, $sellerState);

        // GST charged ON TOP (the order recorded the split) or, for an older / offline-recorded payment, carved OUT of the amount received.
        $recorded = $sub['taxable_paise'] !== null && $sub['gst_paise'] !== null && (int) $sub['taxable_paise'] + (int) $sub['gst_paise'] === $total;
        if ($recorded) { $basis = 'exclusive'; $rate = (float) ($sub['gst_rate'] ?? $s['gst_rate']); $taxable = (int) $sub['taxable_paise']; $tax = (int) $sub['gst_paise']; }
        else { $basis = 'inclusive'; $rate = (float) $s['gst_rate']; $taxable = (int) round($total * 100 / (100 + $rate)); $tax = $total - $taxable; }
        $sp = Gst::split($tax, $sellerState, $pos);
        $cycle = $sub['billing_cycle'] === 'annual' ? 'annual' : 'monthly';
        $desc = sprintf('TripSarthi %s plan - %s subscription', ucfirst((string) $sub['plan']), $cycle);
        if (! empty($sub['current_period_start']) && ! empty($sub['current_period_end'])) { $desc .= sprintf(' (%s to %s)', DocFormat::date(substr((string) $sub['current_period_start'], 0, 10)), DocFormat::date(substr((string) $sub['current_period_end'], 0, 10))); }

        $date = $this->indiaDate(); $fy = Gst::fy($date); $series = strtoupper($s['prefix']);
        $paths = [];
        $db->transStart();
        try {
            $db->query('INSERT IGNORE INTO platform_doc_sequences (series, fy, last_seq) VALUES (?, ?, 0)', [$series, $fy]);
            $seq = (int) $db->query('SELECT last_seq FROM platform_doc_sequences WHERE series = ? AND fy = ? FOR UPDATE', [$series, $fy])->getRowArray()['last_seq'] + 1;
            $db->query('UPDATE platform_doc_sequences SET last_seq = ? WHERE series = ? AND fy = ?', [$seq, $series, $fy]);
            $number = Gst::invoiceNumber($series, $fy, $seq);
            $row = ['number' => $number, 'series' => $series, 'fy' => $fy, 'seq' => $seq, 'issue_date' => $date, 'tenant_id' => (int) $sub['tenant_id'], 'subscription_id' => $subscriptionId,
                'source' => $source === 'offline' ? 'offline' : 'online', 'payment_ref' => str_starts_with((string) $sub['razorpay_sub_id'], 'admin-') ? null : (string) $sub['razorpay_sub_id'],
                'plan' => (string) $sub['plan'], 'billing_cycle' => $cycle, 'period_start' => $sub['current_period_start'], 'period_end' => $sub['current_period_end'], 'description' => mb_substr($desc, 0, 300),
                'buyer_type' => $buyer['gstin'] !== '' ? 'b2b' : 'b2c', 'buyer_name' => mb_substr($buyer['name'], 0, 200), 'buyer_gstin' => $buyer['gstin'] !== '' ? $buyer['gstin'] : null, 'place_of_supply' => $pos, 'supply_type' => $sp['supply'],
                'price_basis' => $basis, 'sac' => $s['sac_code'], 'gst_rate' => $rate, 'taxable_value' => $taxable, 'cgst' => $sp['cgst'], 'sgst' => $sp['sgst'], 'igst' => $sp['igst'], 'total' => $total,
                'seller' => json_encode($seller, JSON_UNESCAPED_UNICODE), 'buyer' => json_encode($buyer, JSON_UNESCAPED_UNICODE), 'email_to' => $buyer['email'] ?: null, 'created_at' => $this->stamp()];
            $db->table('platform_invoices')->insert($row);
            $id = (int) $db->insertID();
            $bytes = $this->renderPdf($row + ['id' => $id]);
            $rel = $fy . '/' . str_replace('/', '-', $number) . '.pdf';
            $abs = $this->storageDir() . '/' . $rel;
            if (! is_dir(dirname($abs)) && ! mkdir(dirname($abs), 0775, true) && ! is_dir(dirname($abs))) { throw new \RuntimeException('Could not create the invoice folder.'); }
            if (file_put_contents($abs, $bytes) === false) { throw new \RuntimeException('Could not store the invoice PDF.'); }
            $paths[] = $abs;
            $db->table('platform_invoices')->where('id', $id)->update(['pdf_path' => $rel, 'pdf_sha256' => hash('sha256', $bytes)]);
            $db->transComplete();
            if (! $db->transStatus()) { throw new \RuntimeException('Could not save the invoice.'); }
        } catch (\Throwable $e) {
            $db->transRollback();
            foreach ($paths as $p) { @unlink($p); }
            // Two requests racing to invoice the same payment: the UNIQUE key makes the loser fail; hand back the winner's invoice.
            $won = $db->table('platform_invoices')->where('subscription_id', $subscriptionId)->get()->getRowArray();
            if ($won) { return $won; }
            throw $e;
        }
        return $db->table('platform_invoices')->where('id', $id)->get()->getRowArray();
    }

    /** The buyer block: their business profile when it has what an invoice needs, else just the workspace and owner. */
    private function buyerSnapshot(int $tenantId): array
    {
        $db = db_connect();
        $t = $db->table('tenants')->select('name')->where('id', $tenantId)->get()->getRowArray();
        $owner = $db->table('users')->select('name, email, phone')->where('tenant_id', $tenantId)->where('deleted_at', null)->orderBy("role = 'owner'", 'DESC', false)->orderBy('id', 'ASC')->limit(1)->get()->getRowArray() ?: [];
        $p = (new BusinessProfileService())->get($tenantId);
        $gstin = strtoupper(trim((string) ($p['gstin'] ?? ''))); $gstin = ($gstin !== '' && Gst::validGstin($gstin)) ? $gstin : '';
        $state = $gstin !== '' ? (string) Gst::stateOfGstin($gstin) : (! empty($p['state_code']) && isset(Gst::STATES[Gst::code((string) $p['state_code'])]) ? Gst::code((string) $p['state_code']) : '');
        $name = trim((string) ($p['legal_name'] ?? '')) ?: (trim((string) ($p['trade_name'] ?? '')) ?: (string) ($t['name'] ?? ('Workspace #' . $tenantId)));
        $addr = DocFormat::addressLines(['address_line1' => $p['address_line1'] ?? '', 'address_line2' => $p['address_line2'] ?? '', 'city' => $p['city'] ?? '', 'state_code' => $state, 'pincode' => $p['pincode'] ?? '']);
        $email = trim((string) ($owner['email'] ?? '')) ?: trim((string) ($p['email'] ?? ''));
        return ['name' => $name, 'gstin' => $gstin, 'state_code' => $state, 'state_name' => $state !== '' ? (Gst::STATES[$state] ?? '') : '', 'address' => $addr, 'email' => $email, 'phone' => trim((string) ($owner['phone'] ?? '')),
            'owner_name' => (string) ($owner['name'] ?? ''), 'billing_email' => trim((string) ($p['email'] ?? ''))];
    }

    // ================================================================================================================================
    // the PDF
    // ================================================================================================================================

    public function storageDir(): string
    {
        $d = (string) (env('PLATFORM_INVOICE_STORAGE_DIR') ?: '');
        return rtrim($d !== '' ? $d : WRITEPATH . 'uploads/platform-invoices', '/');
    }

    private function renderPdf(array $inv): string
    {
        $seller = json_decode((string) $inv['seller'], true); $buyer = json_decode((string) $inv['buyer'], true);
        $mark = is_file(APPPATH . 'Views/pdf/ts-mark.png') ? 'data:image/png;base64,' . base64_encode((string) file_get_contents(APPPATH . 'Views/pdf/ts-mark.png')) : null;
        $b = $seller['bank'] ?? []; $bank = [];
        foreach ([['Account name', $b['account_name'] ?? null], ['Bank', $b['name'] ?? null], ['Account no.', $b['account_no'] ?? null], ['IFSC', $b['ifsc'] ?? null], ['UPI ID', $b['upi'] ?? null]] as [$k, $v]) { if (! empty($v)) { $bank[] = [$k, $v]; } }
        $html = view('pdf/platform_invoice', ['mark' => $mark, 'seller' => $seller, 'buyer' => $buyer, 'bank' => $bank, 'doc' => [
            'number' => $inv['number'], 'date' => DocFormat::date($inv['issue_date']), 'place_name' => Gst::STATES[$inv['place_of_supply']] ?? '', 'place_of_supply' => $inv['place_of_supply'], 'supply_type' => $inv['supply_type'],
            'sac' => $inv['sac'], 'gst_rate' => (float) $inv['gst_rate'], 'description' => $inv['description'], 'taxable_value' => (int) $inv['taxable_value'], 'cgst' => (int) $inv['cgst'], 'sgst' => (int) $inv['sgst'], 'igst' => (int) $inv['igst'],
            'total' => (int) $inv['total'], 'words' => AmountInWords::paise((int) $inv['total']), 'payment_ref' => $inv['payment_ref'], 'source' => $inv['source'], 'plan' => ucfirst((string) $inv['plan']), 'b2b' => $inv['buyer_type'] === 'b2b', 'basis' => $inv['price_basis'] ?? 'inclusive']]);
        return PdfRenderer::render($html, $inv['number'] . ' · ' . $seller['legal_name'] . ' · GSTIN ' . $seller['gstin'], 'Tax invoice ' . $inv['number']);
    }

    /** The stored PDF bytes; refuses a missing or altered file. */
    public function pdf(int $invoiceId, ?int $onlyTenantId = null): array
    {
        $inv = db_connect()->table('platform_invoices')->where('id', $invoiceId)->get()->getRowArray();
        if (! $inv || ($onlyTenantId !== null && (int) $inv['tenant_id'] !== $onlyTenantId)) { throw new \OutOfBoundsException('Invoice not found.'); }
        $path = $this->storageDir() . '/' . $inv['pdf_path'];
        $bytes = $inv['pdf_path'] && is_file($path) ? (string) file_get_contents($path) : null;
        if ($bytes === null) { throw new \DomainException('The stored PDF for ' . $inv['number'] . ' is missing. Restore it from the backup of writable/uploads/platform-invoices.'); }
        if (! hash_equals((string) $inv['pdf_sha256'], hash('sha256', $bytes))) { throw new \DomainException('The stored PDF for ' . $inv['number'] . ' no longer matches what was issued, so it was not opened.'); }
        return ['bytes' => $bytes, 'filename' => str_replace('/', '-', (string) $inv['number']) . '.pdf', 'invoice' => $inv];
    }

    // ================================================================================================================================
    // e-mail
    // ================================================================================================================================

    public static function scheduleEmail(int $invoiceId): void
    {
        self::$queue[$invoiceId] = true;
        if (self::$shutdownHooked) { return; }
        self::$shutdownHooked = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            $svc = new self();
            foreach (array_keys(self::$queue) as $id) { try { $svc->email((int) $id); } catch (\Throwable $e) { log_message('error', 'Invoice e-mail failed: ' . $e->getMessage()); } }
            self::$queue = [];
        });
    }

    /** E-mails the invoice (PDF attached) to the workspace owner, plus the accountant copy when set. @return array{sent:bool,to:?string,error:?string} */
    public function email(int $invoiceId): array
    {
        $db = db_connect(); $f = $this->pdf($invoiceId); $inv = $f['invoice'];
        $buyer = json_decode((string) $inv['buyer'], true); $seller = json_decode((string) $inv['seller'], true);
        $to = trim((string) ($inv['email_to'] ?: ($buyer['email'] ?? '')));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $db->table('platform_invoices')->where('id', $invoiceId)->update(['email_attempts' => (int) $inv['email_attempts'] + 1, 'email_error' => 'This customer has no valid e-mail address.']);
            return ['sent' => false, 'to' => null, 'error' => 'This customer has no valid e-mail address.'];
        }
        $rupees = DocFormat::inr((int) $inv['total']);
        $blocks = [
            ['p', 'Thank you for your payment. Your GST tax invoice is attached to this e-mail as a PDF.'],
            ['facts', array_values(array_filter([['Invoice no.', $inv['number']], ['Date', DocFormat::date($inv['issue_date'])], ['Plan', ucfirst((string) $inv['plan']) . ' (' . $inv['billing_cycle'] . ')'],
                ['Amount paid', $rupees . ' (' . DocFormat::inr((int) $inv['taxable_value']) . ' + GST ' . DocFormat::inr((int) $inv['cgst'] + (int) $inv['sgst'] + (int) $inv['igst']) . ')'], $inv['payment_ref'] ? ['Payment reference', $inv['payment_ref']] : null, ['Billed to', $inv['buyer_name'] . ($inv['buyer_gstin'] ? ' · GSTIN ' . $inv['buyer_gstin'] : '')]]))],
        ];
        if ($inv['buyer_type'] === 'b2c') {
            $blocks[] = ['callout', 'This invoice has no GSTIN because none is saved in your workspace. If you are GST-registered and want to claim input credit, add your GSTIN under Settings > Business & invoicing before your next payment.', 'info'];
        }
        $blocks[] = ['p', 'Questions about this invoice? Reply to this e-mail or use Help & support inside TripSarthi.'];
        $m = EmailLayout::build(['subject' => "TripSarthi invoice {$inv['number']}", 'preheader' => "Your GST invoice {$inv['number']} for {$rupees} is attached.", 'eyebrow' => 'Tax invoice', 'title' => 'Your invoice is ready', 'blocks' => $blocks,
            'footer_note' => $seller['legal_name'] . ' · GSTIN ' . $seller['gstin']]);
        $attachments = [['filename' => $f['filename'], 'content' => $f['bytes'], 'mime' => 'application/pdf']];
        $bcc = trim((string) $this->settings()['copy_to']) ?: null;
        $ok = self::$mailer !== null ? (bool) (self::$mailer)($to, "TripSarthi invoice {$inv['number']} - {$rupees}", $m['html'], $m['text'], $attachments, $bcc)
            : EmailService::send($to, "TripSarthi invoice {$inv['number']} - {$rupees}", $m['html'], $m['text'], null, [], $attachments, $bcc);
        $err = $ok ? null : mb_substr((string) (EmailService::$lastError ?: 'The mail server refused the message.'), 0, 300);
        $db->table('platform_invoices')->where('id', $invoiceId)->update(['email_to' => $to, 'email_attempts' => (int) $inv['email_attempts'] + 1, 'emailed_at' => $ok ? $this->stamp() : $inv['emailed_at'], 'email_error' => $err]);
        return ['sent' => $ok, 'to' => $to, 'error' => $err];
    }

    // ================================================================================================================================
    // reading: lists, totals, GST summary, exports, missing invoices
    // ================================================================================================================================

    /** @return array{0:string,1:list<mixed>} WHERE clause + bindings for the admin filters */
    private function where(array $p): array
    {
        $w = ['1=1']; $b = [];
        $month = (string) ($p['month'] ?? ''); $from = (string) ($p['from'] ?? ''); $to = (string) ($p['to'] ?? ''); $fy = (string) ($p['fy'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $month)) { $from = $month . '-01'; $to = date('Y-m-t', strtotime($from)); }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w[] = 'i.issue_date >= ?'; $b[] = $from; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $w[] = 'i.issue_date <= ?'; $b[] = $to; }
        if (preg_match('/^\d{4}-\d{2}$/', $fy)) { $w[] = 'i.fy = ?'; $b[] = $fy; }
        $q = trim((string) ($p['q'] ?? ''));
        if ($q !== '') { $w[] = '(i.number LIKE ? OR i.buyer_name LIKE ? OR i.buyer_gstin LIKE ? OR i.email_to LIKE ? OR i.payment_ref LIKE ? OR i.tenant_id = ?)'; $like = '%' . $q . '%'; array_push($b, $like, $like, $like, $like, $like, ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : 0); }
        if (in_array($p['buyer_type'] ?? '', ['b2b', 'b2c'], true)) { $w[] = 'i.buyer_type = ?'; $b[] = $p['buyer_type']; }
        if (in_array($p['supply_type'] ?? '', ['intra', 'inter'], true)) { $w[] = 'i.supply_type = ?'; $b[] = $p['supply_type']; }
        if (! empty($p['plan'])) { $w[] = 'i.plan = ?'; $b[] = (string) $p['plan']; }
        if (in_array($p['source'] ?? '', ['online', 'offline'], true)) { $w[] = 'i.source = ?'; $b[] = $p['source']; }
        if (! empty($p['pos']) && preg_match('/^\d{2}$/', (string) $p['pos'])) { $w[] = 'i.place_of_supply = ?'; $b[] = $p['pos']; }
        if (! empty($p['tenant_id'])) { $w[] = 'i.tenant_id = ?'; $b[] = (int) $p['tenant_id']; }
        match ($p['email'] ?? '') { 'sent' => $w[] = 'i.emailed_at IS NOT NULL', 'failed' => $w[] = 'i.emailed_at IS NULL AND i.email_error IS NOT NULL', 'pending' => $w[] = 'i.emailed_at IS NULL', default => null };
        if (isset($p['min']) && $p['min'] !== '') { $w[] = 'i.total >= ?'; $b[] = (int) round((float) $p['min'] * 100); }
        if (isset($p['max']) && $p['max'] !== '') { $w[] = 'i.total <= ?'; $b[] = (int) round((float) $p['max'] * 100); }
        return [implode(' AND ', $w), $b];
    }

    /** @return array{rows:list<array>,total:int,page:int,per_page:int,totals:array} */
    public function list(array $p = []): array
    {
        $db = db_connect(); [$where, $b] = $this->where($p);
        $per = max(5, min(1000, (int) ($p['per_page'] ?? 25))); $page = max(1, (int) ($p['page'] ?? 1));
        $order = match ($p['sort'] ?? 'newest') { 'oldest' => 'i.issue_date ASC, i.id ASC', 'amount' => 'i.total DESC, i.id DESC', 'customer' => 'i.buyer_name ASC, i.id DESC', default => 'i.issue_date DESC, i.id DESC' };
        $t = $db->query("SELECT COUNT(*) n, COALESCE(SUM(i.taxable_value),0) taxable, COALESCE(SUM(i.cgst),0) cgst, COALESCE(SUM(i.sgst),0) sgst, COALESCE(SUM(i.igst),0) igst, COALESCE(SUM(i.total),0) total FROM platform_invoices i WHERE {$where}", $b)->getRowArray();
        $rows = $db->query("SELECT i.id, i.number, i.issue_date, i.fy, i.tenant_id, i.source, i.payment_ref, i.plan, i.billing_cycle, i.period_start, i.period_end, i.description, i.buyer_type, i.buyer_name, i.buyer_gstin, i.place_of_supply, i.supply_type, i.sac, i.gst_rate,
                i.taxable_value, i.cgst, i.sgst, i.igst, i.total, i.email_to, i.emailed_at, i.email_attempts, i.email_error,
                (SELECT COALESCE(SUM(c.total), 0) FROM platform_credit_notes c WHERE c.invoice_id = i.id) credited FROM platform_invoices i WHERE {$where} ORDER BY {$order} LIMIT {$per} OFFSET " . (($page - 1) * $per), $b)->getResultArray();
        foreach ($rows as &$r) { foreach (['id', 'tenant_id', 'taxable_value', 'cgst', 'sgst', 'igst', 'total', 'email_attempts', 'credited'] as $k) { $r[$k] = (int) $r[$k]; } $r['gst_rate'] = (float) $r['gst_rate']; $r['place_name'] = Gst::STATES[$r['place_of_supply']] ?? ''; $r['gst'] = $r['cgst'] + $r['sgst'] + $r['igst']; } unset($r);
        return ['rows' => $rows, 'total' => (int) $t['n'], 'page' => $page, 'per_page' => $per,
            'totals' => ['count' => (int) $t['n'], 'taxable' => (int) $t['taxable'], 'cgst' => (int) $t['cgst'], 'sgst' => (int) $t['sgst'], 'igst' => (int) $t['igst'], 'gst' => (int) $t['cgst'] + (int) $t['sgst'] + (int) $t['igst'], 'total' => (int) $t['total']]];
    }

    /** GST summary for the filtered invoices: B2B by GSTIN, B2C by place of supply, by plan and by supply type - what the accountant needs for GSTR-1. */
    public function summary(array $p = []): array
    {
        $db = db_connect(); [$where, $b] = $this->where($p);
        $agg = 'COUNT(*) n, SUM(i.taxable_value) taxable, SUM(i.cgst) cgst, SUM(i.sgst) sgst, SUM(i.igst) igst, SUM(i.total) total';
        $fix = static function (array $rows): array { foreach ($rows as &$r) { foreach (['n', 'taxable', 'cgst', 'sgst', 'igst', 'total'] as $k) { $r[$k] = (int) $r[$k]; } $r['gst'] = $r['cgst'] + $r['sgst'] + $r['igst']; } return $rows; };
        $b2b = $fix($db->query("SELECT i.buyer_gstin gstin, MAX(i.buyer_name) name, {$agg} FROM platform_invoices i WHERE {$where} AND i.buyer_type = 'b2b' GROUP BY i.buyer_gstin ORDER BY total DESC", $b)->getResultArray());
        $b2c = $fix($db->query("SELECT i.place_of_supply pos, i.gst_rate rate, {$agg} FROM platform_invoices i WHERE {$where} AND i.buyer_type = 'b2c' GROUP BY i.place_of_supply, i.gst_rate ORDER BY total DESC", $b)->getResultArray());
        foreach ($b2c as &$r) { $r['place_name'] = Gst::STATES[$r['pos']] ?? ''; $r['rate'] = (float) $r['rate']; } unset($r);
        $plan = $fix($db->query("SELECT i.plan, i.billing_cycle cycle, {$agg} FROM platform_invoices i WHERE {$where} GROUP BY i.plan, i.billing_cycle ORDER BY total DESC", $b)->getResultArray());
        $supply = $fix($db->query("SELECT i.supply_type supply, {$agg} FROM platform_invoices i WHERE {$where} GROUP BY i.supply_type", $b)->getResultArray());
        $month = $fix($db->query("SELECT DATE_FORMAT(i.issue_date, '%Y-%m') month, {$agg} FROM platform_invoices i WHERE {$where} GROUP BY month ORDER BY month", $b)->getResultArray());
        $tot = $this->list($p + ['per_page' => 5])['totals']; $cn = (new PlatformCreditNoteService($this->now))->summary($p); $ct = $cn['totals'];
        $net = []; foreach (['count', 'taxable', 'cgst', 'sgst', 'igst', 'gst', 'total'] as $k) { $net[$k] = $k === 'count' ? $tot[$k] : $tot[$k] - $ct[$k]; }   // GST payable = invoices minus credit notes
        return ['totals' => $tot, 'credit_notes' => $ct, 'net' => $net, 'cn_b2b' => $cn['b2b'], 'cn_b2c' => $cn['b2c'], 'b2b' => $b2b, 'b2c' => $b2c, 'by_plan' => $plan, 'by_supply' => $supply, 'by_month' => $month];
    }

    /** Sales register as CSV (UTF-8 with BOM for Excel). Text that could be read as a spreadsheet formula is neutralised. */
    public function csv(array $p = []): string
    {
        $rows = $this->list($p + ['per_page' => 1000, 'sort' => 'oldest'] )['rows'];
        $cell = static function ($v): string { $t = (string) $v; if ($t !== '' && in_array($t[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($t)) { $t = "'" . $t; } return '"' . str_replace('"', '""', $t) . '"'; };
        $r2 = static fn (int $paise): string => number_format($paise / 100, 2, '.', '');
        $out = [implode(',', array_map($cell, ['Document', 'Invoice no', 'Date', 'FY', 'Customer', 'Customer GSTIN', 'Type', 'Place of supply', 'Supply', 'Plan', 'Cycle', 'Period from', 'Period to', 'SAC', 'GST rate %',
            'Taxable value', 'CGST', 'SGST', 'IGST', 'Total GST', 'Invoice total', 'Payment', 'Payment reference', 'Customer e-mail', 'E-mailed', 'Workspace id', 'Against invoice', 'Reason']))];
        foreach ($rows as $r) {
            $out[] = implode(',', array_map($cell, ['Tax invoice', $r['number'], $r['issue_date'], $r['fy'], $r['buyer_name'], $r['buyer_gstin'] ?? '', strtoupper($r['buyer_type']), $r['place_name'] . ' (' . $r['place_of_supply'] . ')', $r['supply_type'] === 'intra' ? 'Intra-state' : 'Inter-state',
                ucfirst((string) $r['plan']), $r['billing_cycle'], substr((string) $r['period_start'], 0, 10), substr((string) $r['period_end'], 0, 10), $r['sac'], $r['gst_rate'], $r2($r['taxable_value']), $r2($r['cgst']), $r2($r['sgst']), $r2($r['igst']), $r2($r['gst']), $r2($r['total']),
                $r['source'] === 'offline' ? 'Offline (recorded by hand)' : 'Online (Razorpay)', $r['payment_ref'] ?? '', $r['email_to'] ?? '', $r['emailed_at'] ? 'Yes ' . $r['emailed_at'] : ($r['email_error'] ? 'Failed' : 'No'), $r['tenant_id'], '', '']));
        }
        // Credit notes follow the invoices with NEGATIVE amounts, so the columns still add up to the net.
        foreach ((new PlatformCreditNoteService($this->now))->list($p + ['per_page' => 1000, 'sort' => 'oldest'])['rows'] as $c) {
            $neg = static fn (int $paise): string => number_format(-$paise / 100, 2, '.', '');
            $out[] = implode(',', array_map($cell, ['Credit note', $c['number'], $c['issue_date'], $c['fy'], $c['buyer_name'], $c['buyer_gstin'] ?? '', strtoupper($c['buyer_type']), $c['place_name'] . ' (' . $c['place_of_supply'] . ')', $c['supply_type'] === 'intra' ? 'Intra-state' : 'Inter-state',
                ucfirst((string) $c['plan']), '', '', '', $c['sac'], $c['gst_rate'], $neg($c['taxable_value']), $neg($c['cgst']), $neg($c['sgst']), $neg($c['igst']), $neg($c['gst']), $neg($c['total']),
                'Credit note', $c['refund_ref'] ?? '', $c['email_to'] ?? '', $c['emailed_at'] ? 'Yes ' . $c['emailed_at'] : ($c['email_error'] ? 'Failed' : 'No'), $c['tenant_id'], $c['invoice_number'], $c['reason']]));
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $out) . "\r\n";
    }

    /** A ZIP of the filtered invoices' PDFs (each checked against its stored hash). @return array{path:string,count:int,skipped:list<string>} a temp file the caller streams and deletes */
    public function zip(array $p = []): array
    {
        $rows = $this->list($p + ['per_page' => 1000, 'sort' => 'oldest'])['rows'];
        $path = tempnam(sys_get_temp_dir(), 'tsinv') ?: throw new \RuntimeException('No temporary space for the ZIP.');
        $z = new \ZipArchive();
        if ($z->open($path, \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Could not create the ZIP.'); }
        $n = 0; $skipped = [];
        foreach ($rows as $r) {
            try { $f = $this->pdf((int) $r['id']); $z->addFromString($f['filename'], $f['bytes']); $n++; }
            catch (\Throwable) { $skipped[] = $r['number']; }
        }
        $cns = new PlatformCreditNoteService($this->now);
        foreach ($cns->list($p + ['per_page' => 1000, 'sort' => 'oldest'])['rows'] as $c) {
            try { $f = $cns->pdf((int) $c['id']); $z->addFromString($f['filename'], $f['bytes']); $n++; } catch (\Throwable) { $skipped[] = $c['number']; }
        }
        $z->close();
        return ['path' => $path, 'count' => $n, 'skipped' => $skipped];
    }

    /** Paid periods that have no invoice yet (an invoice failed to issue, or the payment predates this feature). @return list<array> */
    public function missing(): array
    {
        $rows = db_connect()->query("SELECT s.id, s.tenant_id, t.name tenant_name, s.plan, s.billing_cycle, s.amount_paise, s.razorpay_sub_id, s.current_period_start, s.created_at
            FROM subscriptions s JOIN tenants t ON t.id = s.tenant_id LEFT JOIN platform_invoices i ON i.subscription_id = s.id
            WHERE i.id IS NULL AND s.amount_paise > 0 AND s.status IN ('active','cancelled','downgraded') ORDER BY s.id")->getResultArray();
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['tenant_id'] = (int) $r['tenant_id']; $r['amount_paise'] = (int) $r['amount_paise']; $r['offline'] = str_starts_with((string) $r['razorpay_sub_id'], 'admin-'); unset($r['razorpay_sub_id']); } unset($r);
        return $rows;
    }

    /** Issues invoices for the given paid periods (admin button). Each is dated TODAY (a gap-free series cannot be back-dated). @return array{issued:int,failed:list<string>} */
    public function issueMissing(array $subscriptionIds, int $adminId): array
    {
        $issued = 0; $failed = [];
        foreach ($this->missing() as $m) {
            if (! in_array($m['id'], array_map('intval', $subscriptionIds), true)) { continue; }
            try {
                $inv = $this->issueForSubscription($m['id'], $m['offline'] ? 'offline' : 'online');
                if ($inv) { $issued++; if ($this->settings()['auto_email'] === '1') { $this->email((int) $inv['id']); } AuditLogger::log('admin.invoice.issue', 'tenant', $m['tenant_id'], null, ['invoice' => $inv['number']], $m['tenant_id'], $adminId); }
            } catch (\Throwable $e) { $failed[] = '#' . $m['id'] . ': ' . $e->getMessage(); }
        }
        return ['issued' => $issued, 'failed' => $failed];
    }

    /** A workspace's own invoices (customer app). */
    public function forTenant(int $tenantId): array
    {
        $rows = db_connect()->query('SELECT id, number, issue_date, plan, billing_cycle, description, total, buyer_gstin FROM platform_invoices WHERE tenant_id = ? ORDER BY issue_date DESC, id DESC', [$tenantId])->getResultArray();
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['total'] = (int) $r['total']; } unset($r);
        return $rows;
    }
}
