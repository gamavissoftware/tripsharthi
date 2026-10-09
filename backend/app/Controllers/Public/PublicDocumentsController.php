<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Models\InvoiceModel;
use App\Models\ItineraryModel;
use App\Services\Billing\Docs\InvoiceService;
use App\Services\Billing\Docs\QuoteDocument;
use App\Services\Billing\Docs\VoucherDocument;
use CodeIgniter\RESTful\ResourceController;

/** Customer-facing PDF downloads. The 32-hex token in the link is the credential; nothing here lists or enumerates. */
class PublicDocumentsController extends ResourceController
{
    private function pdf(string $bytes, string $filename)
    {
        return $this->response->setHeader('Content-Type', 'application/pdf')->setHeader('Content-Disposition', 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')->setHeader('X-Robots-Tag', 'noindex')->setBody($bytes);
    }

    public function quote($token = '')
    {
        if (! preg_match('/^[a-f0-9]{32}$/', (string) $token)) { return $this->failNotFound('This link is invalid.'); }
        $it = (new ItineraryModel())->withoutTenantScope()->where('share_token', $token)->first();
        if (! $it) { return $this->failNotFound('This link is invalid.'); }
        $r = (new QuoteDocument())->render((int) $it['tenant_id'], (int) $it['id']);
        return $this->pdf($r['pdf'], $r['filename']);
    }

    public function invoice($token = '')
    {
        if (! preg_match('/^[a-f0-9]{32}$/', (string) $token)) { return $this->failNotFound('This link is invalid.'); }
        $inv = (new InvoiceModel())->withoutTenantScope()->where('share_token', $token)->first();
        if (! $inv) { return $this->failNotFound('This link is invalid.'); }
        if (($inv['einvoice_status'] ?? '') === 'cancelled' || $inv['status'] === 'cancelled') { return $this->respond(['success' => false, 'message' => 'This invoice was cancelled and replaced. Please ask for the current one.'], 410); }
        try { return $this->pdf((new InvoiceService())->pdf($inv), str_replace('/', '-', $inv['number']) . '.pdf'); }
        catch (\RuntimeException $e) { log_message('error', $e->getMessage()); return $this->failServerError('This document is temporarily unavailable.'); }
    }

    /** Service voucher by its per-service token (booking_services.voucher_token). */
    public function voucher($token = '')
    {
        if (! preg_match('/^[a-f0-9]{32}$/', (string) $token)) { return $this->failNotFound('This link is invalid.'); }
        $svc = (new \App\Models\BookingServiceModel())->withoutTenantScope()->where('voucher_token', $token)->first();
        if (! $svc) { return $this->failNotFound('This link is invalid.'); }
        $r = (new VoucherDocument())->render((int) $svc['tenant_id'], (int) $svc['id']);
        return $this->pdf($r['pdf'], $r['filename']);
    }
}
