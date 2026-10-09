<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\NotificationModel;

/**
 * Creates in-app notifications (Phase L). Fire-and-forget — a notification
 * failure must never break the action that triggered it.
 */
final class NotificationService
{
    public static function notify(int $tenantId, int $userId, string $type, string $body, ?string $link = null): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            (new NotificationModel())->insert([
                'tenant_id'  => $tenantId,
                'user_id'    => $userId,
                'type'       => $type,
                'body'       => mb_substr($body, 0, 500),
                'link'       => $link,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Notification failed (' . $type . ' → user ' . $userId . '): ' . $e->getMessage());
        }
        // Mirror to the phone: push-only (the in-app row above is already the durable record).
        \App\Services\Push\PushNotifier::safely(static function ($n) use ($tenantId, $userId, $type, $body, $link) {
            $cat = \App\Services\Push\PushCategory::forLegacyType($type);
            $title = ['ad_rule' => '🤖 Ad alert', 'ad_issue' => '⚠️ Ad disapproved', 'gst_due' => '🧾 GST return due', 'mention' => '@ You were mentioned'][$type] ?? 'TravelPilot';
            $n->notify($tenantId, $userId, $cat, 'legacy_' . $type, $title, mb_substr($body, 0, 250), ['screen' => 'Notifications', 'params' => new \stdClass()], null, $link, false);
        });
    }
}
