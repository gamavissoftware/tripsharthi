<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Services\Marketing\EmailBody;
use Config\Services;

class EmailService
{
    /**
     * Configure and return a fresh CI4 email instance from env vars.
     */
    /** Test/verify seam: mail settings to use instead of the saved ones (see PlatformMail). */
    public static ?array $override = null;
    /** Dev/test seam: when set, send() hands each message to this callable (to, subject, html, text) instead of using SMTP. */
    public static $capture = null;
    /** Short, secret-free reason of the last failed send (shown on the admin Mail server page). */
    public static string $lastError = '';

    private static function config(): array { return self::$override ?? PlatformMail::smtp(); }

    private static function makeEmailInstance(): \CodeIgniter\Email\Email
    {
        $email = Services::email();
        $c = self::config();

        $email->initialize([
            'protocol'  => 'smtp',
            'SMTPHost'  => $c['host'],
            'SMTPPort'  => $c['port'],
            'SMTPUser'  => $c['user'],
            'SMTPPass'  => $c['pass'],
            // 'tls' for port 587, 'ssl' for 465, '' for none. Configurable.
            'SMTPCrypto' => $c['crypto'],
            'SMTPTimeout' => 15,
            'mailType'  => 'html',
            'charset'   => 'utf-8',
            'newline'   => "\r\n",
        ]);

        return $email;
    }

    /** One readable line from the SMTP conversation, with the password masked and tags removed. */
    private static function explain(string $debug, string $pass): string
    {
        $t = trim(preg_replace('/\s+/', ' ', strip_tags($debug)) ?? '');
        if ($pass !== '') { $t = str_replace($pass, '********', $t); }
        if (preg_match('/\b(5\d\d[ -][^<]{5,160})/', $t, $m)) { $t = $m[1]; }
        elseif (stripos($t, 'authenticat') !== false) { $t = 'The username or password was not accepted.'; }
        else { $t = 'Could not reach the mail server (the connection timed out or was refused). Check the address and port. If it still fails, the hosting provider may block that port: try port 465 with SSL.'; }
        return mb_substr($t, 0, 220);
    }

    /**
     * Send an email with the given parameters.
     *
     * @param string $to        Recipient address.
     * @param string $subject   Email subject.
     * @param string $htmlBody  HTML message body.
     * @param string $textBody  Optional plain-text alternative body.
     *
     * @return bool True on success, false on failure.
     */
    /**
     * Public base URL of the customer-facing app, for links inside emails.
     *
     * Order: APP_URL (explicit), then CORS_ORIGIN (already the SPA's origin),
     * then the framework base URL. The previous hardcoded
     * 'http://localhost:5173' fallback silently shipped password-reset and
     * invite links that no real recipient could open.
     */
    public static function appUrl(): string
    {
        foreach ([env('APP_URL'), env('CORS_ORIGIN')] as $candidate) {
            $candidate = trim((string) $candidate);
            // CORS_ORIGIN may hold a comma-separated list — take the first entry.
            $candidate = trim(explode(',', $candidate)[0]);

            if ($candidate !== '' && str_starts_with($candidate, 'http')) {
                return rtrim($candidate, '/');
            }
        }

        return rtrim(base_url(), '/');
    }

    public static function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        ?string $replyTo = null,
        array $headers = [],
        array $attachments = [],      // list of ['filename' => 'x.pdf', 'content' => <bytes>, 'mime' => 'application/pdf']
        ?string $bcc = null
    ): bool {
        if (self::$capture !== null) { (self::$capture)($to, $subject, $htmlBody, $textBody, $attachments, $bcc); return true; }
        try {
            $email = self::makeEmailInstance();

            $cfg         = self::config();
            $fromAddress = $cfg['from_email'];
            $fromName    = $cfg['from_name'];

            $email->setFrom($fromAddress, $fromName);
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage($htmlBody);
            if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $email->setReplyTo($replyTo);
            }

            if ($textBody !== '') {
                $email->setAltMessage($textBody);
            }

            foreach ($headers as $name => $value) {
                $email->setHeader((string) $name, str_replace(["\r", "\n"], '', (string) $value));
            }

            if ($bcc !== null && filter_var($bcc, FILTER_VALIDATE_EMAIL)) { $email->setBCC($bcc); }
            foreach ($attachments as $a) {
                $email->attach((string) $a['content'], 'attachment', (string) $a['filename'], (string) ($a['mime'] ?? 'application/octet-stream'));   // a buffer, not a path: the mime type is what tells CI4 so
            }

            $result = $email->send(false);

            if (! $result) {
                self::$lastError = self::explain($email->printDebugger(['headers']), $cfg['pass']);
                log_message('error', '[EmailService] Send failed to {to}: {debug}', [
                    'to'    => $to,
                    'debug' => $email->printDebugger(['headers', 'subject', 'body']),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            self::$lastError = self::explain($e->getMessage(), '');
            log_message('error', '[EmailService] Exception sending to {to}: {msg}', [
                'to'  => $to,
                'msg' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Run a mail send AFTER the HTTP answer has gone out, so a slow or down mail server never makes sign-up / "forgot password" hang
     * (and the response time no longer reveals whether an address has an account). A failure is logged, never shown to the visitor.
     */
    public static function afterResponse(callable $send): void
    {
        register_shutdown_function(static function () use ($send): void {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            try { $send(); } catch (\Throwable $e) { log_message('error', 'deferred e-mail failed: ' . $e->getMessage()); }
        });
    }

    /** Welcome e-mail for a newly registered workspace owner. */
    public static function sendWelcome(string $to, string $name, string $appUrl): bool
    {
        $first = EmailBody::firstName($name);
        $m = EmailLayout::build([
            'subject' => 'Welcome to TripSarthi', 'preheader' => 'Your workspace is ready. Here is how to get your first booking in.',
            'eyebrow' => 'Welcome aboard', 'title' => "Welcome to TripSarthi, {$first}",
            'blocks' => [
                ['p', 'Your workspace is ready. TripSarthi brings your enquiries, quotes, bookings, payments and WhatsApp conversations into one calm place, so nothing falls through the cracks.'],
                ['steps', [['Add your first enquiry', 'Type it in, or let leads from your website, ads and WhatsApp arrive on their own.'],
                           ['Send a beautiful quote', 'Build an itinerary, add your price and share it in one tap.'],
                           ['Collect payments and confirm', 'Payment links and reminders run on their own once a booking is made.']]],
                ['button', 'Open my workspace', $appUrl],
                ['p', 'Questions? Just reply to this e-mail or use Help & support inside the app. We read everything.'],
            ],
        ]);
        return self::send($to, 'Welcome to TripSarthi, ' . $first, $m['html'], $m['text']);
    }

    /** Password-reset e-mail (the link is valid for 1 hour). */
    public static function sendPasswordReset(string $to, string $name, string $resetUrl): bool
    {
        $first = EmailBody::firstName($name);
        $m = EmailLayout::build([
            'subject' => 'Reset your TripSarthi password', 'preheader' => 'Use this link within 1 hour to choose a new password.',
            'eyebrow' => 'Account security', 'title' => 'Reset your password',
            'blocks' => [
                ['p', "Hi {$first}, we received a request to reset the password for your TripSarthi account. Choose a new one with the button below."],
                ['button', 'Choose a new password', $resetUrl],
                ['callout', 'This link is valid for 1 hour and works once.', 'info'],
                ['p', 'If you did not ask for this, you can safely ignore this e-mail. Your password will not change.'],
            ],
        ]);
        return self::send($to, 'Reset your TripSarthi password', $m['html'], $m['text']);
    }

    /** Campaign-completion notification. */
    public static function sendCampaignComplete(string $to, string $name, string $campaignName, int $sent, int $failed): bool
    {
        $first = EmailBody::firstName($name);
        $total = $sent + $failed;
        $rate = $total > 0 ? round(($sent / $total) * 100, 1) : 0.0;
        $facts = [['Campaign', $campaignName], ['Recipients', (string) $total], ['Sent', (string) $sent]];
        if ($failed > 0) { $facts[] = ['Failed', (string) $failed]; }
        $facts[] = ['Success rate', $rate . '%'];
        $m = EmailLayout::build([
            'subject' => "Campaign '{$campaignName}' complete", 'preheader' => "{$sent} of {$total} messages were sent.",
            'eyebrow' => 'Campaign report', 'title' => 'Your campaign has finished',
            'blocks' => [['p', "Hi {$first}, here is how it went."], ['facts', $facts],
                         ['p', 'Open TripSarthi to see the full report and the delivery status of each contact.'], ['button', 'View the report', self::appUrl()]],
        ]);
        return self::send($to, "Campaign '{$campaignName}' complete", $m['html'], $m['text']);
    }

    /** WhatsApp number quality-flag alert to the workspace owner. */
    public static function sendWabaFlagged(string $to, string $name, string $phoneNumber): bool
    {
        $first = EmailBody::firstName($name);
        $m = EmailLayout::build([
            'subject' => 'WhatsApp number quality alert', 'preheader' => "Quality dropped for {$phoneNumber}. Here is what to do.",
            'eyebrow' => 'Needs attention', 'title' => 'WhatsApp quality alert',
            'blocks' => [
                ['p', "Hi {$first}, the quality rating of your WhatsApp number {$phoneNumber} has dropped."],
                ['callout', 'A low rating means people recently blocked or reported messages. If it continues, Meta may limit or disable sending from this number.', 'warn'],
                ['h2', 'What to do now'],
                ['list', ['Review recent campaigns and automations for messages that may feel spammy or irrelevant.', 'Message only people who agreed to receive WhatsApp updates.', 'Lower the sending frequency if you have been running large broadcasts.',
                          'Check WhatsApp Manager for details and any guidance from Meta: https://business.facebook.com/wa/manage/phone-numbers/']],
                ['p', 'Acting early protects your number and keeps your messaging running.'],
            ],
        ]);
        return self::send($to, 'WhatsApp number quality alert', $m['html'], $m['text']);
    }

    /** The test e-mail sent by Admin -> Mail server. */
    public static function sendTest(string $to): bool
    {
        $m = EmailLayout::build([
            'subject' => 'TripSarthi mail test', 'preheader' => 'If you can read this, e-mail is set up correctly.', 'eyebrow' => 'Mail check', 'title' => 'E-mail is working',
            'blocks' => [['p', 'This is a test message from the TripSarthi admin app. If it reached your inbox, the mail server settings are correct.'],
                         ['callout', 'Support replies, plan reminders, password resets and campaigns now go out through this connection.', 'ok']],
        ]);
        return self::send($to, 'TripSarthi mail test', $m['html'], $m['text']);
    }
}
