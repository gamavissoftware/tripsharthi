<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\IntegrationModel;
use App\Models\PaymentLinkModel;
use App\Services\Auth\CurrentUser;
use App\Services\Commerce\PaymentLinkService;
use App\Services\WhatsApp\TokenCipher;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Per-tenant Razorpay payments: credential config + payment-link sending.
 */
class PaymentsController extends ResourceController
{
    protected $format = 'json';

    // GET /api/v1/payments/config — connection status (secrets never returned)
    public function getConfig(): ResponseInterface
    {
        $integration = (new IntegrationModel())
            ->findActiveByType(CurrentUser::tenantId(), 'razorpay_payments');

        // Razorpay has to reach this URL — it must be the app's public base URL,
        // not whatever origin the admin's browser happens to be on.
        $webhookUrl = rtrim(base_url('webhooks/razorpay-payments'), '/');

        if ($integration === null) {
            return $this->respond(['success' => true, 'data' => [
                'connected'   => false,
                'webhook_url' => $webhookUrl,
            ]]);
        }

        $config = json_decode($integration['config'] ?? '{}', true) ?: [];
        return $this->respond(['success' => true, 'data' => [
            'connected'         => true,
            'key_id'            => $config['key_id'] ?? '',
            'has_webhook_secret'=> ! empty($config['webhook_secret_enc']),
            'webhook_url'       => $webhookUrl,
        ]]);
    }

    // POST /api/v1/payments/config — store tenant Razorpay credentials
    public function saveConfig(): ResponseInterface
    {
        $rules = [
            'key_id'     => 'required|string',
            'key_secret' => 'required|string',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId = CurrentUser::tenantId();
        $config   = [
            'key_id'         => trim((string) $this->request->getJsonVar('key_id')),
            'key_secret_enc' => TokenCipher::encrypt(trim((string) $this->request->getJsonVar('key_secret'))),
        ];
        $webhookSecret = $this->request->getJsonVar('webhook_secret');
        if ($webhookSecret) {
            $config['webhook_secret_enc'] = TokenCipher::encrypt(trim((string) $webhookSecret));
        }

        $model    = new IntegrationModel();
        $existing = $model->findActiveByType($tenantId, 'razorpay_payments');

        if ($existing) {
            // Preserve an existing webhook secret if not re-sent.
            if (! $webhookSecret) {
                $prev = json_decode($existing['config'] ?? '{}', true) ?: [];
                if (! empty($prev['webhook_secret_enc'])) {
                    $config['webhook_secret_enc'] = $prev['webhook_secret_enc'];
                }
            }
            $model->setTenant($tenantId)->update((int) $existing['id'], [
                'config' => json_encode($config),
                'status' => 'active',
            ]);
        } else {
            $model->setTenant($tenantId)->insert([
                'type'   => 'razorpay_payments',
                'config' => json_encode($config),
                'status' => 'active',
            ]);
        }

        return $this->respond(['success' => true, 'message' => 'Razorpay payments connected.']);
    }

    // GET /api/v1/payments/links — recent payment links
    public function index(): ResponseInterface
    {
        $links = (new PaymentLinkModel())
            ->setTenant(CurrentUser::tenantId())
            ->orderBy('created_at', 'DESC')
            ->findAll(100);

        return $this->respond(['success' => true, 'data' => $links]);
    }

    // POST /api/v1/payments/links — create a link and send it to a contact
    public function create(): ResponseInterface
    {
        $rules = [
            'contact_id' => 'required|is_natural_no_zero',
            'amount'     => 'required|numeric',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId  = CurrentUser::tenantId();
        $contactId = (int) $this->request->getJsonVar('contact_id');
        $amount    = (float) $this->request->getJsonVar('amount');
        $desc      = (string) ($this->request->getJsonVar('description') ?? 'Payment request');

        $contact = (new ContactModel())->setTenant($tenantId)->find($contactId);
        if (! $contact) {
            return $this->failNotFound("Contact #{$contactId} not found.");
        }

        $conv   = (new ConversationModel())->findOrCreate($tenantId, $contact['wa_number']);
        $convId = (int) $conv['id'];

        $service = new PaymentLinkService();
        try {
            $link = $service->createLink($tenantId, $amount, [
                'contact_id'      => $contactId,
                'conversation_id' => $convId,
                'description'     => $desc,
            ]);
        } catch (\RuntimeException $e) {
            return $this->fail(['payments' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->fail(['amount' => $e->getMessage()], 422);
        }

        // Deliver over WhatsApp only if the 24h window is open (free-form rule).
        $windowOpen = (new WindowService(new ConversationModel()))->isOpenForConversation($conv);
        $delivered  = false;
        if ($windowOpen) {
            try {
                $client = PaymentLinkService::buildClientForTenant($tenantId);
                $res    = $service->deliver($tenantId, $link, $contact['wa_number'], $client, $convId);
                $delivered = (bool) $res['success'];
            } catch (\Throwable $e) {
                log_message('error', 'Payment link delivery failed: ' . $e->getMessage());
            }
        }

        return $this->respondCreated([
            'success'      => true,
            'data'         => (new PaymentLinkModel())->setTenant($tenantId)->find((int) $link['id']),
            'delivered'    => $delivered,
            'window_open'  => $windowOpen,
            'message'      => $delivered
                ? 'Payment link sent on WhatsApp.'
                : 'Payment link created. The 24h window is closed — copy the link or send a template.',
        ]);
    }
}
