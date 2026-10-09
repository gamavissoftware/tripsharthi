<?php

declare(strict_types=1);

namespace App\Services\Commerce;

/**
 * Webhook signature verification for Shopify and WooCommerce.
 *
 * Both platforms sign the raw request body with HMAC-SHA256 and send the
 * digest BASE64-encoded (unlike Meta/Razorpay which use hex). The check is
 * therefore identical for both.
 *
 *   Shopify:     X-Shopify-Hmac-Sha256
 *   WooCommerce: X-WC-Webhook-Signature
 *
 * A bypass flag (ECOMMERCE_VERIFY_SIGNATURE=false) mirrors the discipline of
 * the other verifiers for local testing only.
 */
final class EcommerceSignature
{
    public static function verify(string $rawBody, string $sigHeader, string $secret): bool
    {
        if (! filter_var(env('ECOMMERCE_VERIFY_SIGNATURE', true), FILTER_VALIDATE_BOOLEAN)) {
            log_message('alert', 'EcommerceSignature: verification DISABLED — do not use in production.');
            return true;
        }
        if ($sigHeader === '' || $secret === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        return hash_equals($expected, $sigHeader);
    }
}
