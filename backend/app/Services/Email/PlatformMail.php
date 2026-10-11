<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Services\Admin\PlatformSettings;
use App\Services\Crm\AuditLogger;
use App\Services\WhatsApp\TokenCipher;

/**
 * The mail server TripSarthi's OWN e-mails go through (support replies, plan reminders, password resets, marketing, and the platform
 * fallback for workspaces without their own SMTP). Set from the admin app (Mail server): the password is kept ENCRYPTED in
 * `platform_settings` and never returned again. Nothing saved there = the MAIL_* values in `.env` apply, so old installs keep working.
 * Saving first sends a real test e-mail to the admin with the NEW settings; if that fails, nothing is stored.
 * Every read is fail-safe: a database/decryption problem falls back to the environment, never throws.
 */
final class PlatformMail
{
    public const K = ['host' => 'mail_host', 'port' => 'mail_port', 'crypto' => 'mail_crypto', 'user' => 'mail_user', 'pass' => 'mail_pass_enc',
                      'from_email' => 'mail_from_address', 'from_name' => 'mail_from_name', 'at' => 'mail_changed_at'];

    /** Test seam: fn(array $cfg, string $to): array{ok:bool, error?:string} */
    public static $probe = null;

    private static function stored(string $key): string
    {
        try { return trim((string) PlatformSettings::get($key, '')); } catch (\Throwable) { return ''; }
    }

    private static function storedPass(): string
    {
        $enc = self::stored(self::K['pass']);
        if ($enc === '') { return ''; }
        try { return TokenCipher::decrypt($enc); }
        catch (\Throwable) { log_message('error', 'PlatformMail: could not decrypt the saved mail password - using the server settings.'); return ''; }
    }

    private static function fromAdmin(): bool { return self::stored(self::K['host']) !== ''; }

    /** @return array{host:string,port:int,user:string,pass:string,crypto:string,from_email:string,from_name:string} */
    public static function smtp(): array
    {
        if (self::fromAdmin()) {
            $port = (int) (self::stored(self::K['port']) ?: 587);
            $pass = self::storedPass();
            if ($pass !== '' || self::stored(self::K['user']) === '') {
                return ['host' => self::stored(self::K['host']), 'port' => $port, 'user' => self::stored(self::K['user']), 'pass' => $pass,
                    'crypto' => self::stored(self::K['crypto']) ?: ($port === 465 ? 'ssl' : 'tls'),
                    'from_email' => self::stored(self::K['from_email']) ?: (string) env('MAIL_FROM_ADDRESS', 'noreply@travelpilot.app'),
                    'from_name' => self::stored(self::K['from_name']) ?: (string) env('MAIL_FROM_NAME', 'TripSarthi')];
            }
        }
        $port = (int) env('MAIL_PORT', 1025);
        return ['host' => (string) env('MAIL_HOST', 'localhost'), 'port' => $port, 'user' => (string) env('MAIL_USERNAME', ''), 'pass' => (string) env('MAIL_PASSWORD', ''),
            'crypto' => (string) env('MAIL_CRYPTO', $port === 465 ? 'ssl' : 'tls'),
            'from_email' => (string) env('MAIL_FROM_ADDRESS', 'noreply@travelpilot.app'), 'from_name' => (string) env('MAIL_FROM_NAME', 'TripSarthi')];
    }

    /** What the admin page shows. Never contains the password. */
    public static function status(): array
    {
        $c = self::smtp(); $admin = self::fromAdmin();
        $local = in_array(strtolower($c['host']), ['localhost', '127.0.0.1', '::1'], true);
        $warnings = [];
        if ($local) { $warnings[] = 'No real mail server is set: e-mails (support replies, plan reminders, password resets, campaigns) cannot be delivered.'; }
        if (str_contains(strtolower($c['host']), 'gmail.com')) {
            $warnings[] = 'Gmail allows about 500 e-mails a day and may mark bulk mail as spam. Fine to start; for large campaigns use a bulk provider (Amazon SES, Brevo…) with an address at your own domain.';
            if ($c['from_email'] !== '' && strcasecmp($c['from_email'], $c['user']) !== 0) { $warnings[] = 'Gmail replaces the "from" address with ' . $c['user'] . ' unless you add the other address as an alias in Gmail.'; }
        }
        return ['configured' => ! $local, 'source' => $admin ? 'admin' : ($local ? 'none' : 'server'), 'host' => $c['host'], 'port' => $c['port'], 'crypto' => $c['crypto'], 'user' => $c['user'],
            'pass_set' => $c['pass'] !== '', 'from_email' => $c['from_email'], 'from_name' => $c['from_name'], 'changed_at' => self::stored(self::K['at']) ?: null, 'warnings' => $warnings];
    }

    /** @throws \InvalidArgumentException plain-words message */
    public static function save(array $in, int $adminId, string $testTo, bool $verify = true): array
    {
        $host = strtolower(trim((string) ($in['host'] ?? ''))); $port = (int) ($in['port'] ?? 0); $crypto = strtolower(trim((string) ($in['crypto'] ?? 'tls')));
        $user = trim((string) ($in['user'] ?? '')); $pass = (string) ($in['pass'] ?? ''); $fromEmail = trim((string) ($in['from_email'] ?? '')); $fromName = trim(mb_substr((string) ($in['from_name'] ?? ''), 0, 60));
        if (! preg_match('/^(?=.{3,190}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host)) { throw new \InvalidArgumentException('Enter the mail server address, for example smtp.gmail.com.'); }
        if ($port < 1 || $port > 65535) { throw new \InvalidArgumentException('Enter the port (587 for TLS, 465 for SSL).'); }
        if (! in_array($crypto, ['tls', 'ssl', 'none'], true)) { throw new \InvalidArgumentException('Choose TLS, SSL or none.'); }
        if (mb_strlen($user) > 190 || preg_match('/[\r\n]/', $user)) { throw new \InvalidArgumentException('That username is not valid.'); }
        if (! filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('Enter the e-mail address the messages should come from.'); }
        if ($pass === '') {
            $keep = self::fromAdmin() ? self::storedPass() : '';
            if ($user !== '' && $keep === '') { throw new \InvalidArgumentException('Enter the password (for Gmail: an App Password) for this username.'); }
            $pass = $keep;
        } elseif (strlen($pass) > 200 || preg_match('/[\r\n]/', $pass)) { throw new \InvalidArgumentException('That password does not look right.'); }
        $pass = preg_replace('/\s+/', '', $pass) ?? $pass;   // Google shows App Passwords in groups of four letters with spaces

        $cfg = ['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass, 'crypto' => $crypto === 'none' ? '' : $crypto, 'from_email' => $fromEmail, 'from_name' => $fromName ?: 'TripSarthi'];
        if ($verify) {
            $r = self::$probe !== null ? (self::$probe)($cfg, $testTo) : self::realProbe($cfg, $testTo);
            if (empty($r['ok'])) { throw new \InvalidArgumentException('The mail server did not accept these settings, so nothing was saved. ' . trim((string) ($r['error'] ?? ''))); }
        }
        $before = self::status();
        PlatformSettings::set(self::K['host'], $host, $adminId); PlatformSettings::set(self::K['port'], (string) $port, $adminId); PlatformSettings::set(self::K['crypto'], $crypto, $adminId);
        PlatformSettings::set(self::K['user'], $user, $adminId); PlatformSettings::set(self::K['from_email'], $fromEmail, $adminId); PlatformSettings::set(self::K['from_name'], $fromName, $adminId);
        if ($pass !== '') { PlatformSettings::set(self::K['pass'], TokenCipher::encrypt($pass), $adminId); }
        PlatformSettings::set(self::K['at'], gmdate('Y-m-d H:i:s'), $adminId);
        self::audit('admin.mail.save', ['host' => $before['host'], 'user' => $before['user']], ['host' => $host, 'port' => $port, 'user' => $user, 'from' => $fromEmail, 'password_changed' => ((string) ($in['pass'] ?? '')) !== ''], $adminId);
        return self::status();
    }

    public static function clear(int $adminId): array
    {
        $before = self::status();
        foreach (self::K as $k) { PlatformSettings::set($k, null, $adminId); }
        self::audit('admin.mail.clear', ['host' => $before['host'], 'user' => $before['user']], null, $adminId);
        return self::status();
    }

    /** Send a test e-mail with the CURRENT settings. @return array{sent_to:string} */
    public static function test(string $to): array
    {
        $r = self::$probe !== null ? (self::$probe)(self::smtp(), $to) : self::realProbe(self::smtp(), $to);
        if (empty($r['ok'])) { throw new \InvalidArgumentException('The test e-mail could not be sent. ' . trim((string) ($r['error'] ?? ''))); }
        return ['sent_to' => $to];
    }

    private static function realProbe(array $cfg, string $to): array
    {
        EmailService::$override = $cfg; EmailService::$lastError = '';
        try {
            $ok = EmailService::send($to, 'TripSarthi mail test', '<p>This is a test e-mail from the TripSarthi admin app. If you can read it, e-mail is set up correctly.</p>', "This is a test e-mail from the TripSarthi admin app. If you can read it, e-mail is set up correctly.");
            return ['ok' => $ok, 'error' => EmailService::$lastError];
        } finally { EmailService::$override = null; }
    }

    private static function audit(string $action, ?array $before, ?array $after, int $adminId): void
    {
        try {
            $r = db_connect()->table('users')->select('tenant_id')->where('id', $adminId)->get()->getRowArray();
            AuditLogger::log($action, 'platform', 0, $before, $after, $r ? (int) $r['tenant_id'] : null, $adminId);
        } catch (\Throwable $e) { log_message('error', 'PlatformMail audit failed: ' . $e->getMessage()); }
    }
}
