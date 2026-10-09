<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\EmailModel;
use App\Models\IntegrationModel;
use App\Services\WhatsApp\TokenCipher;
use Config\Services;

/**
 * Email as a CRM channel (Phase L, v1 — outbound only).
 *
 * Sends an email to a contact using the tenant's own SMTP credentials
 * (integrations.type='email_smtp') when configured, else the platform MAIL_*
 * env defaults. Every attempt is recorded in the `emails` table and, on success,
 * mirrored to the contact timeline as an `email` activity.
 *
 * Inbound / 2-way threading is intentionally deferred — the schema's `direction`
 * column is forward-compatible for a future inbound sync.
 *
 * EMAIL_MOCK_MODE=true skips the SMTP transport (local dev / tests) but still
 * logs the row + activity, so the timeline path is exercised without a server.
 */
final class ContactEmailService
{
    /**
     * @return array{success:bool, email_id:?int, error:?string}
     */
    public function sendToContact(int $tenantId, int $contactId, string $subject, string $htmlBody, ?int $actorUserId = null, ?string $attachmentPath = null): array
    {
        $subject = trim($subject);
        if ($subject === '') {
            return ['success' => false, 'email_id' => null, 'error' => 'Subject is required.'];
        }

        $contact = (new ContactModel())->setTenant($tenantId)->find($contactId);
        if (! $contact) {
            return ['success' => false, 'email_id' => null, 'error' => 'Contact not found.'];
        }
        $to = trim((string) ($contact['email'] ?? ''));
        if ($to === '') {
            return ['success' => false, 'email_id' => null, 'error' => 'Contact has no email address.'];
        }

        $cfg = $this->resolveSmtp($tenantId);

        // Transport (skipped in mock mode).
        [$ok, $error] = $this->isMockMode()
            ? [true, null]
            : $this->transport($cfg, $to, $subject, $htmlBody, $attachmentPath);

        // Always log the attempt.
        $emailId = (int) (new EmailModel())->setTenant($tenantId)->insert([
            'contact_id' => $contactId,
            'direction'  => 'out',
            'from_email' => $cfg['from_email'],
            'to_email'   => $to,
            'subject'    => $subject,
            'body'       => $htmlBody,
            'status'     => $ok ? 'sent' : 'failed',
            'error'      => $ok ? null : mb_substr((string) $error, 0, 500),
            'sent_by'    => $actorUserId,
        ], true);

        // Mirror to the contact timeline only on success.
        if ($ok) {
            (new ActivityModel())->log($tenantId, 'email', 'contact', $contactId, [
                'subject'       => $subject,
                'body'          => mb_substr(strip_tags($htmlBody), 0, 2000),
                'actor_user_id' => $actorUserId,
                'meta'          => ['email_id' => $emailId, 'to' => $to],
            ]);
        }

        return ['success' => $ok, 'email_id' => $emailId, 'error' => $ok ? null : $error];
    }

    /**
     * Resolve SMTP settings: the tenant's own integration if present, else the
     * platform MAIL_* env defaults.
     *
     * @return array{host:string, port:int, user:string, pass:string, crypto:string, from_email:string, from_name:string, tenant:bool}
     */
    public function resolveSmtp(int $tenantId): array
    {
        $envPort = (int) env('MAIL_PORT', 1025);
        $defaults = [
            'host'       => (string) env('MAIL_HOST', 'localhost'),
            'port'       => $envPort,
            'user'       => (string) env('MAIL_USERNAME', ''),
            'pass'       => (string) env('MAIL_PASSWORD', ''),
            'crypto'     => (string) env('MAIL_CRYPTO', $envPort === 465 ? 'ssl' : 'tls'),
            'from_email' => (string) env('MAIL_FROM_ADDRESS', 'noreply@travelpilot.app'),
            'from_name'  => (string) env('MAIL_FROM_NAME', 'TravelPilot'),
            'tenant'     => false,
        ];

        $integration = (new IntegrationModel())->findActiveByType($tenantId, 'email_smtp');
        if ($integration === null) {
            return $defaults;
        }
        $c = json_decode($integration['config'] ?? '{}', true) ?: [];
        if (empty($c['host'])) {
            return $defaults;
        }

        $port = (int) ($c['port'] ?? 587);
        return [
            'host'       => (string) $c['host'],
            'port'       => $port,
            'user'       => (string) ($c['username'] ?? ''),
            'pass'       => ! empty($c['password_enc']) ? TokenCipher::decrypt($c['password_enc']) : '',
            'crypto'     => (string) ($c['crypto'] ?? ($port === 465 ? 'ssl' : 'tls')),
            'from_email' => (string) ($c['from_email'] ?? $defaults['from_email']),
            'from_name'  => (string) ($c['from_name'] ?? $defaults['from_name']),
            'tenant'     => true,
        ];
    }

    /** @return array{0:bool, 1:?string} [ok, error] */
    private function transport(array $cfg, string $to, string $subject, string $htmlBody, ?string $attachmentPath = null): array
    {
        try {
            $email = Services::email();
            $email->initialize([
                'protocol'    => 'smtp',
                'SMTPHost'    => $cfg['host'],
                'SMTPPort'    => $cfg['port'],
                'SMTPUser'    => $cfg['user'],
                'SMTPPass'    => $cfg['pass'],
                'SMTPCrypto'  => $cfg['crypto'],
                'SMTPTimeout' => 15,
                'mailType'    => 'html',
                'charset'     => 'utf-8',
                'newline'     => "\r\n",
            ]);
            $email->setFrom($cfg['from_email'], $cfg['from_name']);
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage($htmlBody);
            if ($attachmentPath !== null && is_file($attachmentPath)) {
                $email->attach($attachmentPath);
            }

            if (! $email->send(false)) {
                return [false, 'SMTP send failed.'];
            }
            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    public function isMockMode(): bool
    {
        return filter_var(env('EMAIL_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }
}
