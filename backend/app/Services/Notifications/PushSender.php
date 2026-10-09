<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\PushSubscriptionModel;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Delivers browser Web-Push notifications to a tenant's subscribed devices.
 * No-ops cleanly when VAPID keys aren't configured.
 */
class PushSender
{
    public function sendToTenant(int $tenantId, string $title, string $body, string $url): void
    {
        $public  = (string) env('VAPID_PUBLIC_KEY', '');
        $private = (string) env('VAPID_PRIVATE_KEY', '');
        if ($public === '' || $private === '') {
            return; // push not configured
        }

        $subs = (new PushSubscriptionModel())->forTenant($tenantId);
        if (empty($subs)) {
            return;
        }

        $webPush = new WebPush(['VAPID' => [
            'subject'    => (string) env('VAPID_SUBJECT', 'mailto:support@example.com'),
            'publicKey'  => $public,
            'privateKey' => $private,
        ]]);

        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);
        $model   = new PushSubscriptionModel();

        foreach ($subs as $s) {
            $sub = Subscription::create([
                'endpoint' => $s['endpoint'],
                'keys'     => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
            ]);
            $webPush->queueNotification($sub, $payload);
        }

        // Flush; prune subscriptions the browser has expired (410/404).
        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess() && in_array($report->getResponse()?->getStatusCode(), [404, 410], true)) {
                $endpoint = $report->getEndpoint();
                $row = $model->where('endpoint', $endpoint)->first();
                if ($row) {
                    $model->delete($row['id']);
                }
            }
        }
    }
}
