<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Services\Email\EmailService;

/**
 * The unsubscribe link in every marketing e-mail. The link carries the address and an HMAC of it (keyed by the server's encryption key), so it
 * can't be forged for somebody else's address and needs no stored token. Applying it switches e-mail consent OFF everywhere that address has it
 * (workspace users and partners). Nothing is deleted.
 */
final class EmailUnsubscribe
{
    private static function key(): string
    {
        $k = (string) config('Encryption')->key;
        if ($k === '') { throw new \RuntimeException('No encryption key configured - cannot sign unsubscribe links.'); }
        return $k;
    }

    public static function token(string $email): string { return hash_hmac('sha256', 'unsub|' . strtolower(trim($email)), self::key()); }

    public static function verify(string $email, string $token): bool
    {
        return $email !== '' && $token !== '' && hash_equals(self::token($email), $token);
    }

    public static function url(string $email): string
    {
        $e = rtrim(strtr(base64_encode(strtolower(trim($email))), '+/', '-_'), '=');
        return EmailService::appUrl() . '/api/v1/public/email/unsubscribe?e=' . $e . '&t=' . self::token($email);
    }

    /** Decode the `e` query value back to an address ('' when it is not valid). */
    public static function decode(string $e): string
    {
        $raw = base64_decode(strtr($e, '-_', '+/'), true);
        return ($raw !== false && filter_var($raw, FILTER_VALIDATE_EMAIL)) ? strtolower($raw) : '';
    }

    /** @return int rows switched off */
    public static function apply(string $email): int
    {
        $db = db_connect(); $email = strtolower(trim($email)); $n = 0;
        $db->table('users')->where('LOWER(email)', $email)->where('email_marketing_opt_in', 1)->update(['email_marketing_opt_in' => 0]);
        $n += $db->affectedRows();
        $db->table('partners')->where('LOWER(email)', $email)->where('email_marketing_opt_in', 1)->update(['email_marketing_opt_in' => 0]);
        $n += $db->affectedRows();
        return $n;
    }
}
