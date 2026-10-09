<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

use App\Models\ContactFieldValueModel;
use App\Models\IntegrationModel;
use App\Services\Email\ContactEmailService;
use App\Services\Email\Marketing\Transport\MailTransport;
use App\Services\Email\Marketing\Transport\MockTransport;
use App\Services\Email\Marketing\Transport\SmtpTransport;

/**
 * Everything a marketing send needs that is not the audience: the tenant's
 * sending settings, the transport, and the per-recipient rendering. Shared by
 * the campaign batch sender, the test send and the flow `send_email` node so
 * all three produce byte-identical mail.
 */
final class EmailComposer
{
    public const DEFAULT_RATE = 50;
    public const MAX_RATE     = 500;

    public function __construct(
        private readonly EmailPersonalizer $personalizer = new EmailPersonalizer(),
    ) {}

    public static function isMockMode(): bool
    {
        return filter_var(env('EMAIL_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function defaultTransport(): MailTransport
    {
        return self::isMockMode() ? new MockTransport() : new SmtpTransport();
    }

    /**
     * Sending settings. Bulk email only ever goes out through the TENANT'S OWN
     * SMTP — the same bring-your-own rule as the WABA (§1). The platform MAIL_*
     * account exists for password resets; one customer's newsletter must never
     * be able to burn its reputation for everybody else.
     *
     * daily_limit caps sends in any rolling 24 hours (0 = no cap). Gmail and
     * Workspace suspend an account that goes over its quota, so the sender
     * waits for the window to roll rather than letting SMTP start refusing.
     *
     * copy_to: an address that receives its own copy of every marketing email
     * (see sendCopy). Each copy is a second message through the same SMTP
     * account, so with copies on every recipient costs two against the limit.
     *
     * @return array{ready:bool, reason:?string, smtp:array, rate_per_minute:int, daily_limit:int, copy_to:string, notify_to:string, footer_text:string}
     */
    public function settings(int $tenantId): array
    {
        $smtp = (new ContactEmailService())->resolveSmtp($tenantId);

        $integration = (new IntegrationModel())->findActiveByType($tenantId, 'email_smtp');
        $cfg         = $integration ? (json_decode($integration['config'] ?? '{}', true) ?: []) : [];
        $rate        = (int) ($cfg['rate_per_minute'] ?? self::DEFAULT_RATE);

        $ready  = $smtp['tenant'] || self::isMockMode();
        $reason = $ready ? null : 'Connect your own SMTP server in Email → Settings before sending campaigns.';

        return [
            'ready'           => $ready,
            'reason'          => $reason,
            'smtp'            => $smtp,
            'rate_per_minute' => max(1, min(self::MAX_RATE, $rate > 0 ? $rate : self::DEFAULT_RATE)),
            'daily_limit'     => max(0, (int) ($cfg['daily_limit'] ?? 0)),
            'copy_to'         => filter_var(trim((string) ($cfg['copy_to'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
            // Where reply alerts and the daily email report go ('' = off).
            'notify_to'       => filter_var(trim((string) ($cfg['notify_to'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
            'footer_text'     => trim((string) ($cfg['footer_text'] ?? '')),
        ];
    }

    /**
     * Personalise + track one email for one contact.
     *
     * @param  string|null $token null = untracked (test sends, previews)
     * @return array{subject:string, html:string, text:string, headers:array<string,string>}
     */
    public function compose(array $contact, string $subject, string $html, string $preheader, ?string $token, string $footerText = ''): array
    {
        $custom = isset($contact['id']) && (int) $contact['id'] > 0
            ? (new ContactFieldValueModel())->getForContact((int) $contact['id'], false)
            : [];

        $subject   = trim($this->personalizer->render($subject, $contact, $custom, false));
        $preheader = $this->personalizer->render($preheader, $contact, $custom, false);
        $body      = $this->personalizer->render($html, $contact, $custom, true);
        $body      = EmailTracking::prepare($body, $token, $preheader, $footerText);

        return [
            'subject' => $subject,
            'html'    => $body,
            'text'    => EmailTracking::toText($body),
            'headers' => $token !== null ? EmailTracking::listUnsubscribeHeaders($token) : [],
        ];
    }

    /** Emails that left this tenant's SMTP in the last 24 hours (campaigns and flows alike). */
    public static function sentInLast24h(int $tenantId, ?int $nowTs = null): int
    {
        return db_connect()->table('emails')
            ->where('tenant_id', $tenantId)
            ->where('direction', 'out')
            ->where('status', 'sent')
            ->where('sent_at >=', date('Y-m-d H:i:s', ($nowTs ?? time()) - 86400))
            ->countAllResults();
    }

    /** SMTP messages used in the last 24h: each sent email, plus its copy when copies are on. */
    public static function usedInLast24h(int $tenantId, array $settings, ?int $nowTs = null): int
    {
        return self::sentInLast24h($tenantId, $nowTs) * ($settings['copy_to'] !== '' ? 2 : 1);
    }

    /**
     * The owner's copy of a marketing email. Deliberately NOT a BCC: a BCC is
     * the same message, so its open pixel and tracked links belong to the
     * recipient — every time the owner read a copy it would count as the
     * customer opening it. The copy is rendered untracked (no pixel, links
     * untouched, unsubscribe inert) and names the recipient in the subject.
     * A failed copy is logged and never affects the real send.
     */
    public function sendCopy(MailTransport $transport, array $settings, array $contact, string $recipient, string $subject, string $html, string $preheader, ?string $fromName = null): void
    {
        if ($settings['copy_to'] === '') {
            return;
        }
        $copy = $this->compose($contact, $subject, $html, $preheader, null, $settings['footer_text']);
        $name = trim((string) ($contact['name'] ?? ''));
        $to   = $name !== '' ? "{$name} <{$recipient}>" : $recipient;

        [$ok, $error] = $transport->send(
            $settings['smtp'],
            $settings['copy_to'],
            mb_substr("[Copy → {$to}] {$copy['subject']}", 0, 250),
            $copy['html'],
            $copy['text'],
            [],
            null,
            $fromName,
        );
        if (! $ok) {
            log_message('warning', "[email] copy to {$settings['copy_to']} for {$recipient} failed: {$error}");
        }
    }
}
