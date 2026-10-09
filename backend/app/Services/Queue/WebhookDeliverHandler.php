<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\WebhookSubscriptionModel;
use App\Services\Webhooks\OutboundWebhookService;

/**
 * Delivers a single outbound webhook ('webhook_deliver' job).
 *
 * Signs the envelope with the subscription secret and POSTs it. A non-2xx
 * response throws so the queue retries with backoff; success records the status.
 */
class WebhookDeliverHandler
{
    public function handle(array $job, int $tenantId): void
    {
        $payload = json_decode($job['payload'] ?? '{}', true) ?: [];
        $subId   = (int) ($payload['subscription_id'] ?? 0);
        $event   = (string) ($payload['event'] ?? '');
        $data    = $payload['data'] ?? [];

        $model = new WebhookSubscriptionModel();
        $sub   = $model->setTenant($tenantId)->find($subId);
        if ($sub === null || ! (int) $sub['is_active']) {
            return; // subscription removed/disabled since enqueue — drop silently
        }

        // SSRF guard: never deliver to internal/reserved addresses.
        if (! \App\Services\Security\UrlGuard::isSafePublicUrl($sub['url'])) {
            $model->setTenant($tenantId)->update($subId, [
                'last_status'   => 0,
                'failure_count' => (int) $sub['failure_count'] + 1,
            ]);
            log_message('error', "Webhook sub #{$subId} blocked: unsafe URL {$sub['url']}");
            return; // drop — do not retry an unsafe target
        }

        $body = OutboundWebhookService::envelope($event, $data, time());
        $sig  = OutboundWebhookService::sign($body, $sub['secret']);

        $ch = curl_init($sub['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $body,
            CURLOPT_TIMEOUT_MS        => 10_000,
            CURLOPT_CONNECTTIMEOUT_MS => 5_000,
            CURLOPT_HTTPHEADER        => [
                'Content-Type: application/json',
                'X-TravelPilot-Event: ' . $event,
                'X-TravelPilot-Signature: ' . $sig,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $ok = $err === '' && $code >= 200 && $code < 300;

        $model->setTenant($tenantId)->update($subId, [
            'last_status'       => $code ?: null,
            'last_delivered_at' => date('Y-m-d H:i:s'),
            'failure_count'     => $ok ? 0 : ((int) $sub['failure_count'] + 1),
        ]);

        if (! $ok) {
            // Throw so the queue retries with exponential backoff.
            throw new \RuntimeException("Webhook delivery to sub #{$subId} failed: " . ($err ?: "HTTP {$code}"));
        }
    }
}
