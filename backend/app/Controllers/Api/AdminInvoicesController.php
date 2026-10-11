<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Billing\PlatformInvoiceService;

/** /api/v1/admin/invoices* - TripSarthi's own GST invoices (platform admins only; filter adminauth). Errors: 404, 422, 409; never 401. */
class AdminInvoicesController extends TravelBaseController
{
    private const FILTERS = ['month', 'from', 'to', 'fy', 'q', 'buyer_type', 'supply_type', 'plan', 'source', 'pos', 'tenant_id', 'email', 'min', 'max', 'sort', 'page', 'per_page'];

    private function filters(): array { $o = []; foreach (self::FILTERS as $k) { $v = $this->request->getGet($k); if ($v !== null && $v !== '') { $o[$k] = (string) $v; } } return $o; }

    private function run(callable $fn, int $okCode = 200)
    {
        try { return $this->ok($fn(), $okCode); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'not_found'], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    public function list()    { $f = $this->filters(); return $this->run(fn () => (new PlatformInvoiceService())->list($f)); }
    public function summary() { $f = $this->filters(); return $this->run(fn () => (new PlatformInvoiceService())->summary($f)); }
    public function missing() { return $this->run(fn () => ['rows' => (new PlatformInvoiceService())->missing()]); }

    public function settings()
    {
        return $this->run(function (): array {
            $svc = new PlatformInvoiceService(); $s = $svc->settings();
            return ['settings' => $s, 'problems' => PlatformInvoiceService::validate($s), 'states' => \App\Services\Billing\Docs\Gst::states(), 'storage_ok' => is_dir($svc->storageDir()) || @mkdir($svc->storageDir(), 0775, true)];
        });
    }

    public function saveSettings()
    {
        $b = $this->body();
        return $this->run(fn () => ['settings' => (new PlatformInvoiceService())->saveSettings($b, CurrentUser::id())]);
    }

    public function issueMissing()
    {
        $b = $this->body();
        return $this->run(fn () => (new PlatformInvoiceService())->issueMissing(array_map('intval', (array) ($b['ids'] ?? [])), CurrentUser::id()));
    }

    public function resend($id = null)
    {
        return $this->run(function () use ($id): array {
            $r = (new PlatformInvoiceService())->email((int) $id);
            if (! $r['sent']) { throw new \DomainException('Not sent: ' . ($r['error'] ?? 'unknown error')); }
            return $r;
        });
    }

    /** GET invoices/:id/pdf */
    public function pdf($id = null)
    {
        try { $f = (new PlatformInvoiceService())->pdf((int) $id); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 404); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 409); }
        return $this->response->setHeader('Content-Type', 'application/pdf')->setHeader('Content-Disposition', 'inline; filename="' . $f['filename'] . '"')->setHeader('X-Content-Type-Options', 'nosniff')->setBody($f['bytes']);
    }

    /** GET invoices/export - the sales register for the current filters (CSV). */
    public function export()
    {
        $f = $this->filters(); $csv = (new PlatformInvoiceService())->csv($f);
        $name = 'tripsarthi-invoices-' . ($f['month'] ?? ($f['fy'] ?? 'all')) . '.csv';
        return $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8')->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')->setHeader('X-Content-Type-Options', 'nosniff')->setBody($csv);
    }

    /** GET invoices/bundle - every PDF for the current filters in one ZIP (for the accountant). */
    public function bundle()
    {
        $f = $this->filters();
        try { $z = (new PlatformInvoiceService())->zip($f); } catch (\Throwable $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 409); }
        if ($z['count'] === 0) { @unlink($z['path']); return $this->respond(['success' => false, 'message' => 'No invoice PDFs match these filters.'], 404); }
        $bytes = (string) file_get_contents($z['path']); @unlink($z['path']);
        $name = 'tripsarthi-invoices-' . ($f['month'] ?? ($f['fy'] ?? 'all')) . '.zip';
        return $this->response->setHeader('Content-Type', 'application/zip')->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')->setHeader('X-Skipped', implode(',', $z['skipped']))->setHeader('X-Content-Type-Options', 'nosniff')->setBody($bytes);
    }

    // ---- credit notes (refunds) ------------------------------------------------------------------------------------------------------------------

    /** POST invoices/:id/credit-note { amount_rs?, full?, reason, refund_ref? } - amount is the refund INCLUDING GST; omit it (or full) for everything still creditable. */
    public function creditNote($id = null)
    {
        $b = $this->body();
        return $this->run(function () use ($id, $b): array {
            $full = ! empty($b['full']) || ! isset($b['amount_rs']) || $b['amount_rs'] === '';
            $paise = $full ? null : (int) round((float) $b['amount_rs'] * 100);
            $n = (new \App\Services\Billing\PlatformCreditNoteService())->issue((int) $id, $paise, (string) ($b['reason'] ?? ''), isset($b['refund_ref']) ? (string) $b['refund_ref'] : null, CurrentUser::id());
            if (((new PlatformInvoiceService())->settings()['auto_email'] ?? '1') === '1') { try { (new \App\Services\Billing\PlatformCreditNoteService())->email((int) $n['id']); } catch (\Throwable $e) { log_message('error', 'Credit note e-mail failed: ' . $e->getMessage()); } }
            return ['credit_note' => (new \App\Services\Billing\PlatformCreditNoteService())->list(['q' => $n['number']])['rows'][0] ?? null, 'commission' => $n['commission']];
        }, 201);
    }

    /** GET invoices/:id/credit-notes - what is still creditable and the notes already issued. */
    public function creditNotesFor($id = null)
    {
        return $this->run(function () use ($id): array {
            $svc = new \App\Services\Billing\PlatformCreditNoteService();
            return ['remaining' => $svc->remaining((int) $id), 'notes' => $svc->list(['invoice_id' => (int) $id, 'per_page' => 100, 'sort' => 'oldest'])['rows']];
        });
    }

    public function creditNotes() { $f = $this->filters(); return $this->run(fn () => (new \App\Services\Billing\PlatformCreditNoteService())->list($f)); }

    public function creditNotePdf($id = null)
    {
        try { $f = (new \App\Services\Billing\PlatformCreditNoteService())->pdf((int) $id); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 404); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 409); }
        return $this->response->setHeader('Content-Type', 'application/pdf')->setHeader('Content-Disposition', 'inline; filename="' . $f['filename'] . '"')->setHeader('X-Content-Type-Options', 'nosniff')->setBody($f['bytes']);
    }

    public function creditNoteResend($id = null)
    {
        return $this->run(function () use ($id): array {
            $r = (new \App\Services\Billing\PlatformCreditNoteService())->email((int) $id);
            if (! $r['sent']) { throw new \DomainException('Not sent: ' . ($r['error'] ?? 'unknown error')); }
            return $r;
        });
    }
}
