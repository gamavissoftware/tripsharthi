<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\PlatformRazorpay;
use App\Services\Billing\RazorpaySignatureVerifier;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** TripSarthi's own Razorpay keys, set from the admin app: encrypted at rest, write-only, proved before saving (group "mysql"). */
#[Group('mysql')]
final class PlatformRazorpayTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const ID = 'rzp_test_AbCdEf123456';
    private const SECRET = 'TestSecretValue0123456789';
    private const WEBHOOK = 'whsec_test_value_123';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['platform_settings', 'audit_logs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->table('tenants')->insert(['id' => 1, 'name' => 'TripSarthi', 'slug' => 'ts', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Admin', 'email' => 'a@example.com', 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        PlatformRazorpay::$probe = static fn (string $id, string $s): array => ['code' => 200, 'body' => []];
    }

    protected function tearDown(): void { PlatformRazorpay::$probe = null; db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    public function testSavedKeysAreUsedAndTheSecretIsEncryptedAtRest(): void
    {
        $st = PlatformRazorpay::save(self::ID, self::SECRET, self::WEBHOOK, 1);
        $this->assertTrue($st['configured']);
        $this->assertSame('admin', $st['source']);
        $this->assertSame('test', $st['mode']);
        $this->assertSame(self::ID, PlatformRazorpay::keyId());
        $this->assertSame(self::SECRET, PlatformRazorpay::keySecret());
        $this->assertSame(self::WEBHOOK, PlatformRazorpay::webhookSecret());

        $raw = json_encode(db_connect()->table('platform_settings')->get()->getResultArray());
        $this->assertStringNotContainsString(self::SECRET, $raw, 'secret must not sit in the database in plain text');
        $this->assertStringNotContainsString(self::WEBHOOK, $raw);
    }

    public function testStatusAndAuditNeverContainASecret(): void
    {
        PlatformRazorpay::save(self::ID, self::SECRET, self::WEBHOOK, 1);
        $out = json_encode(PlatformRazorpay::status()) . json_encode(db_connect()->table('audit_logs')->get()->getResultArray());
        $this->assertStringNotContainsString(self::SECRET, $out);
        $this->assertStringNotContainsString(self::WEBHOOK, $out);
        $this->assertStringContainsString('admin.razorpay.save', $out);
    }

    public function testKeysRejectedByRazorpayAreNotSaved(): void
    {
        PlatformRazorpay::$probe = static fn (): array => ['code' => 401, 'body' => []];
        try { PlatformRazorpay::save(self::ID, self::SECRET, '', 1); $this->fail('expected a refusal'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('did not accept', $e->getMessage()); }
        $this->assertSame(0, db_connect()->table('platform_settings')->countAllResults());
        $this->assertFalse(PlatformRazorpay::status()['configured']);
    }

    public function testNetworkTroubleSavesNothing(): void
    {
        PlatformRazorpay::$probe = static fn (): array => ['code' => 0, 'error' => 'timeout'];
        $this->expectException(\InvalidArgumentException::class);
        try { PlatformRazorpay::save(self::ID, self::SECRET, '', 1); }
        finally { $this->assertSame(0, db_connect()->table('platform_settings')->countAllResults()); }
    }

    public function testBlankSecretKeepsTheStoredOneButANewKeyIdNeedsItsSecret(): void
    {
        PlatformRazorpay::save(self::ID, self::SECRET, self::WEBHOOK, 1);
        PlatformRazorpay::save(self::ID, '', '', 1);                 // same id, nothing typed: keeps both
        $this->assertSame(self::SECRET, PlatformRazorpay::keySecret());
        $this->assertSame(self::WEBHOOK, PlatformRazorpay::webhookSecret());

        $this->expectException(\InvalidArgumentException::class);   // a different id with no secret is refused
        PlatformRazorpay::save('rzp_live_ZzYyXx987654', '', '', 1);
    }

    public function testBadInputIsRefusedInPlainWords(): void
    {
        foreach ([['not-a-key', self::SECRET], ['rzp_test_', self::SECRET], [self::ID, 'short'], [self::ID, 'has a space in it 123']] as [$id, $s]) {
            try { PlatformRazorpay::save($id, $s, '', 1); $this->fail("should refuse $id / $s"); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(0, db_connect()->table('platform_settings')->countAllResults());
    }

    public function testModeComesFromTheKeyPrefix(): void
    {
        $this->assertSame('live', PlatformRazorpay::save('rzp_live_AbCdEf123456', self::SECRET, '', 1)['mode']);
        $this->assertSame('test', PlatformRazorpay::save(self::ID, self::SECRET, '', 1)['mode']);
    }

    public function testWebhookSignatureUsesTheSavedWebhookSecret(): void
    {
        PlatformRazorpay::save(self::ID, self::SECRET, self::WEBHOOK, 1);
        $body = '{"event":"subscription.halted"}';
        $this->assertTrue(RazorpaySignatureVerifier::verify($body, hash_hmac('sha256', $body, self::WEBHOOK)));
        $this->assertFalse(RazorpaySignatureVerifier::verify($body, hash_hmac('sha256', $body, 'wrong-secret')));
    }

    public function testClearRemovesWhatWasSaved(): void
    {
        PlatformRazorpay::save(self::ID, self::SECRET, self::WEBHOOK, 1);
        $st = PlatformRazorpay::clear(1);
        $this->assertNotSame('admin', $st['source']);
        $this->assertSame(0, db_connect()->table('platform_settings')->where('value IS NOT NULL')->countAllResults());
    }

    public function testATamperedStoredSecretFallsBackInsteadOfBreakingCheckout(): void
    {
        PlatformRazorpay::save(self::ID, self::SECRET, '', 1);
        db_connect()->table('platform_settings')->where('setting_key', PlatformRazorpay::K_SECRET)->update(['value' => 'not-valid-ciphertext']);
        $this->assertNotSame(self::SECRET, PlatformRazorpay::keySecret());   // no exception, no leak
    }
}
