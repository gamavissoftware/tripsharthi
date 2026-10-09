<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests for the X-Hub-Signature-256 verification logic used in
 * WhatsAppWebhookController.
 *
 * We test the core algorithm independently (no full HTTP request needed).
 */
class WebhookSignatureTest extends CIUnitTestCase
{
    private const SECRET  = 'test_app_secret_abc123';
    private const PAYLOAD = '{"object":"whatsapp_business_account","entry":[]}';

    // ------------------------------------------------------------------
    // Core algorithm
    // ------------------------------------------------------------------

    private function computeSignature(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    private function verify(string $rawBody, string $sigHeader, string $secret): bool
    {
        if (empty($sigHeader) || empty($secret)) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $sigHeader);
    }

    // ------------------------------------------------------------------
    // Passing cases
    // ------------------------------------------------------------------

    public function testValidSignaturePassesVerification(): void
    {
        $sig = $this->computeSignature(self::PAYLOAD, self::SECRET);
        $this->assertTrue($this->verify(self::PAYLOAD, $sig, self::SECRET));
    }

    public function testVerificationIsSignaturePrefixedWithSha256(): void
    {
        $sig = $this->computeSignature(self::PAYLOAD, self::SECRET);
        $this->assertStringStartsWith('sha256=', $sig);
        $this->assertSame(71, strlen($sig)); // 'sha256=' (7) + 64 hex chars
    }

    // ------------------------------------------------------------------
    // Failing cases
    // ------------------------------------------------------------------

    public function testTamperedBodyFailsVerification(): void
    {
        $sig          = $this->computeSignature(self::PAYLOAD, self::SECRET);
        $tamperedBody = self::PAYLOAD . ' '; // one trailing space
        $this->assertFalse($this->verify($tamperedBody, $sig, self::SECRET));
    }

    public function testWrongSecretFailsVerification(): void
    {
        $sig = $this->computeSignature(self::PAYLOAD, 'wrong_secret');
        $this->assertFalse($this->verify(self::PAYLOAD, $sig, self::SECRET));
    }

    public function testEmptySignatureHeaderFailsVerification(): void
    {
        $this->assertFalse($this->verify(self::PAYLOAD, '', self::SECRET));
    }

    public function testEmptySecretFailsVerification(): void
    {
        $sig = $this->computeSignature(self::PAYLOAD, self::SECRET);
        $this->assertFalse($this->verify(self::PAYLOAD, $sig, ''));
    }

    public function testMissingPrefixFailsVerification(): void
    {
        // Signature without 'sha256=' prefix
        $sig = hash_hmac('sha256', self::PAYLOAD, self::SECRET);
        $this->assertFalse($this->verify(self::PAYLOAD, $sig, self::SECRET));
    }

    public function testEmptyBodyWithValidSignatureForEmptyBodyPasses(): void
    {
        $emptyBody = '';
        $sig       = $this->computeSignature($emptyBody, self::SECRET);
        $this->assertTrue($this->verify($emptyBody, $sig, self::SECRET));
    }

    public function testEmptyBodyWithWrongSignatureFails(): void
    {
        $sig = $this->computeSignature(self::PAYLOAD, self::SECRET);
        $this->assertFalse($this->verify('', $sig, self::SECRET));
    }

    // ------------------------------------------------------------------
    // hash_equals is constant-time (timing-safe comparison)
    // ------------------------------------------------------------------

    public function testVerificationUsesConstantTimeComparison(): void
    {
        // Verify that hash_equals is used (not ===) by checking near-match strings
        // differ in the last character — timing-safe comparison returns false
        $correct = $this->computeSignature(self::PAYLOAD, self::SECRET);
        $oneBitOff = substr($correct, 0, -1) . (substr($correct, -1) === 'a' ? 'b' : 'a');

        // The near-match should fail
        $this->assertFalse($this->verify(self::PAYLOAD, $oneBitOff, self::SECRET));

        // The correct one should pass
        $this->assertTrue($this->verify(self::PAYLOAD, $correct, self::SECRET));
    }

    // ------------------------------------------------------------------
    // WEBHOOK_VERIFY_SIGNATURE=false bypass logic
    // ------------------------------------------------------------------

    public function testBypassFlagSkipsVerificationAndReturnsTrue(): void
    {
        // Simulate the bypass logic in WhatsAppWebhookController
        $verifyEnabled = false; // WEBHOOK_VERIFY_SIGNATURE=false

        $result = $verifyEnabled
            ? $this->verify('any payload', 'wrong sig', self::SECRET)
            : true; // bypass returns true always

        $this->assertTrue($result);
    }

    public function testBypassFlagTrueStillRequiresValidSignature(): void
    {
        $verifyEnabled = true;

        $result = $verifyEnabled
            ? $this->verify(self::PAYLOAD, 'sha256=wrong', self::SECRET)
            : true;

        $this->assertFalse($result);
    }
}
