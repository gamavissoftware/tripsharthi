<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\IntegrationModel;
use App\Models\PushSubscriptionModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Reply-alert preferences (WhatsApp / email / browser push) + push subscription.
 */
class NotificationsController extends ResourceController
{
    protected $format = 'json';

    // GET /api/v1/notifications/settings
    public function getSettings(): ResponseInterface
    {
        $row    = (new IntegrationModel())->findActiveByType(CurrentUser::tenantId(), 'notifications');
        $config = $row ? (json_decode($row['config'] ?? '{}', true) ?: []) : [];

        return $this->respond(['success' => true, 'data' => [
            'channels'            => $config['channels'] ?? [],
            'alert_phone'         => $config['alert_phone'] ?? '',
            'alert_email'         => $config['alert_email'] ?? (CurrentUser::get()['email'] ?? ''),
            'alert_template'      => $config['alert_template'] ?? '',
            'alert_template_lang' => $config['alert_template_lang'] ?? 'en',
            'vapid_public_key'    => (string) env('VAPID_PUBLIC_KEY', ''),
        ]]);
    }

    // POST /api/v1/notifications/settings
    public function saveSettings(): ResponseInterface
    {
        $channels = $this->request->getJsonVar('channels');
        $channels = is_array($channels)
            ? array_values(array_intersect($channels, ['whatsapp', 'email', 'push']))
            : [];

        (new IntegrationModel())->saveConfig(CurrentUser::tenantId(), 'notifications', [
            'config' => json_encode([
                'channels'            => $channels,
                'alert_phone'         => trim((string) $this->request->getJsonVar('alert_phone')),
                'alert_email'         => trim((string) $this->request->getJsonVar('alert_email')),
                'alert_template'      => trim((string) $this->request->getJsonVar('alert_template')),
                'alert_template_lang' => trim((string) ($this->request->getJsonVar('alert_template_lang') ?? 'en')),
            ]),
        ]);

        return $this->respond(['success' => true, 'message' => 'Notification settings saved.']);
    }

    // POST /api/v1/notifications/push/subscribe
    public function subscribePush(): ResponseInterface
    {
        $endpoint = (string) $this->request->getJsonVar('endpoint');
        $keys     = $this->request->getJsonVar('keys') ?? [];
        $p256dh   = (string) ($keys['p256dh'] ?? '');
        $auth     = (string) ($keys['auth'] ?? '');

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return $this->fail('Invalid push subscription.', 422);
        }

        (new PushSubscriptionModel())->upsert(
            CurrentUser::tenantId(),
            (int) (CurrentUser::get()['id'] ?? 0) ?: null,
            $endpoint, $p256dh, $auth
        );

        return $this->respond(['success' => true, 'message' => 'Push enabled on this device.']);
    }

    // ── In-app notification feed (Phase L) ──────────────────────────────

    // GET /api/v1/notifications/feed
    public function feed(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $userId   = CurrentUser::id();
        $m        = new \App\Models\NotificationModel();
        return $this->respond([
            'success' => true,
            'data'    => $m->feed($tenantId, $userId),
            'unread'  => $m->unreadCount($tenantId, $userId),
        ]);
    }

    // POST /api/v1/notifications/:id/read
    public function markRead($id = null): ResponseInterface
    {
        $m = (new \App\Models\NotificationModel())->setTenant(CurrentUser::tenantId());
        $row = $m->find((int) $id);
        if (! $row || (int) $row['user_id'] !== CurrentUser::id()) {
            return $this->failNotFound('Notification not found.');
        }
        $m->update((int) $id, ['read_at' => date('Y-m-d H:i:s')]);
        return $this->respond(['success' => true]);
    }

    // POST /api/v1/notifications/read-all
    public function markAllRead(): ResponseInterface
    {
        (new \App\Models\NotificationModel())->setTenant(CurrentUser::tenantId())->builder()
            ->where('user_id', CurrentUser::id())->where('read_at IS NULL', null, false)
            ->update(['read_at' => date('Y-m-d H:i:s')]);
        return $this->respond(['success' => true]);
    }
}
