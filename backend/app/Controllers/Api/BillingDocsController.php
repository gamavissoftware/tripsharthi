<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\BookingModel;
use App\Models\InvoiceModel;
use App\Services\Auth\CurrentUser;
use App\Services\Billing\Docs\BusinessProfileService;
use App\Services\Billing\Docs\DocumentDeliveryService;
use App\Services\Billing\Docs\Gst;
use App\Services\Billing\Docs\InvoiceService;
use App\Services\Billing\Docs\QuoteDocument;

/** Business profile, quote PDFs, GST invoices, credit notes and receipts. */
class BillingDocsController extends TravelBaseController
{
    private function pdf(string $bytes, string $filename)
    {
        return $this->response->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) . '"')
            ->setHeader('Cache-Control', 'private, max-age=0, must-revalidate')->setHeader('X-Content-Type-Options', 'nosniff')->setBody($bytes);
    }

    private function fail422(string $m, string $kind = 'invalid')
    {
        return $this->respond(['success' => false, 'message' => $m, 'kind' => $kind], 422);
    }

    /** Never 401: the SPA signs staff out on 401, and a provider's credential error is not THEIR session. */
    private function einvoiceFail(\App\Services\Einvoice\EinvoiceException $e)
    {
        $http = in_array($e->kind, ['transient', 'auth'], true) ? 502 : 422;
        return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'einvoice_' . $e->kind, 'code' => $e->irpCode], $http);
    }

    private function guard(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\App\Services\Einvoice\EinvoiceException $e) { return $this->einvoiceFail($e); }
        catch (\InvalidArgumentException $e) { return $this->fail422($e->getMessage()); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
        catch (\RuntimeException $e) { log_message('error', 'billing docs: ' . $e->getMessage()); return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'error'], 500); }
    }

    // ---- profile ---------------------------------------------------------------------------------------------

    public function profile()
    {
        $svc = new BusinessProfileService();
        $p = $svc->get(CurrentUser::tenantId());
        return $this->ok(['profile' => $p, 'states' => Gst::states(), 'missing_for_invoice' => $svc->missingFor($p, 'tax_invoice'), 'issues_bill_of_supply' => empty($p['gstin'])]);
    }

    public function saveProfile()
    {
        return $this->guard(fn () => (new BusinessProfileService())->save(CurrentUser::tenantId(), $this->body()));
    }

    // ---- quote -----------------------------------------------------------------------------------------------

    public function quotePdf($id = null)
    {
        try {
            $r = (new QuoteDocument())->render(CurrentUser::tenantId(), (int) $id);
        } catch (\InvalidArgumentException $e) { return $this->failNotFound($e->getMessage()); }
        return $this->pdf($r['pdf'], $r['filename']);
    }

    // ---- invoices ---------------------------------------------------------------------------------------------

    /** GET /bookings/:id/documents — everything issued for a booking + what is needed to issue. */
    public function forBooking($id = null)
    {
        $tid = CurrentUser::tenantId();
        $b = (new BookingModel())->setTenant($tid)->find((int) $id);
        if (! $b) { return $this->failNotFound('Booking not found.'); }
        $rows = (new InvoiceModel())->setTenant($tid)->where('booking_id', (int) $id)->orderBy('id', 'DESC')->findAll();
        $svc = new InvoiceService();
        $profile = (new BusinessProfileService())->get($tid);
        $contact = $b['contact_id'] ? (new \App\Models\ContactModel())->setTenant($tid)->find((int) $b['contact_id']) : null;
        return $this->ok([
            'documents' => array_map(fn ($r) => $this->summary($r), $rows),
            'active_invoice' => ($a = $svc->activeInvoice($tid, (int) $id)) ? $this->summary($a) : null,
            'can_issue' => empty((new BusinessProfileService())->missingFor($profile, empty($profile['gstin']) ? 'bill_of_supply' : 'tax_invoice')),
            'missing' => (new BusinessProfileService())->missingFor($profile, empty($profile['gstin']) ? 'bill_of_supply' : 'tax_invoice'),
            'document_type' => empty($profile['gstin']) ? 'bill_of_supply' : 'tax_invoice',
            'deliveries' => (new DocumentDeliveryService())->forBooking($tid, (int) $id),
            'whatsapp_template' => (new DocumentDeliveryService())->templateStatus($tid),
            'einvoice_enabled' => (new \App\Services\Einvoice\EinvoiceService())->isEnabled($tid),
            'buyer_defaults' => $contact ? ['name' => $contact['name'], 'city' => $contact['city'], 'state' => $contact['state'], 'email' => $contact['email'], 'phone' => $contact['wa_number']] : new \stdClass(),
        ]);
    }

    private function summary(array $r): array
    {
        $buyer = json_decode((string) $r['buyer'], true) ?: [];
        return ['id' => (int) $r['id'], 'doc_type' => $r['doc_type'], 'number' => $r['number'], 'issue_date' => $r['issue_date'], 'total' => (int) $r['total'], 'taxable_value' => (int) $r['taxable_value'],
            'status' => $r['status'], 'related_id' => $r['related_id'], 'payment_id' => $r['payment_id'], 'buyer' => $buyer['name'] ?? '', 'share_token' => $r['share_token'], 'reason' => $r['reason'],
            'einvoice' => ($r['einvoice_status'] ?? 'none') === 'none' ? null : ['status' => $r['einvoice_status'], 'irn' => $r['einvoice_irn'], 'ack_no' => $r['einvoice_ack_no'], 'ack_dt' => $r['einvoice_ack_dt'], 'cancel_hours_left' => (new \App\Services\Einvoice\EinvoiceService())->cancelHoursLeft($r), 'cancel_reason' => $r['einvoice_cancel_reason']]];
    }

    public function issue($id = null)
    {
        return $this->guard(fn () => $this->summary((new InvoiceService())->issueForBooking(CurrentUser::tenantId(), (int) $id, $this->body(), CurrentUser::id())), 201);
    }

    /** POST /billing-docs/invoices/:id/einvoice/cancel { reason: 1-4, remark } */
    public function cancelEinvoice($id = null)
    {
        $b = $this->body();
        return $this->guard(fn () => $this->summary((new \App\Services\Einvoice\EinvoiceService())->cancel(CurrentUser::tenantId(), (int) $id, (int) ($b['reason'] ?? 0), (string) ($b['remark'] ?? ''), CurrentUser::id())));
    }

    public function creditNote($id = null)
    {
        $b = $this->body();
        $opts = ['reason' => $b['reason'] ?? '', 'full' => ! empty($b['full'])];
        if (isset($b['amount_rs'])) { $opts['taxable_value'] = (int) round(((float) $b['amount_rs']) * 100); }
        return $this->guard(fn () => $this->summary((new InvoiceService())->creditNote(CurrentUser::tenantId(), (int) $id, $opts, CurrentUser::id())), 201);
    }

    public function receipt($paymentId = null)
    {
        return $this->guard(fn () => $this->summary((new InvoiceService())->receipt(CurrentUser::tenantId(), (int) $paymentId, CurrentUser::id())), 201);
    }

    /** POST /billing-docs/send {kind: invoice|voucher, id} — send a document to the customer on WhatsApp. */
    public function sendWhatsApp()
    {
        $b = $this->body();
        $kind = (string) ($b['kind'] ?? '');
        if (! in_array($kind, ['invoice', 'voucher', 'portal'], true)) { return $this->fail422('Choose a document to send.'); }
        return $this->guard(fn () => (new DocumentDeliveryService())->send(CurrentUser::tenantId(), $kind, (int) ($b['id'] ?? 0), CurrentUser::id()));
    }

    /** POST /billing-docs/whatsapp-template — create the utility template as a draft to submit to Meta. */
    public function setupTemplate()
    {
        return $this->guard(fn () => (new DocumentDeliveryService())->setupTemplate(CurrentUser::tenantId()), 201);
    }

    /** GET /billing-docs/exports/summary?period=YYYY-MM — what would be filed, with warnings. */
    public function exportSummary()
    {
        return $this->guard(fn () => (new \App\Services\Billing\Docs\GstExportService())->summary(CurrentUser::tenantId(), (string) $this->request->getGet('period')));
    }

    // ---- GSTR-3B, HSN, filing tracker ----------------------------------------------------------------------------------

    private function gstGuard(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\InvalidArgumentException $e) { return $this->fail422($e->getMessage()); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }
    private function gst(): \App\Services\Billing\Docs\GstExportService { return new \App\Services\Billing\Docs\GstExportService(); }

    public function gst3b() { return $this->gstGuard(fn () => $this->gst()->gstr3b(CurrentUser::tenantId(), (string) $this->request->getGet('period'))); }
    public function gstItc() { return $this->gstGuard(fn () => $this->gst()->itcEntry(CurrentUser::tenantId(), (string) $this->request->getGet('period'))); }
    public function saveGstItc() { $b = $this->body(); return $this->gstGuard(fn () => $this->gst()->saveItc(CurrentUser::tenantId(), (string) ($b['period'] ?? ''), $b, CurrentUser::id())); }
    public function gstHsn() { return $this->gstGuard(fn () => $this->gst()->hsn(CurrentUser::tenantId(), (string) $this->request->getGet('period'))); }
    public function gstFilings() { return $this->gstGuard(fn () => $this->gst()->filings(CurrentUser::tenantId(), (int) ($this->request->getGet('fy') ?: \App\Services\Billing\Docs\TcsLedger::currentFy(date('Y-m-d'))))); }
    public function recordGstFiling() { $b = $this->body(); return $this->gstGuard(fn () => $this->gst()->recordFiling(CurrentUser::tenantId(), (string) ($b['period'] ?? ''), (string) ($b['return'] ?? ''), $b, CurrentUser::id()), 201); }
    public function removeGstFiling() { return $this->gstGuard(function () { $this->gst()->removeFiling(CurrentUser::tenantId(), (string) $this->request->getGet('period'), (string) $this->request->getGet('return'), CurrentUser::id()); return ['deleted' => true]; }); }

    /** GET /billing-docs/exports/download?period=YYYY-MM&file=gstr1.json|register.csv|tally.xml */
    public function exportDownload()
    {
        try {
            $r = (new \App\Services\Billing\Docs\GstExportService())->file(CurrentUser::tenantId(), (string) $this->request->getGet('period'), (string) $this->request->getGet('file'));
        } catch (\InvalidArgumentException $e) { return $this->fail422($e->getMessage()); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
        \App\Services\Crm\AuditLogger::log('gst.export', 'export', null, null, ['period' => (string) $this->request->getGet('period'), 'file' => $r['filename']], CurrentUser::tenantId(), CurrentUser::id());
        return $this->response->setHeader('Content-Type', $r['type'])->setHeader('Content-Disposition', 'attachment; filename="' . $r['filename'] . '"')
            ->setHeader('Cache-Control', 'private, no-store')->setHeader('X-Content-Type-Options', 'nosniff')->setBody($r['body']);
    }

    // ---- TCS (27EQ) ------------------------------------------------------------------------------------------------

    private function fyq(): array
    {
        $fy = (int) ($this->request->getGet('fy') ?: \App\Services\Billing\Docs\TcsLedger::currentFy(date('Y-m-d')));
        $q = (int) ($this->request->getGet('q') ?: 1);
        if ($fy < 2020 || $fy > 2100 || $q < 1 || $q > 4) { throw new \InvalidArgumentException('Choose a financial year and quarter.'); }
        return [$fy, $q];
    }

    public function tcsReport()
    {
        return $this->guard(function () { [$fy, $q] = $this->fyq(); return (new \App\Services\Billing\Docs\TcsReportService())->report(CurrentUser::tenantId(), $fy, $q); });
    }

    public function tcsDownload()
    {
        try { [$fy, $q] = $this->fyq(); $csv = (new \App\Services\Billing\Docs\TcsReportService())->csv(CurrentUser::tenantId(), $fy, $q); }
        catch (\InvalidArgumentException $e) { return $this->fail422($e->getMessage()); }
        return $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8')->setHeader('Content-Disposition', 'attachment; filename="TCS_FY' . $fy . '-' . substr((string) ($fy + 1), 2) . '_Q' . $q . '.csv"')
            ->setHeader('Cache-Control', 'private, no-store')->setHeader('X-Content-Type-Options', 'nosniff')->setBody($csv);
    }

    public function tcsAddChallan()
    {
        return $this->guard(fn () => (new \App\Services\Billing\Docs\TcsReportService())->addChallan(CurrentUser::tenantId(), $this->body(), CurrentUser::id()), 201);
    }

    public function tcsDeleteChallan($id = null)
    {
        return $this->guard(function () use ($id) { (new \App\Services\Billing\Docs\TcsReportService())->deleteChallan(CurrentUser::tenantId(), (int) $id, CurrentUser::id()); return ['deleted' => true]; });
    }

    public function setPan($id = null)
    {
        return $this->guard(fn () => (new \App\Services\Billing\Docs\TcsReportService())->setPan(CurrentUser::tenantId(), (int) $id, (string) ($this->body()['pan'] ?? '')));
    }

    public function invoicePdf($id = null)
    {
        $inv = (new InvoiceModel())->setTenant(CurrentUser::tenantId())->find((int) $id);
        if (! $inv) { return $this->failNotFound('Document not found.'); }
        try { return $this->pdf((new InvoiceService())->pdf($inv), str_replace('/', '-', $inv['number']) . '.pdf'); }
        catch (\RuntimeException $e) { log_message('error', $e->getMessage()); return $this->respond(['success' => false, 'message' => $e->getMessage()], 500); }
    }
}
