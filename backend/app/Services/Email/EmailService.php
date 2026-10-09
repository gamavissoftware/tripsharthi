<?php

declare(strict_types=1);

namespace App\Services\Email;

use Config\Services;

class EmailService
{
    /**
     * Configure and return a fresh CI4 email instance from env vars.
     */
    private static function makeEmailInstance(): \CodeIgniter\Email\Email
    {
        $email = Services::email();

        $email->initialize([
            'protocol'  => 'smtp',
            'SMTPHost'  => (string) env('MAIL_HOST', 'localhost'),
            'SMTPPort'  => (int) env('MAIL_PORT', 1025),
            'SMTPUser'  => (string) env('MAIL_USERNAME', ''),
            'SMTPPass'  => (string) env('MAIL_PASSWORD', ''),
            // 'tls' for port 587, 'ssl' for 465, '' for none. Configurable.
            'SMTPCrypto' => (string) env('MAIL_CRYPTO', ((int) env('MAIL_PORT', 1025) === 465 ? 'ssl' : 'tls')),
            'SMTPTimeout' => 15,
            'mailType'  => 'html',
            'charset'   => 'utf-8',
            'newline'   => "\r\n",
        ]);

        return $email;
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
        ?string $replyTo = null
    ): bool {
        try {
            $email = self::makeEmailInstance();

            $fromAddress = (string) env('MAIL_FROM_ADDRESS', 'noreply@travelpilot.app');
            $fromName    = (string) env('MAIL_FROM_NAME', 'TravelPilot');

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

            $result = $email->send(false);

            if (! $result) {
                log_message('error', '[EmailService] Send failed to {to}: {debug}', [
                    'to'    => $to,
                    'debug' => $email->printDebugger(['headers', 'subject', 'body']),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            log_message('error', '[EmailService] Exception sending to {to}: {msg}', [
                'to'  => $to,
                'msg' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a welcome email to a newly registered user.
     *
     * @param string $to     Recipient address.
     * @param string $name   Recipient's display name.
     * @param string $appUrl Base URL of the application.
     *
     * @return bool
     */
    public static function sendWelcome(string $to, string $name, string $appUrl): bool
    {
        $subject = "Welcome to TravelPilot, {$name}!";

        $escapedName   = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $escapedAppUrl = htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Welcome to TravelPilot</title></head>
<body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:24px;">
  <h1 style="color:#1a73e8;">Welcome to TravelPilot, {$escapedName}!</h1>
  <p>We're excited to have you on board. TravelPilot helps you capture, manage and engage your WhatsApp leads — all in one place.</p>
  <p style="margin:32px 0;">
    <a href="{$escapedAppUrl}" style="background:#1a73e8;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;">
      Get Started
    </a>
  </p>
  <p style="color:#666;font-size:13px;">If you have any questions, just reply to this email — we're here to help.</p>
  <hr style="border:none;border-top:1px solid #eee;margin:32px 0;">
  <p style="color:#999;font-size:12px;">TravelPilot by Gamavis Software Solutions</p>
</body>
</html>
HTML;

        $text = "Welcome to TravelPilot, {$name}!\n\n"
            . "We're excited to have you on board.\n\n"
            . "Get started here: {$appUrl}\n\n"
            . "TravelPilot by Gamavis Software Solutions";

        return self::send($to, $subject, $html, $text);
    }

    /**
     * Send a password-reset email.
     *
     * @param string $to       Recipient address.
     * @param string $name     Recipient's display name.
     * @param string $resetUrl Password-reset URL (expires in 1 hour).
     *
     * @return bool
     */
    public static function sendPasswordReset(string $to, string $name, string $resetUrl): bool
    {
        $subject = 'Reset your TravelPilot password';

        $escapedName     = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $escapedResetUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Reset your password</title></head>
<body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:24px;">
  <h1 style="color:#1a73e8;">Reset your password</h1>
  <p>Hi {$escapedName},</p>
  <p>We received a request to reset the password for your TravelPilot account. Click the button below to choose a new password. This link is valid for <strong>1 hour</strong>.</p>
  <p style="margin:32px 0;">
    <a href="{$escapedResetUrl}" style="background:#d93025;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;">
      Reset Password
    </a>
  </p>
  <p>If you didn't request a password reset, you can safely ignore this email — your password won't change.</p>
  <hr style="border:none;border-top:1px solid #eee;margin:32px 0;">
  <p style="color:#999;font-size:12px;">TravelPilot by Gamavis Software Solutions</p>
</body>
</html>
HTML;

        $text = "Hi {$name},\n\n"
            . "We received a request to reset the password for your TravelPilot account.\n\n"
            . "Reset your password (valid for 1 hour): {$resetUrl}\n\n"
            . "If you didn't request this, you can safely ignore this email.\n\n"
            . "TravelPilot by Gamavis Software Solutions";

        return self::send($to, $subject, $html, $text);
    }

    /**
     * Send a campaign-completion notification.
     *
     * @param string $to           Recipient address.
     * @param string $name         Recipient's display name.
     * @param string $campaignName Name of the completed campaign.
     * @param int    $sent         Number of messages successfully sent.
     * @param int    $failed       Number of messages that failed.
     *
     * @return bool
     */
    public static function sendCampaignComplete(
        string $to,
        string $name,
        string $campaignName,
        int $sent,
        int $failed
    ): bool {
        $escapedCampaignName = htmlspecialchars($campaignName, ENT_QUOTES, 'UTF-8');
        $subject             = "Campaign '{$campaignName}' complete";

        $escapedName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $total       = $sent + $failed;
        $successRate = $total > 0 ? round(($sent / $total) * 100, 1) : 0.0;

        $failedRow = $failed > 0
            ? "<tr><td style='padding:8px;border:1px solid #eee;color:#d93025;'>Failed</td><td style='padding:8px;border:1px solid #eee;color:#d93025;'>{$failed}</td></tr>"
            : '';

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Campaign complete</title></head>
<body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:24px;">
  <h1 style="color:#1a73e8;">Campaign Complete</h1>
  <p>Hi {$escapedName},</p>
  <p>Your campaign <strong>{$escapedCampaignName}</strong> has finished sending. Here's a summary:</p>
  <table style="border-collapse:collapse;width:100%;margin:24px 0;">
    <tr style="background:#f5f5f5;">
      <th style="padding:8px;border:1px solid #eee;text-align:left;">Metric</th>
      <th style="padding:8px;border:1px solid #eee;text-align:left;">Count</th>
    </tr>
    <tr>
      <td style="padding:8px;border:1px solid #eee;">Total recipients</td>
      <td style="padding:8px;border:1px solid #eee;">{$total}</td>
    </tr>
    <tr>
      <td style="padding:8px;border:1px solid #eee;color:#188038;">Sent successfully</td>
      <td style="padding:8px;border:1px solid #eee;color:#188038;">{$sent}</td>
    </tr>
    {$failedRow}
    <tr>
      <td style="padding:8px;border:1px solid #eee;">Success rate</td>
      <td style="padding:8px;border:1px solid #eee;">{$successRate}%</td>
    </tr>
  </table>
  <p>Log in to TravelPilot to view the full campaign report and per-contact delivery status.</p>
  <hr style="border:none;border-top:1px solid #eee;margin:32px 0;">
  <p style="color:#999;font-size:12px;">TravelPilot by Gamavis Software Solutions</p>
</body>
</html>
HTML;

        $text = "Hi {$name},\n\n"
            . "Your campaign '{$campaignName}' has finished sending.\n\n"
            . "Total recipients : {$total}\n"
            . "Sent successfully : {$sent}\n"
            . "Failed            : {$failed}\n"
            . "Success rate      : {$successRate}%\n\n"
            . "Log in to TravelPilot to view the full report.\n\n"
            . "TravelPilot by Gamavis Software Solutions";

        return self::send($to, $subject, $html, $text);
    }

    /**
     * Send a WABA quality-flag alert to the account owner.
     *
     * @param string $to          Recipient address.
     * @param string $name        Recipient's display name.
     * @param string $phoneNumber The flagged WhatsApp display number.
     *
     * @return bool
     */
    public static function sendWabaFlagged(string $to, string $name, string $phoneNumber): bool
    {
        $subject = '⚠️ WhatsApp number quality alert';

        $escapedName   = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $escapedPhone  = htmlspecialchars($phoneNumber, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>WhatsApp number quality alert</title></head>
<body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:24px;">
  <h1 style="color:#d93025;">⚠️ WhatsApp Number Quality Alert</h1>
  <p>Hi {$escapedName},</p>
  <p>We've detected a <strong>quality rating drop</strong> for your WhatsApp number <strong>{$escapedPhone}</strong> connected to TravelPilot.</p>
  <p>A low quality rating means that recipients have recently blocked or reported your messages. If this continues, Meta may limit or disable your ability to send messages from this number.</p>
  <h2 style="font-size:16px;color:#333;">Recommended actions</h2>
  <ul>
    <li>Review recent campaigns and flows for messages that may feel spammy or irrelevant.</li>
    <li>Ensure you only contact leads who have opted in to receive WhatsApp messages.</li>
    <li>Reduce sending frequency if you've been running high-volume broadcasts.</li>
    <li>Check the <a href="https://business.facebook.com/wa/manage/phone-numbers/" style="color:#1a73e8;">WhatsApp Manager</a> for details and any Meta guidance.</li>
  </ul>
  <p>Improving your quality rating protects your WABA and keeps your messaging capabilities intact.</p>
  <hr style="border:none;border-top:1px solid #eee;margin:32px 0;">
  <p style="color:#999;font-size:12px;">TravelPilot by Gamavis Software Solutions</p>
</body>
</html>
HTML;

        $text = "Hi {$name},\n\n"
            . "We've detected a quality rating drop for your WhatsApp number {$phoneNumber} connected to TravelPilot.\n\n"
            . "A low quality rating means recipients have recently blocked or reported your messages. "
            . "If this continues, Meta may limit or disable messaging from this number.\n\n"
            . "Recommended actions:\n"
            . "- Review recent campaigns and flows for potentially spammy messages.\n"
            . "- Only contact leads who have opted in.\n"
            . "- Reduce sending frequency if running high-volume broadcasts.\n"
            . "- Check WhatsApp Manager: https://business.facebook.com/wa/manage/phone-numbers/\n\n"
            . "TravelPilot by Gamavis Software Solutions";

        return self::send($to, $subject, $html, $text);
    }
}
