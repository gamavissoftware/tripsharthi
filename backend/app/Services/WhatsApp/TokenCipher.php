<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Encrypt / decrypt WABA access tokens at rest.
 *
 * Uses CI4's built-in Encryption service (AES-256-GCM via OpenSSL).
 * Key comes from encryption.key in .env (run `php spark key:generate`).
 *
 * The raw token MUST NOT:
 *  - be stored anywhere in plaintext
 *  - appear in logs
 *  - be returned in any JSON API response
 *
 * The encrypted value is always base64-encoded for safe DB storage.
 */
class TokenCipher
{
    public static function encrypt(string $rawToken): string
    {
        if ($rawToken === '') {
            return '';
        }
        return base64_encode(
            \Config\Services::encrypter()->encrypt($rawToken)
        );
    }

    public static function decrypt(string $encryptedToken): string
    {
        if ($encryptedToken === '') {
            return '';
        }
        try {
            return \Config\Services::encrypter()->decrypt(
                base64_decode($encryptedToken)
            );
        } catch (\Throwable $e) {
            log_message('error', 'TokenCipher: failed to decrypt token — key mismatch or corrupted value. ' . $e->getMessage());
            return '';
        }
    }
}
