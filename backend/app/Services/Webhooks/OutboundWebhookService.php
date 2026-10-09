<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Models\WebhookSubscriptionModel;
use App\Services\Flow\JobDispatcher;

/**
 * Emits outbound webhooks to tenant-registered URLs (Zapier / Make / custom CRM).
 *
 * emit() fans an event out to every matching subscription by enqueuing a
 * 'webhook_deliver' job per subscription — delivery happens in the cron worker
 * (WebhookDeliverHandler), so the request path stays fast and failed deliveries
 * are retried with the queue's exponential backoff.
 *
 * Payloads are signed HMAC-SHA256(body, subscription.secret), hex, in the
 * X-TravelPilot-Signature header so receivers can verify authenticity.
 */
class OutboundWebhookService
{
    /** Canonical event catalog (also surfaced in the UI). */
    public const EVENTS = [
        'contact.created',
        'message.received',
        'payment.paid',
        'order.placed',
    ];

    /**
     * Fan an event out to matching subscriptions.
     *
     * @return int Number of delivery jobs enqueued.
     */
    public static function emit(int $tenantId, string $event, array $data): int
    {
        if ($tenantId <= 0) {
            return 0;
        }

        try {
            $subs = (new WebhookSubscriptionModel())->activeForEvent($tenantId, $event);
        } catch (\Throwable $e) {
            log_message('error', 'OutboundWebhook emit lookup failed: ' . $e->getMessage());
            return 0;
        }

        $count = 0;
        foreach ($subs as $sub) {
            try {
                JobDispatcher::dispatch($tenantId, 'webhook_deliver', [
                    'subscription_id' => (int) $sub['id'],
                    'event'           => $event,
                    'data'            => $data,
                ]);
                $count++;
            } catch (\Throwable $e) {
                log_message('error', 'OutboundWebhook dispatch failed: ' . $e->getMessage());
            }
        }
        return $count;
    }

    /** HMAC-SHA256 hex signature of a JSON body. Pure. */
    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * Build the canonical JSON envelope for a delivery. Pure (timestamp passed in).
     */
    public static function envelope(string $event, array $data, int $timestamp): string
    {
        return json_encode([
            'event'        => $event,
            'delivered_at' => gmdate('c', $timestamp),
            'data'         => $data,
        ], JSON_UNESCAPED_SLASHES);
    }

    /** Generate a random signing secret for a new subscription. */
    public static function newSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(20));
    }
}
