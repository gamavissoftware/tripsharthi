<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing\Transport;

use CodeIgniter\Email\Email;

/**
 * SMTP via CodeIgniter's mailer, keeping the connection open across a batch
 * (SMTPKeepAlive) — reconnecting and re-authenticating for each of fifty
 * recipients is the slow part of bulk SMTP, and some providers rate-limit
 * logins separately from messages.
 */
final class SmtpTransport implements MailTransport
{
    private ?Email $mailer = null;
    private string $connKey = '';

    public function send(array $smtp, string $to, string $subject, string $html, string $text, array $headers = [], ?string $replyTo = null, ?string $fromName = null): array
    {
        try {
            $mailer = $this->mailer($smtp);
            $mailer->clear(true);
            $mailer->setFrom($smtp['from_email'], $fromName !== null && $fromName !== '' ? $fromName : $smtp['from_name']);
            $mailer->setTo($to);
            if ($replyTo !== null && $replyTo !== '') {
                $mailer->setReplyTo($replyTo);
            }
            $mailer->setSubject($subject);
            $mailer->setMessage($html);
            if ($text !== '') {
                $mailer->setAltMessage($text);
            }
            foreach ($headers as $name => $value) {
                $mailer->setHeader($name, $value);
            }

            if (! $mailer->send(false)) {
                $debug = strip_tags((string) $mailer->printDebugger([]));
                $this->mailer = null; // a failed session may be half-open — start clean next time

                return [false, mb_substr(trim($debug) !== '' ? trim($debug) : 'SMTP send failed.', 0, 480)];
            }

            return [true, null];
        } catch (\Throwable $e) {
            $this->mailer = null;

            return [false, mb_substr($e->getMessage(), 0, 480)];
        }
    }

    private function mailer(array $smtp): Email
    {
        $key = $smtp['host'] . ':' . $smtp['port'] . ':' . $smtp['user'];
        if ($this->mailer !== null && $this->connKey === $key) {
            return $this->mailer;
        }

        $mailer = new Email();
        $mailer->initialize([
            'protocol'      => 'smtp',
            'SMTPHost'      => $smtp['host'],
            'SMTPPort'      => (int) $smtp['port'],
            'SMTPUser'      => $smtp['user'],
            'SMTPPass'      => $smtp['pass'],
            'SMTPCrypto'    => $smtp['crypto'],
            'SMTPTimeout'   => 20,
            'SMTPKeepAlive' => true,
            'mailType'      => 'html',
            'charset'       => 'utf-8',
            'newline'       => "\r\n",
            'CRLF'          => "\r\n",
        ]);

        $this->connKey = $key;

        return $this->mailer = $mailer;
    }
}
