<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\DealLineItemModel;
use App\Models\DealModel;
use App\Models\QuoteModel;
use App\Models\WabaAccountModel;
use App\Services\Auth\CurrentUser;
use App\Services\Crm\QuotePdfService;
use App\Services\Crm\QuoteSendService;
use App\Services\Email\ContactEmailService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Quotes (CRM Phase F / CPQ) — a priced proposal snapshotted from a deal's line
 * items, with a status workflow (draft → sent → accepted/rejected).
 */
class QuotesController extends ResourceController
{
    protected $format = 'json';

    private function model(): QuoteModel
    {
        return (new QuoteModel())->setTenant(CurrentUser::tenantId());
    }

    /** GET /deals/:dealId/quotes */
    /** GET /contacts/:id/quotations — quotes across all of this lead's deals. */
    public function forContact($contactId = null): ResponseInterface
    {
        return $this->respond(['success' => true, 'data' => $this->model()->forContact(CurrentUser::tenantId(), (int) $contactId)]);
    }

    public function forDeal($dealId = null): ResponseInterface
    {
        return $this->respond(['success' => true, 'data' => $this->model()->forDeal(CurrentUser::tenantId(), (int) $dealId)]);
    }

    /** POST /deals/:dealId/quotes — snapshot the deal's line items into a new quote. */
    public function createFromDeal($dealId = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $deal     = (new DealModel())->setTenant($tenantId)->find((int) $dealId);
        if (! $deal) {
            return $this->failNotFound("Deal #{$dealId} not found.");
        }

        $items    = (new DealLineItemModel())->forDeal($tenantId, (int) $dealId);
        $snapshot = array_map(static fn ($i) => [
            'name'         => $i['name'],
            'quantity'     => (int) $i['quantity'],
            'unit_price'   => (int) $i['unit_price'],
            'discount_pct' => (int) $i['discount_pct'],
            'tax_pct'      => (int) $i['tax_pct'],
            'total'        => (int) $i['total'],
        ], $items);
        $subtotal = array_sum(array_map(static fn ($i) => (int) $i['quantity'] * (int) $i['unit_price'], $items));
        $total    = array_sum(array_map(static fn ($i) => (int) $i['total'], $items));

        $model = $this->model();
        $id    = $model->insert([
            'deal_id'     => (int) $dealId,
            'number'      => (new QuoteModel())->nextNumber($tenantId),
            'status'      => 'draft',
            'valid_until' => date('Y-m-d', strtotime('+14 days')),
            'currency'    => $deal['currency'] ?? 'INR',
            'subtotal'    => $subtotal,
            'total'       => $total,
            'items'       => json_encode($snapshot),
            'notes'       => $this->request->getJsonVar('notes'),
        ], true);

        $quote = $model->find((int) $id);
        (new ActivityModel())->log($tenantId, 'system', 'deal', (int) $dealId, [
            'subject' => "Quote {$quote['number']} created", 'actor_user_id' => CurrentUser::id(),
        ]);

        return $this->respondCreated(['success' => true, 'data' => $quote]);
    }

    public function show($id = null): ResponseInterface
    {
        $quote = $this->model()->find((int) $id);
        if (! $quote) {
            return $this->failNotFound("Quote #{$id} not found.");
        }
        $quote['items'] = json_decode($quote['items'] ?? '[]', true) ?: [];
        $quote['deal']  = (new DealModel())->setTenant(CurrentUser::tenantId())->find((int) $quote['deal_id']);
        return $this->respond(['success' => true, 'data' => $quote]);
    }

    /** POST /quotes/:id/status { status } */
    public function setStatus($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Quote #{$id} not found.");
        }
        $status = (string) $this->request->getJsonVar('status');
        if (! in_array($status, ['draft', 'sent', 'accepted', 'rejected', 'expired'], true)) {
            return $this->fail(['status' => 'Invalid status.'], 422);
        }
        $this->model()->update((int) $id, ['status' => $status]);
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Quote #{$id} not found.");
        }
        $model->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    /** GET /quotes/:id/pdf — branded PDF, inline (Phase J1). */
    public function pdf($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $quote    = $this->model()->find((int) $id);
        if (! $quote) {
            return $this->failNotFound("Quote #{$id} not found.");
        }
        $quote['items'] = json_decode($quote['items'] ?? '[]', true) ?: [];
        $deal           = (new DealModel())->setTenant($tenantId)->find((int) $quote['deal_id']) ?? [];

        $pdf = (new QuotePdfService())->render($quote, $deal, $tenantId);
        return $this->response->setStatusCode(200)
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="quote-' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $quote['number']) . '.pdf"')
            ->setBody($pdf);
    }

    /** POST /quotes/:id/send — deliver the PDF over WhatsApp (windowed, Phase J1). */
    public function send($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $quote    = $this->model()->find((int) $id);
        if (! $quote) {
            return $this->failNotFound("Quote #{$id} not found.");
        }
        $quote['items'] = json_decode($quote['items'] ?? '[]', true) ?: [];
        $deal           = (new DealModel())->setTenant($tenantId)->find((int) $quote['deal_id']);
        if (! $deal || empty($deal['primary_contact_id'])) {
            return $this->fail(['contact' => 'The deal has no primary contact to send to.'], 422);
        }
        $contact = (new ContactModel())->setTenant($tenantId)->find((int) $deal['primary_contact_id']);
        if (! $contact) {
            return $this->failNotFound('Primary contact not found.');
        }

        $account = (new WabaAccountModel())->findActive($tenantId);
        if (! $account) {
            return $this->fail('No active WhatsApp account configured.', 422);
        }
        $client = (new WabaAccountModel())->buildAdapter($account);

        $conv = (new ConversationModel())->findOrCreate($tenantId, (string) $contact['wa_number'], ['contact_id' => (int) $contact['id']]);

        $res = (new QuoteSendService())->deliver($tenantId, $quote, $deal, (array) $contact, (array) $conv, $client);
        if ($res['blocked']) {
            return $this->respond(['success' => false, 'code' => 'WINDOW_CLOSED', 'message' => 'The 24-hour window is closed — send a template first.'], 422);
        }
        if (! $res['sent']) {
            return $this->fail('WhatsApp send failed.', 502);
        }
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    /** POST /quotes/:id/send-email — email the PDF to the deal's primary contact. */
    public function sendEmail($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $quote    = $this->model()->find((int) $id);
        if (! $quote) {
            return $this->failNotFound("Quote #{$id} not found.");
        }
        $quote['items'] = json_decode($quote['items'] ?? '[]', true) ?: [];
        $deal           = (new DealModel())->setTenant($tenantId)->find((int) $quote['deal_id']);
        if (! $deal || empty($deal['primary_contact_id'])) {
            return $this->fail(['contact' => 'The deal has no primary contact to send to.'], 422);
        }

        $pdfPath = (new QuotePdfService())->generate($quote, (array) $deal, $tenantId);
        $subject = "Quote {$quote['number']}" . (! empty($deal['title']) ? " — {$deal['title']}" : '');
        $body    = '<p>Hello,</p><p>Please find attached quote <strong>' . htmlspecialchars((string) $quote['number'], ENT_QUOTES, 'UTF-8')
            . '</strong>. We look forward to working with you.</p><p>Thank you.</p>';

        $res = (new ContactEmailService())->sendToContact(
            $tenantId, (int) $deal['primary_contact_id'], $subject, $body, CurrentUser::id(), $pdfPath
        );
        if (! $res['success']) {
            return $this->fail(['email' => $res['error'] ?? 'Could not send the quote email.'], 422);
        }

        // Emailing a draft advances it to "sent" (mirrors the WhatsApp delivery).
        if (($quote['status'] ?? '') === 'draft') {
            $this->model()->update((int) $id, ['status' => 'sent']);
        }
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }
}
