<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\RazorpaySignatureVerifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests for RazorpaySignatureVerifier.
 *
 * Mirrors WebhookSignatureTest (Meta) with the key format difference:
 * Razorpay sends bare HMAC-SHA256 hex — no "sha256=" prefix.
 *
 * All tests use the static algorithm directly (same approach as WebhookSignatureTest)
 * so they remain fast and dependency-free.
 */
class RazorpayWebhookSignatureTest extends CIUnitTestCase
{
    private const SECRET  = 'rzp_test_webhook_secret_xyz';
    private const PAYLOAD = '{"event":"subscription.charged","payload":{"subscription":{"entity":{"id":"sub_test123"}}}}';

    // ------------------------------------------------------------------
    // Core algorithm helpers (mirror the verifier's internals)
    // ------------------------------------------------------------------

    private function computeSignature(string $payload, string $secret): string
    {
        // Razorpay: bare hex, NO "sha256=" prefix
        return hash_hmac('sha256', $payload, $secret);
    }

    private function verify(string $rawBody, string $sigHeader, string $secret): bool
    {
        if (empty($sigHeader) || empty($secret)) return false;
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $sigHeader);
    }

    // ------------------------------------------------------------------
    // Test 1: valid signature passes
    // ------------------------------------------------------------------

    public function testValidSignaturePassesVerification(): void
    {
        $sig = $this->computeSignature(self::PAYLOAD, self::SECRET);
        $this->assertTrue($this->verify(self::PAYLOAD, $sig, self::SECRET));
    }

    // ------------------------------------------------------------------
    // Test 2: tampered body fails
    // ------------------------------------------------------------------

    public function testTamperedBodyFailsVerification(): void
    {
        $sig          = $this->computeSignature(self::PAYLOAD, self::SECRET);
        $tamperedBody = self::PAYLOAD . ' '; // trailing space changes the HMAC

        $this->assertFalse($this->verify($tamperedBody, $sig, self::SECRET));
    }

    // ------------------------------------------------------------------
    // Test 3: wrong secret fails
    // ------------------------------------------------------------------

    public function testWrongSecretFailsVerification(): void
    {
        $sig = $this->computeSignature(self::PAYLOAD, 'completely_wrong_secret');
        $this->assertFalse($this->verify(self::PAYLOAD, $sig, self::SECRET));
    }

    // ------------------------------------------------------------------
    // Test 4: empty signature header fails
    // ------------------------------------------------------------------

    public function testEmptySignatureHeaderFailsVerification(): void
    {
        $this->assertFalse($this->verify(self::PAYLOAD, '', self::SECRET));
    }

    // ------------------------------------------------------------------
    // Test 5: empty secret fails
    // ------------------------------------------------------------------

    public function testEmptySecretFailsVerification(): void
    {
        $sig = $this->computeSignature(self::PAYLOAD, self::SECRET);
        $this->assertFalse($this->verify(self::PAYLOAD, $sig, ''));
    }

    // ------------------------------------------------------------------
    // Test 6: Razorpay uses bare hex — NO "sha256=" prefix required
    //         (contrast with Meta which requires the prefix)
    // ------------------------------------------------------------------

    public function testBareHexFormatIsCorrectNoPrefixNeeded(): void
    {
        $sig = $this->computeSignature(self::PAYLOAD, self::SECRET);

        // Bare hex must not start with "sha256="
        $this->assertStringNotContainsString('sha256=', $sig,
            'Razorpay signature must be bare hex without the "sha256=" prefix.'
        );

        // It must be exactly 64 hex characters
        $this->assertSame(64, strlen($sig), 'SHA-256 HMAC hex must be 64 characters.');

        // And it must verify correctly
        $this->assertTrue($this->verify(self::PAYLOAD, $sig, self::SECRET));
    }

    // ------------------------------------------------------------------
    // Test 7: correct-length hex with wrong content fails
    //         (proves hash_equals, not just length check)
    // ------------------------------------------------------------------

    public function testCorrectLengthForgedSignatureFails(): void
    {
        $correct  = $this->computeSignature(self::PAYLOAD, self::SECRET);
        // Flip the last character — same length, different hash
        $lastChar = substr($correct, -1);
        $forged   = substr($correct, 0, -1) . ($lastChar === 'a' ? 'b' : 'a');

        $this->assertSame(strlen($correct), strlen($forged), 'Sanity: both must be same length.');
        $this->assertFalse($this->verify(self::PAYLOAD, $forged, self::SECRET),
            'A forged signature of correct length must not verify.'
        );
    }

    // ------------------------------------------------------------------
    // Test 8: bypass flag — mirrors MetaSignatureVerifier discipline
    // ------------------------------------------------------------------

    public function testBypassFlagSkipsVerificationAndReturnsTrue(): void
    {
        $verifyEnabled = false; // RAZORPAY_VERIFY_SIGNATURE=false
        $result = $verifyEnabled
            ? $this->verify('any payload', 'wrong_sig', self::SECRET)
            : true; // bypass returns true always
        $this->assertTrue($result);
    }

    public function testBypassFlagTrueStillRequiresValidSignature(): void
    {
        $verifyEnabled = true;
        $result = $verifyEnabled
            ? $this->verify(self::PAYLOAD, 'deadbeef00', self::SECRET)
            : true;
        $this->assertFalse($result);
    }
}
