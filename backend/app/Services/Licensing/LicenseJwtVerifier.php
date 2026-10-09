<?php

declare(strict_types=1);

namespace App\Services\Licensing;

/**
 * Offline-verifiable license key signing and verification.
 *
 * Key format (lightweight JWT-compatible):
 *   base64url(JSON payload) . "." . base64url(HMAC-SHA256(payload_part, secret))
 *
 * The HMAC secret (GAMAVIS_LICENSE_HMAC_KEY) is set during installation as part
 * of the self-hosted package delivery.  It is known to the customer because they
 * have the source code — this is inherent to source-code products.  The security
 * guarantee is that the customer cannot forge a valid key that Gamavis's license
 * server would accept on phone-home; the HMAC prevents field tampering in transit.
 *
 * LICENSE_MOCK_MODE=true bypasses both signature check and payload parsing,
 * returning true/empty-array respectively — for local testing only.
 */
class LicenseJwtVerifier
{
    private const HMAC_ENV_KEY = 'GAMAVIS_LICENSE_HMAC_KEY';

    public function verify(string $rawKey): bool
    {
        if (filter_var(env('LICENSE_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $parts = explode('.', $rawKey, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$payloadB64, $sigB64] = $parts;
        $secret = env(self::HMAC_ENV_KEY, '');

        if (empty($secret)) {
            log_message('error',
                'LicenseJwtVerifier: ' . self::HMAC_ENV_KEY . ' is not set. '
                . 'Set it to the value provided with your self-hosted package.'
            );
            return false;
        }

        $expected = $this->base64urlEncode(hash_hmac('sha256', $payloadB64, $secret, true));
        return hash_equals($expected, $sigB64);
    }

    /** Decode the payload part — does NOT verify the signature. Call verify() first. */
    public function decode(string $rawKey): array
    {
        $parts = explode('.', $rawKey, 2);
        if (count($parts) < 1 || $parts[0] === '') {
            return [];
        }
        $json = $this->base64urlDecode($parts[0]);
        return json_decode($json, true) ?? [];
    }

    /** Generate a signed key — used by the Gamavis license server, included here for completeness. */
    public static function sign(array $payload, string $secret): string
    {
        $payloadB64 = self::staticBase64urlEncode(json_encode($payload));
        $sig        = self::staticBase64urlEncode(hash_hmac('sha256', $payloadB64, $secret, true));
        return $payloadB64 . '.' . $sig;
    }

    // ------------------------------------------------------------------

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        $pad  = (4 - strlen($data) % 4) % 4;
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad));
    }

    private static function staticBase64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
