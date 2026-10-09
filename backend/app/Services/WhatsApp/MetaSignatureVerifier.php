<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Shared X-Hub-Signature-256 verification for ALL Meta webhook endpoints.
 *
 * Meta uses the same App Secret for every webhook regardless of product
 * (WhatsApp Business, Facebook Pages / Lead Ads, etc.).
 *
 * Extracted here so WhatsAppWebhookController and MetaLeadWebhookController
 * share the same constant-time verification — no duplication.
 *
 * WEBHOOK_VERIFY_SIGNATURE=false bypasses verification for local curl testing.
 * Logs at alert level when disabled (must never be false in production).
 */
final class MetaSignatureVerifier
{
    /**
     * @param  string  $rawBody    Raw request body (before any JSON parsing).
     * @param  string  $sigHeader  Value of the X-Hub-Signature-256 header.
     * @return bool    true = valid; false = reject.
     */
    public static function verify(string $rawBody, string $sigHeader): bool
    {
        $enabled = filter_var(env('WEBHOOK_VERIFY_SIGNATURE', true), FILTER_VALIDATE_BOOLEAN);

        if (! $enabled) {
            log_message('alert',
                'MetaSignatureVerifier: WEBHOOK_VERIFY_SIGNATURE is disabled — '
                . 'accepting ALL Meta webhook payloads WITHOUT verification. '
                . 'DO NOT use in production.'
            );
            return true;
        }

        $secret = env('META_APP_SECRET', '');
        if (empty($sigHeader) || empty($secret)) {
            log_message('error',
                'MetaSignatureVerifier: missing signature header or META_APP_SECRET not configured.'
            );
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $sigHeader); // constant-time — prevents timing attacks
    }
}
