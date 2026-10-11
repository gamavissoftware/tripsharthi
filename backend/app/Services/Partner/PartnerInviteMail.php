<?php

declare(strict_types=1);

namespace App\Services\Partner;

use App\Services\Email\EmailLayout;
use App\Services\Email\EmailService;

/**
 * E-mails a partner their one-time set-password link (new invite, or a password reset). The link is a credential, so it goes only to the
 * partner's own login address, is never written to a log, and the e-mail says it is single-use and expires in 7 days.
 * Returns the outcome instead of throwing: the admin screen still shows the link to copy when the mail could not be sent.
 */
final class PartnerInviteMail
{
    /** @param array{name:string,email:string,code?:string,commission_pct?:mixed,commission_months?:mixed} $partner
     *  @param callable|null $mailer fn(string $to, string $subject, string $html, string $text): bool  (test seam)
     *  @return array{ok:bool,error:?string} */
    public static function send(array $partner, string $url, bool $reset, ?callable $mailer = null): array
    {
        $to = trim((string) ($partner['email'] ?? ''));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) { return ['ok' => false, 'error' => 'The partner has no valid e-mail address.']; }
        $name = (string) ($partner['name'] ?? 'there');
        $first = \App\Services\Marketing\EmailBody::firstName($name);
        $code = (string) ($partner['code'] ?? '');
        $pct = isset($partner['commission_pct']) ? rtrim(rtrim(number_format((float) $partner['commission_pct'], 1), '0'), '.') : '';
        $months = (int) ($partner['commission_months'] ?? 0);
        if ($reset) {
            $subject = 'Reset your TripSarthi partner password';
            $m = EmailLayout::build(['subject' => $subject, 'preheader' => 'Choose a new password for your partner account. The link works once and expires in 7 days.',
                'eyebrow' => 'Partner account', 'title' => 'Choose a new password',
                'blocks' => [['p', "Hi {$first}, use the button below to set a new password for your TripSarthi partner account."], ['button', 'Set my new password', $url],
                             ['callout', 'The link works once and expires in 7 days. If you did not ask for this, you can ignore this e-mail; nothing changes until the link is used.', 'info']]]);
        } else {
            $subject = 'Welcome to the TripSarthi partner program';
            $facts = [];
            if ($code !== '') { $facts[] = ['Your referral code', $code]; }
            if ($pct !== '') { $facts[] = ['Your commission', $pct . '% of what each customer pays' . ($months > 0 ? ", for {$months} months" : ', for as long as they stay')]; }
            $blocks = [['p', "Hi {$first}, you have been invited to the TripSarthi referral partner program. Introduce travel businesses to TripSarthi and earn a commission on every subscription payment they make."]];
            if ($facts) { $blocks[] = ['facts', $facts]; }
            $blocks[] = ['h2', 'Getting started takes two minutes'];
            $blocks[] = ['steps', [['Set your password', 'Use the button below to open your partner dashboard.'], ['Share your link', 'Your personal referral link is waiting in the dashboard, ready to copy.'], ['Track and get paid', 'Watch sign-ups and earnings in real time. Payouts are made by bank transfer or UPI.']]];
            $blocks[] = ['button', 'Set my password', $url];
            $blocks[] = ['callout', 'The link works once and expires in 7 days. If you were not expecting this invitation, you can ignore this e-mail.', 'info'];
            $m = EmailLayout::build(['subject' => $subject, 'preheader' => 'Your invitation is ready: set a password and get your referral link.', 'eyebrow' => 'Partner program', 'title' => "You're invited, {$first}", 'blocks' => $blocks]);
        }
        $html = $m['html']; $text = $m['text'];
        EmailService::$lastError = '';
        $ok = $mailer !== null ? (bool) $mailer($to, $subject, $html, $text) : EmailService::send($to, $subject, $html, $text);
        return ['ok' => $ok, 'error' => $ok ? null : (EmailService::$lastError ?: 'The mail server did not accept the message.')];
    }
}
