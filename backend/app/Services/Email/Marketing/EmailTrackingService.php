<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

use App\Models\ActivityModel;

/**
 * Records what recipients do with a campaign email: opens (pixel), clicks
 * (signed redirect) and unsubscribes. Every lookup is by the per-email random
 * token, which also carries the tenant — these endpoints are public.
 *
 * Opens are a soft signal: Apple Mail Privacy Protection pre-fetches every
 * image, and some clients block them. A click implies an open, so a click on
 * an email whose pixel never loaded still counts as opened.
 */
final class EmailTrackingService
{
    public function __construct(
        private readonly SuppressionService $suppressions = new SuppressionService(),
    ) {}

    public function findByToken(string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        return db_connect()->table('emails')
            ->where('tracking_token', $token)
            ->where('deleted_at', null)
            ->get()->getRowArray() ?: null;
    }

    public function recordOpen(string $token): bool
    {
        $email = $this->findByToken($token);
        if ($email === null) {
            return false;
        }
        $this->markOpened($email);

        return true;
    }

    /** @return string|null the URL to redirect to, or null when the link is not genuine */
    public function recordClick(string $token, string $url, string $sig): ?string
    {
        if (! preg_match('#^https?://#i', $url) || ! EmailTracking::verify($token, $url, $sig)) {
            return null;
        }

        $email = $this->findByToken($token);
        if ($email === null) {
            // Signed but unknown (row purged): still send the reader on.
            return $url;
        }

        $now = date('Y-m-d H:i:s');
        $db  = db_connect();
        $db->table('emails')->where('id', $email['id'])->set('click_count', 'click_count + 1', false)
            ->set('clicked_at', $email['clicked_at'] ?? $now)
            ->set('updated_at', $now)
            ->update();
        $this->markOpened($email);

        $db->table('email_clicks')->insert([
            'tenant_id'         => (int) $email['tenant_id'],
            'email_id'          => (int) $email['id'],
            'email_campaign_id' => $email['email_campaign_id'] !== null ? (int) $email['email_campaign_id'] : null,
            'contact_id'        => (int) $email['contact_id'],
            'url'               => mb_substr($url, 0, 2048),
            'created_at'        => $now,
        ]);

        return $url;
    }

    /** @return array{ok:bool, email:?string, already:bool} */
    public function unsubscribe(string $token): array
    {
        $email = $this->findByToken($token);
        if ($email === null) {
            return ['ok' => false, 'email' => null, 'already' => false];
        }

        $tenantId = (int) $email['tenant_id'];
        $address  = (string) $email['to_email'];
        $already  = $this->suppressions->isSuppressed($tenantId, $address);

        $this->suppressions->suppress($tenantId, $address, 'unsubscribed', (int) $email['contact_id'], (int) $email['id']);

        if (empty($email['unsubscribed_at'])) {
            db_connect()->table('emails')->where('id', $email['id'])
                ->update(['unsubscribed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        }

        if (! $already) {
            (new ActivityModel())->log($tenantId, 'email', 'contact', (int) $email['contact_id'], [
                'subject' => 'Unsubscribed from marketing email',
                'body'    => "{$address} used the unsubscribe link in \"{$email['subject']}\".",
                'meta'    => ['email_id' => (int) $email['id'], 'email_campaign_id' => $email['email_campaign_id']],
            ]);
        }

        return ['ok' => true, 'email' => $address, 'already' => $already];
    }

    private function markOpened(array $email): void
    {
        $now = date('Y-m-d H:i:s');
        db_connect()->table('emails')->where('id', $email['id'])
            ->set('open_count', 'open_count + 1', false)
            ->set('opened_at', $email['opened_at'] ?? $now)
            ->set('updated_at', $now)
            ->update();
    }
}
