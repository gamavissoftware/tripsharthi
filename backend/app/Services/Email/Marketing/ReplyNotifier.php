<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

use App\Services\Email\EmailService;
use App\Services\Email\Marketing\Transport\MailTransport;

/**
 * Emails the tenant's notify_to address when a customer replies to a
 * campaign. Reply-To is the customer, so answering the alert answers them.
 * Sent through the tenant's own SMTP; a failure is logged, never thrown —
 * the reply itself is already recorded.
 */
class ReplyNotifier
{
    public function __construct(private readonly ?MailTransport $transport = null) {}

    public function notify(int $tenantId, int $contactId, int $campaignId, string $from, string $fromName, string $subject, string $text, string $receivedAt): bool
    {
        $settings = (new EmailComposer())->settings($tenantId);
        if ($settings['notify_to'] === '' || ! $settings['ready']) {
            return false;
        }

        $db       = db_connect();
        $contact  = $db->table('contacts c')->select('c.id, c.name, c.wa_number, c.email, c.status, a.name AS company')
            ->join('accounts a', 'a.id = c.account_id', 'left')
            ->where('c.id', $contactId)->where('c.tenant_id', $tenantId)->get()->getRowArray() ?? [];
        $campaign = $db->table('email_campaigns')->select('name, subject')->where('id', $campaignId)->where('tenant_id', $tenantId)
            ->get()->getRowArray() ?? [];

        $name    = html_entity_decode(trim((string) ($contact['name'] ?? '')) ?: ($fromName ?: $from), ENT_QUOTES, 'UTF-8');
        $company = html_entity_decode(trim((string) ($contact['company'] ?? '')), ENT_QUOTES, 'UTF-8');
        $wa      = preg_replace('/\D+/', '', (string) ($contact['wa_number'] ?? ''));
        $link    = EmailService::appUrl() . '/#/contacts/' . $contactId;
        $e       = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $when    = date('d M Y, h:i A', strtotime($receivedAt . ' UTC') + 19800) . ' IST';

        $rows = '<tr><td style="padding:3px 12px 3px 0;color:#5B6474;">From</td><td>' . $e($name) . ' &lt;<a href="mailto:' . $e($from) . '">' . $e($from) . '</a>&gt;</td></tr>'
            . ($company !== '' ? '<tr><td style="padding:3px 12px 3px 0;color:#5B6474;">Company</td><td>' . $e($company) . '</td></tr>' : '')
            . ($wa !== '' ? '<tr><td style="padding:3px 12px 3px 0;color:#5B6474;">Phone</td><td><a href="tel:+' . $wa . '">+' . $wa . '</a> · <a href="https://wa.me/' . $wa . '">WhatsApp</a></td></tr>' : '')
            . '<tr><td style="padding:3px 12px 3px 0;color:#5B6474;">Replied to</td><td>' . $e((string) ($campaign['subject'] ?? $subject)) . '</td></tr>'
            . '<tr><td style="padding:3px 12px 3px 0;color:#5B6474;">Received</td><td>' . $e($when) . '</td></tr>';

        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1F2937;max-width:640px;">'
            . '<div style="font-size:18px;font-weight:bold;color:#0B2A5B;margin:0 0 12px;">&#8617; ' . $e($name) . ($company !== '' ? ' (' . $e($company) . ')' : '') . ' replied to your email</div>'
            . '<div style="background:#EEF3FB;border-left:4px solid #1B4596;padding:14px 16px;white-space:pre-wrap;line-height:1.5;margin:0 0 16px;">' . $e($text !== '' ? $text : '(empty reply)') . '</div>'
            . '<table style="font-size:13px;border-collapse:collapse;margin:0 0 16px;">' . $rows . '</table>'
            . '<a href="' . $e($link) . '" style="display:inline-block;background:#1B4596;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:bold;">Open in TravelPilot</a>'
            . '<p style="font-size:12px;color:#5B6474;margin:16px 0 0;">Reply to this email to answer ' . $e($name) . ' directly. They have been taken out of the remaining automatic follow-ups'
            . ($campaign ? ' of "' . $e((string) $campaign['name']) . '"' : '') . '.</p></div>';

        $transport = $this->transport ?? EmailComposer::defaultTransport();
        [$ok, $error] = $transport->send(
            $settings['smtp'],
            $settings['notify_to'],
            mb_substr("↩ {$name}" . ($company !== '' ? " ({$company})" : '') . ' replied: ' . ($subject !== '' ? $subject : '(no subject)'), 0, 250),
            $html,
            "{$name} <{$from}> replied:\n\n{$text}\n\nOpen in TravelPilot: {$link}",
            [],
            $from,
            'TravelPilot',
        );
        if (! $ok) {
            log_message('error', "[email-replies] alert to {$settings['notify_to']} failed: {$error}");
        }

        return $ok;
    }
}
