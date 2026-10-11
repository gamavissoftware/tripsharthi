<?php

declare(strict_types=1);

namespace App\Services\Partner;

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
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = (string) ($partner['name'] ?? 'there');
        $subject = $reset ? 'Reset your TripSarthi partner password' : 'You are invited to the TripSarthi partner program';
        $intro = $reset ? 'Use the button below to choose a new password for your TripSarthi partner account.'
                        : 'You have been invited to the TripSarthi referral partner program. Choose a password to open your partner dashboard, where you will find your referral link and your earnings.';
        $code = (string) ($partner['code'] ?? '');
        $terms = ($code !== '' && ! $reset) ? '<p style="margin:0 0 14px">Your referral code: <b>' . $h($code) . '</b></p>' : '';
        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.55;color:#0f1f3a;max-width:560px">'
            . '<div style="font-weight:800;font-size:20px;color:#0a1f44;margin-bottom:12px">Trip<span style="color:#12a89e">Sarthi</span></div>'
            . '<p style="margin:0 0 14px">Hi ' . $h($name) . ',</p><p style="margin:0 0 14px">' . $h($intro) . '</p>' . $terms
            . '<p style="margin:0 0 14px"><a href="' . $h($url) . '" style="display:inline-block;background:#0a6cc4;color:#fff;text-decoration:none;padding:11px 18px;border-radius:8px">Set my password</a></p>'
            . '<p style="margin:0 0 6px;color:#53657f;font-size:13px">The link works once and expires in 7 days. If the button does not work, copy this address into your browser:<br><span style="word-break:break-all">' . $h($url) . '</span></p>'
            . '<p style="margin:14px 0 0;color:#53657f;font-size:13px">If you were not expecting this, you can ignore the e-mail - nothing happens until the link is used.<br>TripSarthi by Gamavis Software Solutions</p></div>';
        $text = "Hi {$name},\n\n{$intro}\n\n" . ($code !== '' && ! $reset ? "Your referral code: {$code}\n\n" : '') . "Set your password: {$url}\n\nThe link works once and expires in 7 days. If you were not expecting this e-mail, ignore it.\n\nTripSarthi by Gamavis Software Solutions";
        EmailService::$lastError = '';
        $ok = $mailer !== null ? (bool) $mailer($to, $subject, $html, $text) : EmailService::send($to, $subject, $html, $text);
        return ['ok' => $ok, 'error' => $ok ? null : (EmailService::$lastError ?: 'The mail server did not accept the message.')];
    }
}
