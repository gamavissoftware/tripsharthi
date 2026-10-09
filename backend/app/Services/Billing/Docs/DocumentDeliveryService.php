<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

use App\Models\BookingModel;
use App\Models\BookingServiceModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\DocumentDeliveryModel;
use App\Models\InvoiceModel;
use App\Models\MessageModel;
use App\Models\TemplateModel;
use App\Services\WhatsApp\BillableComputer;
use App\Services\WhatsApp\TemplateComponentBuilder;
use App\Services\WhatsApp\TemplateParamCheck;
use App\Services\WhatsApp\WindowService;

/**
 * Sends an issued document to the customer on WhatsApp (a person pressing "Send").
 *
 * Policy (platform spec §1):
 *  - Window open  -> the PDF itself as a WhatsApp document (falls back to a download link when the provider
 *    cannot carry media). Window closed -> the APPROVED utility template `tp_document_ready` with a download
 *    link; with none approved nothing is sent and the caller is told exactly what to do. Free-form is never
 *    sent outside the window.
 *  - Opted-out customers are never messaged.
 *  - Issued invoices are immutable: the stored, checksum-verified PDF is what is sent (never regenerated).
 *  - A double-click cannot send twice (same document + customer within DUPLICATE_WINDOW seconds is refused).
 *  - Only customer-safe documents are offered: invoices, credit notes, receipts and vouchers.
 */
final class DocumentDeliveryService
{
    public const TEMPLATE = 'tp_document_ready';
    public const DUPLICATE_WINDOW = 60;

    /** @param null|callable(int):object $clientFactory object with sendText/sendTemplate/sendMedia/uploadMedia */
    public function __construct(private readonly mixed $clientFactory = null, private readonly ?int $now = null) {}

    private function now(): int { return $this->now ?? time(); }

    private const LABEL = ['tax_invoice' => 'tax invoice', 'bill_of_supply' => 'bill of supply', 'credit_note' => 'credit note', 'receipt' => 'payment receipt'];

    /**
     * @param 'invoice'|'voucher'|'portal' $kind
     * @return array{status:string,mode:string,message_id:int,to:string,label:string}
     * @throws \InvalidArgumentException not found / nothing to send to (422)
     * @throws \DomainException          needs a human decision: opted out, duplicate, no approved template (409)
     * @throws \RuntimeException         the provider rejected it
     */
    public function send(int $tenantId, string $kind, int $docId, ?int $userId = null): array
    {
        $d = $this->resolve($tenantId, $kind, $docId);
        $contact = $d['contact_id'] ? (new ContactModel())->setTenant($tenantId)->find((int) $d['contact_id']) : null;
        if (! $contact || empty($contact['wa_number'])) { throw new \InvalidArgumentException('This booking has no customer with a WhatsApp number.'); }
        if ((int) ($contact['opt_in'] ?? 1) === 0) { throw new \DomainException('This customer has opted out of WhatsApp messages. Share the document another way.'); }

        $recent = (new DocumentDeliveryModel())->setTenant($tenantId)->where('doc_kind', $kind)->where('doc_id', $docId)->where('contact_id', (int) $contact['id'])
            ->where('status', 'sent')->where('created_at >=', date('Y-m-d H:i:s', $this->now() - self::DUPLICATE_WINDOW))->first();
        if ($recent) { throw new \DomainException('This document was just sent to the customer. Wait a minute before sending it again.'); }

        $wa      = (string) $contact['wa_number'];
        $client  = $this->client($tenantId);
        $conv    = (new ConversationModel())->setTenant($tenantId)->where('wa_number', $wa)->first();
        $open    = $conv ? (new WindowService(new ConversationModel(), $this->now()))->isOpenForConversation((array) $conv) : false;
        $convId  = $conv ? (int) $conv['id'] : (int) (new ConversationModel())->findOrCreate($tenantId, $wa)['id'];
        $first   = explode(' ', trim((string) ($contact['name'] ?: 'there')))[0];

        if ($open) {
            if (! empty($d['link_only'])) {
                $caption = "Hi {$first}, here is your trip portal for booking {$d['booking_ref']}: view your itinerary, pay instalments and upload documents in one place.";
                $r = $client->sendText($wa, $caption . "\n" . $d['link']);
                $r['mode'] = 'link';
            } else {
                $caption = "Hi {$first}, here is your {$d['kind_label']} {$d['number']}" . ($d['booking_ref'] ? " for booking {$d['booking_ref']}" : '') . '. Thank you!';
                $r = $this->sendPdf($client, $wa, $d, $caption);
            }
            $mode = $r['mode'];
            $msg = $this->logMessage($tenantId, $contact, $convId, $mode === 'document' ? 'document' : 'text', 'free_form', $caption . ($mode === 'link' ? ' ' . $d['link'] : ''), $r, 0, null);
        } else {
            $tpl = (new TemplateModel())->setTenant($tenantId)->where('name', self::TEMPLATE)->first();
            if (! $tpl || $tpl['meta_status'] !== 'approved') {
                throw new \DomainException("The customer's 24-hour WhatsApp window is closed, and the \"" . self::TEMPLATE . '" template is not approved yet. Create it from the Documents card on the booking, submit it to Meta from Templates, or share the download link manually.');
            }
            $vars = [$first, $d['kind_label'], $d['booking_ref'] ?: $d['number'], $d['link']];
            $params = array_slice(array_map(static fn ($v) => ['type' => 'text', 'text' => $v === '' ? '-' : $v], $vars), 0, TemplateParamCheck::requiredCount($tpl));
            $r = $client->sendTemplate($wa, $tpl['name'], $tpl['language'], TemplateComponentBuilder::forSend($tpl, $params));
            $r['mode'] = 'template';
            $mode = 'template';
            $msg = $this->logMessage($tenantId, $contact, $convId, 'template', (string) $tpl['category'], (string) $tpl['body'], $r, BillableComputer::compute((string) $tpl['category'], false), (int) $tpl['id']);
        }

        $ok = (bool) ($r['success'] ?? false);
        (new DocumentDeliveryModel())->setTenant($tenantId)->insert([
            'tenant_id' => $tenantId, 'doc_kind' => $kind, 'doc_id' => $docId, 'doc_label' => mb_substr($d['number'], 0, 120), 'booking_id' => $d['booking_id'],
            'contact_id' => (int) $contact['id'], 'channel' => 'whatsapp', 'mode' => $mode, 'status' => $ok ? 'sent' : 'failed', 'message_id' => $msg,
            'error' => $ok ? null : mb_substr((string) ($r['error'] ?? 'send failed'), 0, 250), 'sent_by' => $userId, 'created_at' => date('Y-m-d H:i:s', $this->now()),
        ]);
        if (! $ok) { throw new \RuntimeException('WhatsApp did not accept the message: ' . mb_substr((string) ($r['error'] ?? 'send failed'), 0, 200)); }
        return ['status' => 'sent', 'mode' => $mode, 'message_id' => $msg, 'to' => $wa, 'label' => $d['number']];
    }

    /** Delivery history for one booking (newest first). */
    public function forBooking(int $tenantId, int $bookingId): array
    {
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'doc_kind' => $r['doc_kind'], 'doc_id' => (int) $r['doc_id'], 'label' => $r['doc_label'], 'mode' => $r['mode'],
            'status' => $r['status'], 'error' => $r['error'], 'sent_at' => $r['created_at']],
            (new DocumentDeliveryModel())->setTenant($tenantId)->where('booking_id', $bookingId)->orderBy('id', 'DESC')->findAll(50));
    }

    /** Create the utility template as a DRAFT (submit it from Templates). Idempotent. */
    public function setupTemplate(int $tenantId): array
    {
        $existing = (new TemplateModel())->setTenant($tenantId)->where('name', self::TEMPLATE)->first();
        if ($existing) { return ['id' => (int) $existing['id'], 'meta_status' => $existing['meta_status'], 'created' => false]; }
        $id = (int) (new TemplateModel())->setTenant($tenantId)->insert([
            'name' => self::TEMPLATE, 'display_name' => 'Document Ready', 'language' => 'en', 'category' => 'utility', 'header_type' => 'none',
            'body' => 'Hi {{1}}, your {{2}} for {{3}} is ready. You can download it here: {{4}}',
            'variables' => json_encode(['customer_name', 'document', 'booking_ref', 'link']), 'meta_status' => 'draft',
        ], true);
        return ['id' => $id, 'meta_status' => 'draft', 'created' => true];
    }

    public function templateStatus(int $tenantId): ?string
    {
        $t = (new TemplateModel())->setTenant($tenantId)->where('name', self::TEMPLATE)->first();
        return $t['meta_status'] ?? null;
    }

    // ---- internals -------------------------------------------------------------------------------------------

    /** @return array{contact_id:?int,booking_id:?int,booking_ref:string,number:string,kind_label:string,link:string,filename:string,bytes:callable} */
    private function resolve(int $tenantId, string $kind, int $docId): array
    {
        if ($kind === 'invoice') {
            $inv = (new InvoiceModel())->setTenant($tenantId)->find($docId);
            if (! $inv) { throw new \InvalidArgumentException('Document not found.'); }
            $b = (new BookingModel())->setTenant($tenantId)->find((int) $inv['booking_id']);
            return ['contact_id' => $b['contact_id'] ?? null, 'booking_id' => (int) $inv['booking_id'], 'booking_ref' => (string) ($b['booking_ref'] ?? ''), 'number' => (string) $inv['number'],
                'kind_label' => self::LABEL[$inv['doc_type']] ?? 'document', 'link' => $this->base() . '/api/v1/public/invoices/' . $inv['share_token'] . '/pdf',
                'filename' => str_replace('/', '-', $inv['number']) . '.pdf', 'bytes' => fn () => (new InvoiceService())->pdf($inv)];
        }
        if ($kind === 'voucher') {
            $svc = (new BookingServiceModel())->setTenant($tenantId)->find($docId);
            if (! $svc) { throw new \InvalidArgumentException('Service not found.'); }
            if ($svc['status'] === 'cancelled') { throw new \InvalidArgumentException('A cancelled service has no voucher to send.'); }
            if (empty($svc['voucher_token'])) { throw new \InvalidArgumentException('This service has no voucher yet.'); }
            $b = (new BookingModel())->setTenant($tenantId)->find((int) $svc['booking_id']);
            $r = null;
            return ['contact_id' => $b['contact_id'] ?? null, 'booking_id' => (int) $svc['booking_id'], 'booking_ref' => (string) ($b['booking_ref'] ?? ''), 'number' => 'Voucher: ' . $svc['title'],
                'kind_label' => 'service voucher', 'link' => $this->base() . '/api/v1/public/vouchers/' . $svc['voucher_token'] . '/pdf',
                'filename' => '', 'bytes' => function () use ($tenantId, $docId, &$r) { return $r ??= (new VoucherDocument())->render($tenantId, $docId); }];
        }
        if ($kind === 'portal') {
            $b = (new BookingModel())->setTenant($tenantId)->find($docId);
            if (! $b) { throw new \InvalidArgumentException('Booking not found.'); }
            if ($b['status'] === 'cancelled') { throw new \InvalidArgumentException('This booking is cancelled.'); }
            $token = (new \App\Services\Travel\PortalService())->ensureToken($tenantId, $docId);
            return ['contact_id' => $b['contact_id'] ?? null, 'booking_id' => $docId, 'booking_ref' => (string) $b['booking_ref'], 'number' => 'Trip portal ' . $b['booking_ref'], 'kind_label' => 'trip portal',
                'link' => $this->base() . '/#/trip/' . $token, 'filename' => '', 'bytes' => null, 'link_only' => true];
        }
        throw new \InvalidArgumentException('Unknown document type.');
    }

    private function base(): string { return rtrim((string) base_url(), '/'); }

    /** Upload + send as a document; if the provider cannot carry media, send the download link instead. */
    private function sendPdf(object $client, string $wa, array $d, string $caption): array
    {
        try {
            $raw = ($d['bytes'])();
            $bytes = is_array($raw) ? $raw['pdf'] : $raw;
            $name = $d['filename'] ?: (is_array($raw) ? $raw['filename'] : 'document.pdf');
            $tmp = tempnam(sys_get_temp_dir(), 'tpdoc');
            file_put_contents($tmp, $bytes);
            try { $up = $client->uploadMedia($tmp, 'application/pdf'); } finally { @unlink($tmp); }
            if (($up['success'] ?? false) && ! empty($up['media_id'])) {
                $r = $client->sendMedia($wa, 'document', (string) $up['media_id'], $caption, $name);
                $r['mode'] = 'document';
                return $r;
            }
        } catch (\RuntimeException $e) {
            throw $e; // a missing / corrupt stored PDF must stop the send, not be papered over with a link
        }
        $r = $client->sendText($wa, $caption . "\nDownload: " . $d['link']);
        $r['mode'] = 'link';
        return $r;
    }

    private function logMessage(int $tenantId, array $contact, int $convId, string $type, string $category, string $body, array $r, int $billable, ?int $templateId): int
    {
        $ok = (bool) ($r['success'] ?? false);
        return (int) (new MessageModel())->withoutTenantScope()->insert([
            'tenant_id' => $tenantId, 'contact_id' => $contact['id'], 'conversation_id' => $convId, 'direction' => 'out',
            'type' => $type, 'template_id' => $templateId, 'category' => $category, 'body' => $body,
            'wa_message_id' => $r['message_id'] ?? null, 'status' => $ok ? 'sent' : 'failed', 'billable' => $ok ? $billable : 0,
            'error' => $r['error'] ?? null, 'sent_at' => $ok ? date('Y-m-d H:i:s', $this->now()) : null,
        ], true);
    }

    private function client(int $tenantId): object
    {
        if (is_callable($this->clientFactory)) { return ($this->clientFactory)($tenantId); }
        return \App\Services\Commerce\PaymentLinkService::buildClientForTenant($tenantId);
    }
}
