<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\IntegrationModel;
use App\Models\PhoneNumberModel;
use App\Models\PushSubscriptionModel;
use App\Models\WabaAccountModel;
use App\Services\Email\EmailService;
use App\Services\WhatsApp\CloudApiClient;

/**
 * Fires "you have a new reply" alerts to the business owner/agent when a
 * customer replies and nobody is watching the inbox. Channels are per-tenant and
 * configurable (WhatsApp, Email, browser push).
 *
 * Triggered from WebhookService on inbound — best-effort: any failure is logged,
 * never thrown (an alert must never block storing the customer's message).
 *
 * Throttled to the FIRST unread message of a conversation (caller passes the
 * pre-increment unread_count) so a customer sending five lines = one alert.
 */
class NotificationService
{
    public function notifyInbound(int $tenantId, array $conv, array $message, int $previousUnread): void
    {
        // Only alert on the first unread reply (avoid spamming on bursts).
        if ($previousUnread > 0) {
            return;
        }

        $settings = $this->settings($tenantId);
        $channels = $settings['channels'] ?? [];
        if (empty($channels)) {
            return;
        }

        $who     = $conv['contact_name'] ?: ($conv['wa_number'] ?? 'a customer');
        $preview = $this->preview($message);

        foreach ($channels as $channel) {
            try {
                match ($channel) {
                    'email'    => $this->sendEmail($settings, $who, $preview),
                    'whatsapp' => $this->sendWhatsApp($tenantId, $settings, $who, $preview),
                    'push'     => $this->sendPush($tenantId, $who, $preview, (int) ($conv['id'] ?? 0)),
                    default    => null,
                };
            } catch (\Throwable $e) {
                log_message('error', "NotificationService [{$channel}] failed: " . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------

    private function settings(int $tenantId): array
    {
        $row = (new IntegrationModel())->findActiveByType($tenantId, 'notifications');
        if ($row === null) {
            return [];
        }
        return json_decode($row['config'] ?? '{}', true) ?: [];
    }

    private function preview(array $message): string
    {
        $body = trim((string) ($message['body'] ?? ''));
        if ($body === '') {
            $body = '[' . ($message['type'] ?? 'message') . ']';
        }
        return mb_strlen($body) > 120 ? mb_substr($body, 0, 120) . '…' : $body;
    }

    private function sendEmail(array $settings, string $who, string $preview): void
    {
        $to = trim((string) ($settings['alert_email'] ?? ''));
        if ($to === '') {
            return;
        }
        $html = "<p>📩 <strong>New WhatsApp reply from {$who}</strong></p>"
            . '<blockquote style="border-left:3px solid #25D366;padding-left:10px;color:#333">'
            . htmlspecialchars($preview) . '</blockquote>'
            . '<p>Open your TravelPilot inbox to reply.</p>';
        EmailService::send($to, "New WhatsApp reply from {$who}", $html, "New reply from {$who}: {$preview}");
    }

    private function sendWhatsApp(int $tenantId, array $settings, string $who, string $preview): void
    {
        $phone = preg_replace('/[^\d+]/', '', (string) ($settings['alert_phone'] ?? ''));
        if ($phone === '') {
            return;
        }

        $wabaModel = new WabaAccountModel();
        $account   = $wabaModel->findActive($tenantId);
        if ($account === null) {
            return;
        }
        $pn = (new PhoneNumberModel())->defaultForTenant($tenantId);
        if ($pn === null) {
            return;
        }
        $client = new CloudApiClient(
            is_array($pn) ? $pn['phone_number_id'] : $pn->phone_number_id,
            $wabaModel->getDecryptedToken($account)
        );

        $template = trim((string) ($settings['alert_template'] ?? ''));
        if ($template !== '') {
            // Reliable path: an approved utility template with 2 body variables.
            $client->sendTemplate($phone, $template, (string) ($settings['alert_template_lang'] ?? 'en'), [[
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $who],
                    ['type' => 'text', 'text' => $preview],
                ],
            ]]);
        } else {
            // Best-effort: only delivers if YOUR number has an open 24h window
            // with the business number (message it once to keep it open).
            $client->sendText($phone, "🔔 New WhatsApp reply from {$who}: {$preview}");
        }
    }

    private function sendPush(int $tenantId, string $who, string $preview, int $convId): void
    {
        (new PushSender())->sendToTenant(
            $tenantId,
            "New reply from {$who}",
            $preview,
            '/#/inbox' . ($convId ? "?c={$convId}" : '')
        );
    }
}
