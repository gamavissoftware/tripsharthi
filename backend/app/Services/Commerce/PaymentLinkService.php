<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\IntegrationModel;
use App\Models\MessageModel;
use App\Models\PaymentLinkModel;
use App\Models\WabaAccountModel;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\ProviderAdapter;

/**
 * Creates and delivers Razorpay payment links to contacts over WhatsApp.
 *
 * Per-tenant credentials: each tenant connects their OWN Razorpay account
 * (integrations.type = 'razorpay_payments'); TravelPilot is never in the
 * money-movement loop, mirroring the bring-your-own-WABA model.
 *
 * RAZORPAY_MOCK_MODE short-circuits the Razorpay API for local dev/tests.
 */
class PaymentLinkService
{
    public function __construct(
        private readonly ?IntegrationModel $integrations = null,
        private readonly ?PaymentLinkModel $links        = null,
    ) {}

    /** Pure rupee→paise conversion (Razorpay smallest unit). */
    public static function toPaise(int|float|string $inr): int
    {
        return (int) round(((float) $inr) * 100);
    }

    /**
     * Create a Razorpay payment link and persist a payment_links row.
     *
     * @param array $opts contact_id?, conversation_id?, description?
     * @return array The stored payment link row.
     */
    public function createLink(int $tenantId, int|float|string $amountInr, array $opts = []): array
    {
        $amountPaise = self::toPaise($amountInr);
        if ($amountPaise < 100) {
            throw new \InvalidArgumentException('Amount must be at least ₹1.');
        }

        $integrations = $this->integrations ?? new IntegrationModel();
        $integration  = $integrations->findActiveByType($tenantId, 'razorpay_payments');
        if ($integration === null) {
            throw new \RuntimeException('Razorpay payments is not connected for this tenant.');
        }
        $keys = $integrations->razorpayKeys($integration);

        $reference   = 'tp_pl_' . $tenantId . '_' . bin2hex(random_bytes(8));
        $description = (string) ($opts['description'] ?? 'Payment request');

        if ($this->isMockMode()) {
            $linkId   = 'plink_mock_' . bin2hex(random_bytes(6));
            $shortUrl = 'https://rzp.io/i/mock_' . substr($reference, -6);
        } else {
            if ($keys['key_id'] === '' || $keys['key_secret'] === '') {
                throw new \RuntimeException('Razorpay credentials are incomplete.');
            }
            $resp = $this->razorpayPost($keys, '/payment_links', [
                'amount'          => $amountPaise,
                'currency'        => 'INR',
                'description'     => $description,
                'reference_id'    => $reference,
                'notify'          => ['sms' => false, 'email' => false], // we deliver via WhatsApp
                'reminder_enable' => true,
                'notes'           => ['tenant_id' => (string) $tenantId, 'reference_id' => $reference],
            ]);
            $linkId   = $resp['id'] ?? '';
            $shortUrl = $resp['short_url'] ?? '';
        }

        $linkModel = $this->links ?? new PaymentLinkModel();
        $id = (int) $linkModel->setTenant($tenantId)->insert([
            'contact_id'       => ($opts['contact_id'] ?? 0) ?: null,
            'conversation_id'  => ($opts['conversation_id'] ?? 0) ?: null,
            'amount_paise'     => $amountPaise,
            'currency'         => 'INR',
            'description'      => $description,
            'reference_id'     => $reference,
            'razorpay_link_id' => $linkId,
            'short_url'        => $shortUrl,
            'status'           => 'created',
        ], true);

        return $linkModel->setTenant($tenantId)->find($id);
    }

    /**
     * Deliver a created link to the contact over WhatsApp (free-form text).
     * Caller is responsible for window policy; pass a built client/adapter.
     */
    public function deliver(
        int $tenantId,
        array $link,
        string $waNumber,
        CloudApiClient|ProviderAdapter $client,
        ?int $conversationId = null,
    ): array {
        $amount = number_format($link['amount_paise'] / 100, 2);
        $body   = "💳 {$link['description']}\nAmount: ₹{$amount}\nPay securely here: {$link['short_url']}";

        $result = $client->sendText($waNumber, $body);
        $status = ($result['success'] ?? false) ? 'sent' : 'failed';

        $msgId = null;
        if ($conversationId) {
            $msgId = (int) (new MessageModel())->withoutTenantScope()->insert([
                'tenant_id'       => $tenantId,
                'contact_id'      => ($link['contact_id'] ?? 0) ?: null,
                'conversation_id' => $conversationId,
                'direction'       => 'out',
                'type'            => 'text',
                'category'        => 'free_form',
                'body'            => $body,
                'wa_message_id'   => $result['message_id'] ?? null,
                'status'          => $status,
                'billable'        => 0,
                'error'           => $result['error'] ?? null,
                'sent_at'         => ($result['success'] ?? false) ? date('Y-m-d H:i:s') : null,
            ], true);
        }

        $linkModel = $this->links ?? new PaymentLinkModel();
        $linkModel->setTenant($tenantId)->update((int) $link['id'], [
            'status'          => $status === 'sent' ? 'sent' : $link['status'],
            'message_id'      => $msgId,
            'conversation_id' => $conversationId ?: ($link['conversation_id'] ?? null),
        ]);

        return ['success' => $status === 'sent', 'message_id' => $msgId, 'result' => $result];
    }

    /**
     * Apply a Razorpay payment_link.* webhook event to the stored link.
     *
     * @param  array $entity  The payment_link entity (id, reference_id, status).
     * @return array|null     The updated link row, or null if unknown.
     */
    public function applyWebhookStatus(string $event, array $entity, ?string $paymentId = null): ?array
    {
        $linkModel = $this->links ?? new PaymentLinkModel();

        $link = null;
        if (! empty($entity['id'])) {
            $link = $linkModel->findByRazorpayLinkId((string) $entity['id']);
        }
        if ($link === null && ! empty($entity['reference_id'])) {
            $link = $linkModel->findByReference((string) $entity['reference_id']);
        }
        if ($link === null) {
            return null;
        }
        $link = is_array($link) ? $link : (array) $link;

        $status = match ($event) {
            'payment_link.paid'      => 'paid',
            'payment_link.cancelled' => 'cancelled',
            'payment_link.expired'   => 'expired',
            default                  => null,
        };
        if ($status === null) {
            return $link;
        }

        $update = ['status' => $status];
        if ($status === 'paid') {
            $update['paid_at']             = date('Y-m-d H:i:s');
            $update['razorpay_payment_id'] = $paymentId ?: ($link['razorpay_payment_id'] ?? null);
        }

        $tenantId = (int) $link['tenant_id'];
        $linkModel->setTenant($tenantId)->update((int) $link['id'], $update);

        if ($status === 'paid') {
            // Travel: settle the booking instalment this link was issued for.
            try {
                (new \App\Services\Travel\BookingService())->onPaymentLinkPaid($tenantId, (int) $link['id'], $update['razorpay_payment_id'] ?? null);
            } catch (\Throwable $e) {
                log_message('error', 'booking settle failed for link #' . $link['id'] . ': ' . $e->getMessage());
            }
            \App\Services\Webhooks\OutboundWebhookService::emit($tenantId, 'payment.paid', [
                'payment_link_id' => (int) $link['id'],
                'contact_id'      => $link['contact_id'] ?? null,
                'amount_paise'    => (int) $link['amount_paise'],
                'currency'        => $link['currency'] ?? 'INR',
                'reference_id'    => $link['reference_id'] ?? null,
                'razorpay_payment_id' => $update['razorpay_payment_id'] ?? null,
            ]);
        }

        return array_merge($link, $update);
    }

    /** Build a WhatsApp adapter for a tenant's active WABA. */
    public static function buildClientForTenant(int $tenantId): ProviderAdapter
    {
        $wabaModel = new WabaAccountModel();
        $account   = $wabaModel->findActive($tenantId);
        if ($account === null) {
            throw new \RuntimeException("No active WABA account for tenant #{$tenantId}.");
        }
        return $wabaModel->buildAdapter($account);
    }

    // ------------------------------------------------------------------

    private function razorpayPost(array $keys, string $path, array $data): array
    {
        $ch = curl_init('https://api.razorpay.com/v1' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => 10_000,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_USERPWD        => "{$keys['key_id']}:{$keys['key_secret']}",
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("Razorpay API cURL error: {$err}");
        }
        $parsed = json_decode($resp, true) ?? [];
        if ($code < 200 || $code >= 300) {
            $msg = $parsed['error']['description'] ?? "HTTP {$code}";
            throw new \RuntimeException("Razorpay payment link error: {$msg}");
        }
        return $parsed;
    }

    private function isMockMode(): bool
    {
        return filter_var(env('RAZORPAY_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }
}
