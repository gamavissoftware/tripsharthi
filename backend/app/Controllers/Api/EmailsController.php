<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\EmailModel;
use App\Models\IntegrationModel;
use App\Services\Auth\CurrentUser;
use App\Services\Email\ContactEmailService;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Email as a CRM channel (Phase L, v1 — outbound only). Send an email to a
 * contact (logged + mirrored to the timeline) and read a contact's email log.
 * Per-tenant SMTP config is owner/admin only. Inbound / 2-way is deferred.
 */
class EmailsController extends ResourceController
{
    protected $format = 'json';

    // GET /api/v1/contacts/:id/emails — the contact's outbound email log
    public function forContact($contactId = null): ResponseInterface
    {
        $rows = (new EmailModel())->forContact(CurrentUser::tenantId(), (int) $contactId);
        return $this->respond(['success' => true, 'data' => $rows]);
    }

    // POST /api/v1/contacts/:id/emails  { subject, body }
    public function send($contactId = null): ResponseInterface
    {
        if (! $this->validate(['subject' => 'required|max_length[255]', 'body' => 'required'])) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $result = (new ContactEmailService())->sendToContact(
            CurrentUser::tenantId(),
            (int) $contactId,
            (string) $this->request->getJsonVar('subject'),
            (string) $this->request->getJsonVar('body'),
            CurrentUser::id(),
        );

        if (! $result['success']) {
            return $this->fail(['email' => $result['error'] ?? 'Could not send email.'], 422);
        }
        return $this->respond(['success' => true, 'data' => ['email_id' => $result['email_id']]]);
    }

    // GET /api/v1/email/config — SMTP connection status (owner/admin)
    public function getConfig(): ResponseInterface
    {
        $integration = (new IntegrationModel())->findActiveByType(CurrentUser::tenantId(), 'email_smtp');
        $config      = $integration ? (json_decode($integration['config'] ?? '{}', true) ?: []) : [];
        $inToken     = $integration['verify_token'] ?? '';

        return $this->respond(['success' => true, 'data' => [
            'configured'         => ! empty($config['host']),
            'host'               => $config['host'] ?? '',
            'port'               => $config['port'] ?? 587,
            'username'           => $config['username'] ?? '',
            'from_email'         => $config['from_email'] ?? '',
            'from_name'          => $config['from_name'] ?? '',
            'crypto'             => $config['crypto'] ?? '',
            'has_password'       => ! empty($config['password_enc']),
            // Email marketing: batch size per worker minute, and the footer
            // (company name + postal address) every bulk email carries.
            'rate_per_minute'    => (int) ($config['rate_per_minute'] ?? \App\Services\Email\Marketing\EmailComposer::DEFAULT_RATE),
            'footer_text'        => $config['footer_text'] ?? '',
            'daily_limit'        => (int) ($config['daily_limit'] ?? 0),
            'copy_to'            => $config['copy_to'] ?? '',
            'notify_to'          => $config['notify_to'] ?? '',
            'replies_last_ok'    => $config['replies_last_ok'] ?? null,
            'replies_last_error' => $config['replies_last_error'] ?? null,
            'imap_supported'     => function_exists('imap_open'),
            'platform_fallback'  => env('MAIL_HOST', '') !== '',
            // Point your mail provider's inbound-parse webhook here to log replies.
            'inbound_webhook_path' => $inToken ? "/webhooks/inbound-email/{$inToken}" : '',
        ]]);
    }

    // POST /api/v1/email/config — store per-tenant SMTP credentials (owner/admin)
    public function saveConfig(): ResponseInterface
    {
        $rules = [
            'host'       => 'required|string|max_length[255]',
            'port'       => 'required|is_natural_no_zero',
            'from_email' => 'required|valid_email',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $config = [
            'host'       => trim((string) $this->request->getJsonVar('host')),
            'port'       => (int) $this->request->getJsonVar('port'),
            'username'   => trim((string) ($this->request->getJsonVar('username') ?? '')),
            'from_email' => trim((string) $this->request->getJsonVar('from_email')),
            'from_name'  => trim((string) ($this->request->getJsonVar('from_name') ?? 'TravelPilot')),
            'crypto'     => trim((string) ($this->request->getJsonVar('crypto') ?? 'tls')),
            'rate_per_minute' => max(1, min(
                \App\Services\Email\Marketing\EmailComposer::MAX_RATE,
                (int) ($this->request->getJsonVar('rate_per_minute') ?: \App\Services\Email\Marketing\EmailComposer::DEFAULT_RATE),
            )),
            'footer_text' => mb_substr(trim((string) ($this->request->getJsonVar('footer_text') ?? '')), 0, 500),
            // 0 = no cap. Gmail allows ~500/day, Workspace ~2,000.
            'daily_limit' => max(0, min(100000, (int) ($this->request->getJsonVar('daily_limit') ?? 0))),
            // Owner gets an untracked copy of every marketing email ('' = off).
            'copy_to'     => trim((string) ($this->request->getJsonVar('copy_to') ?? '')),
        ];
        if ($config['copy_to'] !== '' && ! filter_var($config['copy_to'], FILTER_VALIDATE_EMAIL)) {
            return $this->fail(['copy_to' => 'Copy address must be a valid email.'], 422);
        }
        // Read the prior row by the SAME key saveConfig upserts on (tenant+type,
        // any status) — findActiveByType would miss a deactivated/soft-deleted row
        // and we'd drop its password + mint a new inbound token on re-save.
        $prevRow    = db_connect()->table('integrations')
            ->where('tenant_id', CurrentUser::tenantId())->where('type', 'email_smtp')
            ->get()->getRowArray();
        $prevConfig = $prevRow ? (json_decode($prevRow['config'] ?? '{}', true) ?: []) : [];

        // Reply alerts + daily report ('' = off; absent from the request = keep).
        $config['notify_to'] = trim((string) ($this->request->getJsonVar('notify_to') ?? ($prevConfig['notify_to'] ?? '')));
        if ($config['notify_to'] !== '' && ! filter_var($config['notify_to'], FILTER_VALIDATE_EMAIL)) {
            return $this->fail(['notify_to' => 'Alert address must be a valid email.'], 422);
        }

        // Keep settings this form does not manage (report/reply-poll state
        // written by EmailInboxRunner) — dropping report_sent_on would resend
        // today's report.
        $config += array_diff_key($prevConfig, array_flip(['password_enc']));

        // Only overwrite the stored password when a new one is supplied.
        $newPass = (string) ($this->request->getJsonVar('password') ?? '');
        if ($newPass !== '') {
            $config['password_enc'] = TokenCipher::encrypt($newPass);
        } elseif (! empty($prevConfig['password_enc'])) {
            $config['password_enc'] = $prevConfig['password_enc'];
        }

        // Stable per-tenant inbound token (reused on re-save) so the inbound-email
        // webhook URL doesn't change when SMTP settings are updated.
        $token = $prevRow['verify_token'] ?? '';
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        }

        (new IntegrationModel())->saveConfig(CurrentUser::tenantId(), 'email_smtp', [
            'config'       => json_encode($config),
            'verify_token' => $token,
        ]);

        return $this->respond(['success' => true, 'message' => 'SMTP settings saved.']);
    }

    // POST /api/v1/email/config/test  { to? } — send a real message through the
    // saved tenant SMTP, so a wrong password shows up here and not as a paused
    // campaign later. Defaults to the signed-in user's own address.
    public function testConfig(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $user     = (array) (CurrentUser::get() ?? []);
        $to       = trim((string) ($this->request->getJsonVar('to') ?: ($user['email'] ?? '')));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->fail(['to' => 'Enter a valid email address.'], 422);
        }

        $smtp = (new ContactEmailService())->resolveSmtp($tenantId);
        if (! $smtp['tenant']) {
            return $this->fail('Save your SMTP settings first.', 422);
        }

        [$ok, $error] = \App\Services\Email\Marketing\EmailComposer::defaultTransport()->send(
            $smtp,
            $to,
            'TravelPilot SMTP test',
            '<p>Your SMTP settings work. Email campaigns from TravelPilot will be sent through <strong>'
                . esc($smtp['host']) . '</strong> as <strong>' . esc($smtp['from_email']) . '</strong>.</p>',
            'Your SMTP settings work.',
        );

        return $ok
            ? $this->respond(['success' => true, 'message' => "Test email sent to {$to}."])
            : $this->fail(['smtp' => 'SMTP test failed: ' . $error], 422);
    }
}
