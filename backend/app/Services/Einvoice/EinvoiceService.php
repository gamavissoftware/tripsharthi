<?php

declare(strict_types=1);

namespace App\Services\Einvoice;

use App\Models\InvoiceModel;
use App\Services\Billing\Docs\Gst;
use App\Services\Crm\AuditLogger;
use App\Services\WhatsApp\TokenCipher;

/**
 * E-invoicing orchestration.
 *
 *  - Applicability: the tenant switched it on AND confirmed their turnover requires it, the document is a tax invoice or credit note, and the
 *    buyer is B2B (valid GSTIN). B2C, bills of supply and receipts are never e-invoiced.
 *  - `prepare()` runs INSIDE InvoiceService's numbering transaction, between "number assigned" and "PDF rendered": validate -> register with the IRP
 *    -> the IRN/QR go onto the PDF and the invoice row. If anything fails the transaction rolls back, so NO number is consumed and NO invoice exists
 *    (an invoice to a B2B customer without an IRN is not a valid tax invoice). If the IRP registered it but we then failed to store it, the retry gets
 *    the same number, the IRP answers "duplicate" (2150) and we adopt the existing IRN instead of failing.
 *  - A simulated (demo) IRN is only possible when EINVOICE_MOCK_MODE is on; it can never reach a production invoice.
 *  - Cancel: only within 24 hours of the acknowledgement (the IRP's rule), only if no credit note exists against it; afterwards use a credit note.
 */
final class EinvoiceService
{
    public const CANCEL_WINDOW_SECONDS = 86400;
    public const REASONS = [1 => 'Duplicate', 2 => 'Data entry mistake', 3 => 'Order cancelled', 4 => 'Other'];

    /** Test seam: lets a test inject a client for code paths that build the service themselves (InvoiceService). Honoured ONLY under PHPUnit. */
    public static mixed $testFactory = null;

    public function __construct(private readonly mixed $clientFactory = null, private readonly ?int $now = null) {}
    private function ts(): int { return $this->now ?? time(); }
    private function stamp(): string { return date('Y-m-d H:i:s', $this->ts()); }

    // ---- settings ------------------------------------------------------------------------------------------------------

    private function row(int $tenantId): ?array { return db_connect()->table('einvoice_settings')->where('tenant_id', $tenantId)->get()->getRowArray() ?: null; }

    private function config(?array $row): array
    {
        if (! $row || empty($row['config_enc'])) { return []; }
        try { return json_decode(TokenCipher::decrypt((string) $row['config_enc']), true) ?: []; } catch (\Throwable) { return []; }
    }

    /** Safe view for the UI: never any secret. */
    public function settings(int $tenantId): array
    {
        $r = $this->row($tenantId); $c = $this->config($r);
        $a = (array) ($c['auth'] ?? []);
        return ['enabled' => (bool) ($r['enabled'] ?? false), 'mode' => $r['mode'] ?? 'gsp', 'aato_confirmed' => (bool) ($r['aato_confirmed'] ?? false), 'enabled_at' => $r['enabled_at'] ?? null,
            'demo_available' => MockIrpClient::enabled(),
            'config' => ['base_url' => $c['base_url'] ?? '', 'generate_path' => $c['generate_path'] ?? '/einvoice/generate', 'cancel_path' => $c['cancel_path'] ?? '/einvoice/cancel', 'find_path' => $c['find_path'] ?? '/einvoice/irn',
                'auth_type' => $a['type'] ?? 'headers', 'header_names' => array_keys((array) ($a['headers'] ?? [])), 'has_secret' => ! empty($a['token']) || ! empty($a['password']) || ! empty($a['headers']), 'username' => $a['username'] ?? '']];
    }

    /**
     * @param array $in enabled, mode, aato_confirmed, base_url, generate_path, cancel_path, find_path, auth_type, headers{name:value}, token, username, password
     *   A blank secret keeps the stored one (secrets are never sent back to the browser).
     */
    public function save(int $tenantId, array $in, ?int $userId = null): array
    {
        $cur = $this->row($tenantId); $old = $this->config($cur);
        $enabled = ! empty($in['enabled']); $mode = ($in['mode'] ?? 'gsp') === 'demo' ? 'demo' : 'gsp';
        if ($mode === 'demo' && ! MockIrpClient::enabled()) { throw new \InvalidArgumentException('Demo mode is only for local testing and is switched off on this server. Use your e-invoice provider (GSP).'); }
        $cfg = $old;
        if ($mode === 'gsp') {
            $base = trim((string) ($in['base_url'] ?? $old['base_url'] ?? ''));
            if ($base !== '' && ! preg_match('#^https://[^\s/]+#', $base)) { throw new \InvalidArgumentException('The provider address must start with https://.'); }
            $auth = (array) ($old['auth'] ?? []);
            $type = in_array($in['auth_type'] ?? ($auth['type'] ?? 'headers'), ['headers', 'bearer', 'basic'], true) ? ($in['auth_type'] ?? ($auth['type'] ?? 'headers')) : 'headers';
            $auth['type'] = $type;
            foreach (['token', 'password'] as $k) { if (isset($in[$k]) && trim((string) $in[$k]) !== '') { $auth[$k] = trim((string) $in[$k]); } }
            if (isset($in['username'])) { $auth['username'] = trim((string) $in['username']); }
            if (isset($in['headers']) && is_array($in['headers'])) {
                $h = (array) ($auth['headers'] ?? []);
                foreach ($in['headers'] as $name => $val) {
                    $name = trim((string) $name);
                    if ($name === '' || ! preg_match('/^[A-Za-z0-9\-_]{1,60}$/', $name)) { continue; }
                    if (is_string($val) && trim($val) !== '') { $h[$name] = trim($val); } elseif (! array_key_exists($name, $h)) { $h[$name] = ''; }
                }
                foreach (array_keys($h) as $name) { if (! array_key_exists($name, $in['headers'])) { unset($h[$name]); } }
                $auth['headers'] = $h;
            }
            $cfg = ['base_url' => $base, 'generate_path' => $this->path($in['generate_path'] ?? $old['generate_path'] ?? '/einvoice/generate'), 'cancel_path' => $this->path($in['cancel_path'] ?? $old['cancel_path'] ?? '/einvoice/cancel'),
                'find_path' => $this->path($in['find_path'] ?? $old['find_path'] ?? '/einvoice/irn'), 'auth' => $auth];
        }
        $aato = ! empty($in['aato_confirmed']);
        if ($enabled) {
            if (! $aato) { throw new \InvalidArgumentException('Confirm that your aggregate turnover requires e-invoicing (above ₹5 crore). Switching it on for a business that does not need it is not recommended.'); }
            if ($mode === 'gsp' && (empty($cfg['base_url']))) { throw new \InvalidArgumentException('Enter your e-invoice provider\'s address first.'); }
        }
        $row = ['enabled' => $enabled ? 1 : 0, 'mode' => $mode, 'aato_confirmed' => $aato ? 1 : 0, 'config_enc' => $mode === 'gsp' && $cfg ? TokenCipher::encrypt((string) json_encode($cfg)) : ($cur['config_enc'] ?? null),
            'updated_by' => $userId, 'updated_at' => $this->stamp()] + ($enabled && empty($cur['enabled']) ? ['enabled_at' => $this->stamp()] : []);
        $db = db_connect();
        $cur ? $db->table('einvoice_settings')->where('tenant_id', $tenantId)->update($row) : $db->table('einvoice_settings')->insert($row + ['tenant_id' => $tenantId, 'created_at' => $this->stamp()]);
        AuditLogger::log('einvoice.settings', 'einvoice_settings', null, ['enabled' => (bool) ($cur['enabled'] ?? false)], ['enabled' => $enabled, 'mode' => $mode], $tenantId, $userId);
        return $this->settings($tenantId);
    }

    private function path(mixed $p): string { $p = '/' . ltrim(trim((string) $p), '/'); return preg_match('#^/[A-Za-z0-9/_\-.]*$#', $p) ? $p : throw new \InvalidArgumentException('An endpoint path may only contain letters, digits and / _ - .'); }

    public function isEnabled(int $tenantId): bool { $r = $this->row($tenantId); return $r && (int) $r['enabled'] === 1 && (int) $r['aato_confirmed'] === 1; }

    private function client(int $tenantId): IrpClient
    {
        if (is_callable($this->clientFactory)) { return ($this->clientFactory)($tenantId); }
        if (is_callable(self::$testFactory) && ENVIRONMENT === 'testing') { return (self::$testFactory)($tenantId); }
        $r = $this->row($tenantId);
        if (($r['mode'] ?? 'gsp') === 'demo') {
            if (! MockIrpClient::enabled()) { throw new EinvoiceException('Demo e-invoicing is switched off on this server.', EinvoiceException::INVALID); }
            return new MockIrpClient($tenantId, $this->now);
        }
        return new GspIrpClient($this->config($r));
    }

    /** Applicability of e-invoicing to ONE document. */
    public function applies(int $tenantId, string $docType, array $buyer): bool
    {
        return $this->isEnabled($tenantId) && in_array($docType, ['tax_invoice', 'credit_note'], true) && EinvoiceBuilder::isB2b($buyer);
    }

    /** Reachability + completeness check for the Settings screen. @return array{ok:bool,message:string} */
    public function test(int $tenantId): array
    {
        $r = $this->row($tenantId);
        if (($r['mode'] ?? 'gsp') === 'demo') { return MockIrpClient::enabled() ? ['ok' => true, 'message' => 'Demo mode: invoices get SIMULATED IRNs that are not valid for tax purposes.'] : ['ok' => false, 'message' => 'Demo mode is off on this server.']; }
        $c = $this->config($r);
        if (empty($c['base_url'])) { return ['ok' => false, 'message' => 'Enter your provider\'s address first.']; }
        try {
            $res = ([\App\Services\Travel\ConversionFeedbackService::class, 'defaultHttp'])('GET', $c['base_url'], ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
        } catch (\Throwable $e) { return ['ok' => false, 'message' => 'Could not reach ' . $c['base_url'] . ': ' . $e->getMessage()]; }
        return $res['status'] < 500 ? ['ok' => true, 'message' => 'The provider address is reachable (HTTP ' . $res['status'] . '). This does not prove your credentials — issue a test invoice in the provider\'s SANDBOX first.'] : ['ok' => false, 'message' => 'The provider answered with an error (HTTP ' . $res['status'] . ').'];
    }

    // ---- issuing ---------------------------------------------------------------------------------------------------------

    /**
     * Register a document that is being issued. Returns null when e-invoicing does not apply.
     * @param array $doc the invoice fields being inserted (number + issue_date already assigned)
     * @param array|null $original for credit notes: the original invoice row
     * @return array{irn:string,ack_no:string,ack_dt:string,signed_qr:string}|null
     * @throws EinvoiceException
     */
    public function prepare(int $tenantId, array $doc, array $profile, ?array $original = null): ?array
    {
        if (! $this->applies($tenantId, (string) $doc['doc_type'], (array) $doc['buyer'])) { return null; }
        $payload = EinvoiceBuilder::build($doc, $profile, $original);
        if ($problems = EinvoiceValidator::check($payload, date('Y-m-d', $this->ts()))) {
            throw new EinvoiceException("This invoice cannot be e-invoiced yet:\n• " . implode("\n• ", $problems), EinvoiceException::INVALID);
        }
        $client = $this->client($tenantId);
        try {
            return $client->generate($payload);
        } catch (EinvoiceException $e) {
            if ($e->kind === EinvoiceException::DUPLICATE) {
                $existing = $e->extra['existing'] ?? $client->findByDocument($payload['DocDtls']['Typ'], $payload['DocDtls']['No'], $payload['DocDtls']['Dt']);
                if ($existing && $existing['irn'] !== '') { return $existing; }         // we registered it before but never stored it: adopt that IRN
            }
            throw $e;
        }
    }

    // ---- cancelling --------------------------------------------------------------------------------------------------------

    /** Cancel the IRN of an invoice (24-hour window). The invoice becomes cancelled; re-invoice the booking to issue a fresh one. */
    public function cancel(int $tenantId, int $invoiceId, int $reasonCode, string $remark, ?int $userId = null): array
    {
        $inv = (new InvoiceModel())->setTenant($tenantId)->find($invoiceId);
        if (! $inv) { throw new \InvalidArgumentException('Document not found.'); }
        if (($inv['einvoice_status'] ?? 'none') !== 'generated' || empty($inv['einvoice_irn'])) { throw new \DomainException('This document has no active e-invoice to cancel.'); }
        if (! isset(self::REASONS[$reasonCode])) { throw new \InvalidArgumentException('Choose a cancellation reason.'); }
        if (trim($remark) === '') { throw new \InvalidArgumentException('Add a short remark explaining the cancellation.'); }
        if ($this->ts() - strtotime((string) $inv['einvoice_ack_dt']) > self::CANCEL_WINDOW_SECONDS) {
            throw new EinvoiceException('The 24-hour cancellation window for this e-invoice has passed. Issue a credit note instead (or cancel it on the GST e-invoice portal).', EinvoiceException::WINDOW);
        }
        $credited = (int) db_connect()->table('invoices')->where('tenant_id', $tenantId)->where('related_id', $invoiceId)->where('doc_type', 'credit_note')->where('status', 'issued')->countAllResults();
        if ($credited > 0) { throw new \DomainException('A credit note has been issued against this invoice, so its e-invoice can no longer be cancelled.'); }
        $this->client($tenantId)->cancel((string) $inv['einvoice_irn'], $reasonCode, $remark);
        (new InvoiceModel())->setTenant($tenantId)->update($invoiceId, ['status' => 'cancelled', 'einvoice_status' => 'cancelled', 'einvoice_cancelled_at' => $this->stamp(), 'einvoice_cancel_reason' => mb_substr(self::REASONS[$reasonCode] . ': ' . trim($remark), 0, 255)]);
        AuditLogger::log('einvoice.cancel', 'invoice', $invoiceId, null, ['irn' => $inv['einvoice_irn'], 'reason' => $reasonCode], $tenantId, $userId);
        return (array) (new InvoiceModel())->setTenant($tenantId)->find($invoiceId);
    }

    /** How many hours are left to cancel (null = not cancellable via the IRP any more). */
    public function cancelHoursLeft(array $inv): ?float
    {
        if (($inv['einvoice_status'] ?? 'none') !== 'generated' || empty($inv['einvoice_ack_dt'])) { return null; }
        $left = self::CANCEL_WINDOW_SECONDS - ($this->ts() - strtotime((string) $inv['einvoice_ack_dt']));
        return $left > 0 ? round($left / 3600, 1) : null;
    }

    /** B2B tax invoices issued while e-invoicing is on that carry no IRN — a compliance gap to fix. */
    public function missingIrn(int $tenantId, string $from, string $to): int
    {
        $s = $this->row($tenantId);
        if (! $s || (int) $s['enabled'] !== 1) { return 0; }
        $n = 0;
        foreach (db_connect()->table('invoices')->select('buyer')->where('tenant_id', $tenantId)->where('doc_type', 'tax_invoice')->where('status', 'issued')->where('einvoice_status', 'none')
            ->where('issue_date >=', max($from, substr((string) $s['enabled_at'], 0, 10) ?: $from))->where('issue_date <=', $to)->get()->getResultArray() as $r) {
            if (EinvoiceBuilder::isB2b((array) json_decode((string) $r['buyer'], true))) { $n++; }
        }
        return $n;
    }
}
