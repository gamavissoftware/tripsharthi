<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Razorpay X-Razorpay-Signature verification.
 *
 * ── Format difference from Meta ──────────────────────────────────────────
 * Meta webhooks send:  X-Hub-Signature-256: sha256=<hex>  (prefixed)
 * Razorpay webhooks send: X-Razorpay-Signature: <hex>     (bare hex, no prefix)
 *
 * Both use HMAC-SHA256 and constant-time comparison.  The verification logic
 * is otherwise identical; the bypass flag follows the same discipline:
 *
 *   RAZORPAY_VERIFY_SIGNATURE=false  — log alert + return true (local testing only)
 *   RAZORPAY_WEBHOOK_SECRET not set  — log error + return false (safe-fail)
 *
 * ── Write-only-from-webhook invariant ────────────────────────────────────
 * RazorpayWebhookController calls this BEFORE any DB write.  A rejected
 * signature causes an early 200 return with no side effects.  This is the
 * ONLY gate preventing a forged webhook from changing tenants.plan/status.
 */
final class RazorpaySignatureVerifier
{
    public static function verify(string $rawBody, string $sigHeader): bool
    {
        $enabled = filter_var(env('RAZORPAY_VERIFY_SIGNATURE', true), FILTER_VALIDATE_BOOLEAN);

        if (! $enabled) {
            log_message('alert',
                'RazorpaySignatureVerifier: RAZORPAY_VERIFY_SIGNATURE is disabled — '
                . 'accepting ALL Razorpay webhook payloads WITHOUT verification. '
                . 'DO NOT use in production.'
            );
            return true;
        }

        $secret = env('RAZORPAY_WEBHOOK_SECRET', '');

        if (empty($sigHeader) || empty($secret)) {
            log_message('error',
                'RazorpaySignatureVerifier: missing X-Razorpay-Signature header '
                . 'or RAZORPAY_WEBHOOK_SECRET not configured.'
            );
            return false;
        }

        // Razorpay sends bare HMAC-SHA256 hex — no "sha256=" prefix.
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $sigHeader); // constant-time — prevents timing attacks
    }
}
