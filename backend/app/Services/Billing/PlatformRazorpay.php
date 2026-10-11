<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Services\Admin\PlatformSettings;
use App\Services\Crm\AuditLogger;
use App\Services\WhatsApp\TokenCipher;

/**
 * TripSarthi's OWN Razorpay account (what customers pay their subscription into).
 *
 * Credentials can be set from the admin app (Payments) and are then kept ENCRYPTED in `platform_settings`
 * (the secret and the webhook secret never leave the server again: the API only says "set" / "not set").
 * When nothing is saved there, the old RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET / RAZORPAY_WEBHOOK_SECRET
 * environment values are used, so existing installs keep working.
 *
 * Every read is fail-safe: a database or decryption problem falls back to the environment, never throws,
 * so a settings problem can't take checkout down.
 */
final class PlatformRazorpay
{
    public const K_ID = 'razorpay_key_id';
    public const K_SECRET = 'razorpay_key_secret_enc';
    public const K_WEBHOOK = 'razorpay_webhook_secret_enc';
    public const K_AT = 'razorpay_changed_at';

    /** Test seam: fn(string $keyId, string $secret): array{code:int, body:array} - GET /v1/orders?count=1. */
    public static $probe = null;

    public static function keyId(): string
    {
        $v = self::stored(self::K_ID);
        return $v !== '' ? $v : (string) env('RAZORPAY_KEY_ID', '');
    }

    public static function keySecret(): string
    {
        $v = self::storedSecret(self::K_SECRET);
        return $v !== '' ? $v : (string) env('RAZORPAY_KEY_SECRET', '');
    }

    public static function webhookSecret(): string
    {
        $v = self::storedSecret(self::K_WEBHOOK);
        return $v !== '' ? $v : (string) env('RAZORPAY_WEBHOOK_SECRET', '');
    }

    /** What the admin page shows. Never contains a secret. */
    public static function status(): array
    {
        $id = self::keyId();
        $fromAdmin = self::stored(self::K_ID) !== '';
        $secretSet = self::keySecret() !== '';
        $mode = str_starts_with($id, 'rzp_live_') ? 'live' : (str_starts_with($id, 'rzp_test_') ? 'test' : 'none');
        $mock = filter_var(env('RAZORPAY_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
        $warnings = [];
        if ($mock) { $warnings[] = 'Mock mode is ON in the server settings (RAZORPAY_MOCK_MODE): payments are simulated and no real money moves. Switch it off before taking real payments.'; }
        if ($id !== '' && ! $secretSet) { $warnings[] = 'A Key ID is saved but the Key Secret is missing - checkout will fail.'; }
        if (self::webhookSecret() === '') { $warnings[] = 'No webhook secret yet: if a customer pays and closes the page before checkout finishes, the plan will not open automatically. Add the webhook below.'; }
        return [
            'configured'     => $id !== '' && $secretSet,
            'source'         => $id === '' ? 'none' : ($fromAdmin ? 'admin' : 'server'),
            'key_id'         => $id,
            'mode'           => $mode,
            'secret_set'     => $secretSet,
            'webhook_set'    => self::webhookSecret() !== '',
            'mock'           => $mock,
            'webhook_url'    => rtrim((string) (env('APP_URL', '') ?: 'https://app.tripsarthi.com'), '/') . '/webhooks/razorpay',
            'changed_at'     => self::stored(self::K_AT) ?: null,
            'warnings'       => $warnings,
        ];
    }

    /**
     * Save new credentials. A blank secret / webhook secret keeps the stored one (they are write-only in the UI).
     * With $verify the key pair is proved against Razorpay before anything is stored.
     *
     * @throws \InvalidArgumentException with a plain-words message
     */
    public static function save(string $keyId, string $secret, string $webhook, int $adminId, bool $verify = true): array
    {
        $keyId = trim($keyId); $secret = trim($secret); $webhook = trim($webhook);
        if (! preg_match('/^rzp_(live|test)_[A-Za-z0-9]{6,40}$/', $keyId)) {
            throw new \InvalidArgumentException('That doesn\'t look like a Razorpay Key ID. It starts with rzp_live_ or rzp_test_.');
        }
        $changedId = $keyId !== self::stored(self::K_ID) && $keyId !== (string) env('RAZORPAY_KEY_ID', '');
        if ($secret === '' && ($changedId || self::keySecret() === '')) {
            throw new \InvalidArgumentException('Enter the Key Secret that goes with this Key ID.');
        }
        if ($secret !== '' && (strlen($secret) < 10 || strlen($secret) > 100 || preg_match('/\s/', $secret))) {
            throw new \InvalidArgumentException('That Key Secret doesn\'t look right (no spaces, at least 10 characters).');
        }
        if ($webhook !== '' && (strlen($webhook) < 8 || strlen($webhook) > 200 || preg_match('/\s/', $webhook))) {
            throw new \InvalidArgumentException('The webhook secret must be 8-200 characters with no spaces.');
        }
        $effectiveSecret = $secret !== '' ? $secret : self::keySecret();

        if ($verify) { self::prove($keyId, $effectiveSecret); }

        $before = self::status();
        PlatformSettings::set(self::K_ID, $keyId, $adminId);
        if ($secret !== '')  { PlatformSettings::set(self::K_SECRET, TokenCipher::encrypt($secret), $adminId); }
        if ($webhook !== '') { PlatformSettings::set(self::K_WEBHOOK, TokenCipher::encrypt($webhook), $adminId); }
        PlatformSettings::set(self::K_AT, gmdate('Y-m-d H:i:s'), $adminId);

        $after = self::status();
        // Secrets are never written to the audit log - only that they changed.
        self::audit('admin.razorpay.save', ['key_id' => $before['key_id'], 'mode' => $before['mode']],
            ['key_id' => $after['key_id'], 'mode' => $after['mode'], 'secret_changed' => $secret !== '', 'webhook_changed' => $webhook !== ''], $adminId);
        return $after;
    }

    /** Remove what was saved in the admin app; the server's environment values (if any) apply again. */
    public static function clear(int $adminId): array
    {
        $before = self::status();
        foreach ([self::K_ID, self::K_SECRET, self::K_WEBHOOK, self::K_AT] as $k) { PlatformSettings::set($k, null, $adminId); }
        self::audit('admin.razorpay.clear', ['key_id' => $before['key_id'], 'mode' => $before['mode']], null, $adminId);
        return self::status();
    }

    /** Check the CURRENT credentials against Razorpay without changing anything. */
    public static function test(): array
    {
        $id = self::keyId(); $secret = self::keySecret();
        if ($id === '' || $secret === '') { throw new \InvalidArgumentException('No Razorpay keys are saved yet.'); }
        self::prove($id, $secret);
        return ['ok' => true, 'mode' => str_starts_with($id, 'rzp_live_') ? 'live' : 'test'];
    }

    // ---------------------------------------------------------------------------------------------------------------

    /** A read-only call: it only succeeds when Razorpay accepts the key pair. */
    private static function prove(string $keyId, string $secret): void
    {
        $r = self::$probe !== null ? (self::$probe)($keyId, $secret) : self::httpProbe($keyId, $secret);
        $code = (int) ($r['code'] ?? 0);
        if ($code === 401) { throw new \InvalidArgumentException('Razorpay did not accept this Key ID / Key Secret pair. Check both and that they are from the same mode (live or test).'); }
        if ($code === 0)   { throw new \InvalidArgumentException('Could not reach Razorpay to check the keys: ' . (string) ($r['error'] ?? 'network error') . '. Nothing was saved.'); }
        if ($code >= 400)  { throw new \InvalidArgumentException('Razorpay answered ' . $code . ': ' . (string) ($r['body']['error']['description'] ?? 'the account may not be activated for this mode yet') . '. Nothing was saved.'); }
    }

    private static function httpProbe(string $keyId, string $secret): array
    {
        $ch = curl_init('https://api.razorpay.com/v1/orders?count=1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERPWD => $keyId . ':' . $secret, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'error' => $err, 'body' => is_string($resp) ? (json_decode($resp, true) ?: []) : []];
    }

    private static function stored(string $key): string
    {
        try { return trim((string) PlatformSettings::get($key, '')); } catch (\Throwable) { return ''; }
    }

    private static function storedSecret(string $key): string
    {
        $enc = self::stored($key);
        if ($enc === '') { return ''; }
        try { return TokenCipher::decrypt($enc); }
        catch (\Throwable $e) { log_message('error', 'PlatformRazorpay: could not decrypt ' . $key . ' - falling back to server settings.'); return ''; }
    }

    private static function audit(string $action, ?array $before, ?array $after, int $adminId): void
    {
        try {
            $r = db_connect()->table('users')->select('tenant_id')->where('id', $adminId)->get()->getRowArray();
            AuditLogger::log($action, 'platform', 0, $before, $after, $r ? (int) $r['tenant_id'] : null, $adminId);
        } catch (\Throwable $e) { log_message('error', 'PlatformRazorpay audit failed: ' . $e->getMessage()); }
    }
}
