<?php

declare(strict_types=1);

namespace App\Controllers\Webhooks;

use App\Models\IntegrationModel;
use App\Models\PaymentLinkModel;
use App\Services\Commerce\PaymentLinkService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Razorpay PAYMENT LINK webhook — POST /webhooks/razorpay-payments
 *
 * Distinct from the platform subscription webhook: these events belong to each
 * tenant's OWN Razorpay account. Since the payload doesn't carry tenant context,
 * we resolve the tenant from the payment_links row (matched by link id /
 * reference_id) and verify the signature using THAT tenant's webhook secret.
 * The reference is only a lookup key — forgery is still blocked because the
 * HMAC must match the tenant's secret.
 *
 * Always returns 200 so Razorpay stops retrying (same discipline as other hooks).
 */
class RazorpayPaymentsWebhookController extends Controller
{
    public function receive(): ResponseInterface
    {
        $rawBody = $this->request->getBody() ?? '';
        $sig     = $this->request->getHeaderLine('X-Razorpay-Signature');

        $payload = json_decode($rawBody, true) ?: [];
        $event   = $payload['event'] ?? '';
        $entity  = $payload['payload']['payment_link']['entity'] ?? null;

        if (! $entity) {
            return $this->ok();
        }

        // ── Resolve tenant from the stored link (lookup only) ──────────
        $linkModel = new PaymentLinkModel();
        $link = ! empty($entity['id'])
            ? $linkModel->findByRazorpayLinkId((string) $entity['id'])
            : null;
        if ($link === null && ! empty($entity['reference_id'])) {
            $link = $linkModel->findByReference((string) $entity['reference_id']);
        }
        if ($link === null) {
            log_message('info', 'RazorpayPaymentsWebhook: unknown payment link — ignored.');
            return $this->ok();
        }
        $link     = is_array($link) ? $link : (array) $link;
        $tenantId = (int) $link['tenant_id'];

        // ── Verify with the tenant's own webhook secret ────────────────
        $integration = (new IntegrationModel())->findActiveByType($tenantId, 'razorpay_payments');
        $secret      = $integration
            ? ((new IntegrationModel())->razorpayKeys($integration)['webhook_secret'] ?? '')
            : '';

        if (! $this->verify($rawBody, $sig, $secret)) {
            log_message('error', "RazorpayPaymentsWebhook: signature mismatch for tenant #{$tenantId} — rejected.");
            return $this->ok();
        }

        $paymentId = $payload['payload']['payment']['entity']['id'] ?? null;

        try {
            (new PaymentLinkService())->applyWebhookStatus($event, $entity, $paymentId);
            log_message('info', "RazorpayPaymentsWebhook: '{$event}' applied to link #{$link['id']} (tenant #{$tenantId}).");
        } catch (\Throwable $e) {
            log_message('error', 'RazorpayPaymentsWebhook: ' . $e->getMessage());
        }

        return $this->ok();
    }

    private function verify(string $rawBody, string $sig, string $secret): bool
    {
        // Local bypass mirrors the platform verifier.
        if (! filter_var(env('RAZORPAY_VERIFY_SIGNATURE', true), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        if ($sig === '' || $secret === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $sig);
    }

    private function ok(): ResponseInterface
    {
        return $this->response->setStatusCode(200)->setBody('OK');
    }
}
