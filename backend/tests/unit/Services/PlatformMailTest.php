<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Email\PlatformMail;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** The admin-set mail server: password encrypted + write-only, proved by a real test send before saving (group "mysql"). */
#[Group('mysql')]
final class PlatformMailTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PASS = 'abcdefghijklmnop';
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['platform_settings', 'audit_logs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->table('tenants')->insert(['id' => 1, 'name' => 'TripSarthi', 'slug' => 'ts', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com', 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        $this->sent = [];
        PlatformMail::$probe = function (array $cfg, string $to): array { $this->sent[] = [$cfg, $to]; return ['ok' => true]; };
    }

    protected function tearDown(): void { PlatformMail::$probe = null; db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function input(array $o = []): array
    {
        return $o + ['host' => 'smtp.gmail.com', 'port' => 587, 'crypto' => 'tls', 'user' => 'support@example.com', 'pass' => 'abcd efgh ijkl mnop', 'from_email' => 'support@example.com', 'from_name' => 'TripSarthi'];
    }

    public function testSavedSettingsAreUsedAndThePasswordIsEncryptedAndNeverShown(): void
    {
        $st = PlatformMail::save($this->input(), 1, 'admin@example.com');
        $this->assertTrue($st['configured']); $this->assertSame('admin', $st['source']); $this->assertTrue($st['pass_set']);
        $c = PlatformMail::smtp();
        $this->assertSame('smtp.gmail.com', $c['host']); $this->assertSame(self::PASS, $c['pass'], 'spaces in a Google App Password are dropped');
        $this->assertSame('admin@example.com', $this->sent[0][1], 'the test e-mail goes to the admin');

        $raw = json_encode(db_connect()->table('platform_settings')->get()->getResultArray());
        $this->assertStringNotContainsString(self::PASS, $raw, 'not stored in plain text');
        $out = json_encode($st) . json_encode(db_connect()->table('audit_logs')->get()->getResultArray());
        $this->assertStringNotContainsString(self::PASS, $out);
        $this->assertStringNotContainsString('abcd efgh', $out, 'the audit log never holds the password');
        $this->assertStringContainsString('admin.mail.save', $out);
    }

    public function testASettingsThatTheMailServerRefusesAreNotSaved(): void
    {
        PlatformMail::$probe = static fn (): array => ['ok' => false, 'error' => '535 Username and Password not accepted.'];
        try { PlatformMail::save($this->input(), 1, 'admin@example.com'); $this->fail('expected a refusal'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('nothing was saved', $e->getMessage()); $this->assertStringContainsString('535', $e->getMessage()); }
        $this->assertSame(0, db_connect()->table('platform_settings')->countAllResults());
    }

    public function testBlankPasswordKeepsTheSavedOneButAMissingFirstPasswordIsRefused(): void
    {
        try { PlatformMail::save($this->input(['pass' => '']), 1, 'admin@example.com'); $this->fail('a username needs a password the first time'); }
        catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        PlatformMail::save($this->input(), 1, 'admin@example.com');
        PlatformMail::save($this->input(['pass' => '', 'from_name' => 'TripSarthi Support']), 1, 'admin@example.com');
        $this->assertSame(self::PASS, PlatformMail::smtp()['pass']);
        $this->assertSame('TripSarthi Support', PlatformMail::smtp()['from_name']);
    }

    public function testBadInputIsRefusedInPlainWords(): void
    {
        foreach ([['host' => 'not a host'], ['host' => 'localhost'], ['port' => 0], ['port' => 70000], ['crypto' => 'weird'], ['from_email' => 'nope'], ['pass' => "line\nbreak"]] as $bad) {
            try { PlatformMail::save($this->input($bad), 1, 'admin@example.com'); $this->fail('should refuse ' . json_encode($bad)); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(0, db_connect()->table('platform_settings')->countAllResults());
    }

    public function testStatusWarnsAboutGmailAndClearFallsBackToTheServer(): void
    {
        $w = implode(' ', PlatformMail::save($this->input(['from_email' => 'other@example.com']), 1, 'admin@example.com')['warnings']);
        $this->assertStringContainsString('500 e-mails', $w); $this->assertStringContainsString('replaces the "from" address', $w);
        $st = PlatformMail::clear(1);
        $this->assertNotSame('admin', $st['source']);
        $this->assertSame(0, db_connect()->table('platform_settings')->where('value IS NOT NULL')->countAllResults());
    }

    public function testATamperedSavedPasswordFallsBackInsteadOfBreakingMail(): void
    {
        PlatformMail::save($this->input(), 1, 'admin@example.com');
        db_connect()->table('platform_settings')->where('setting_key', PlatformMail::K['pass'])->update(['value' => 'not-valid-ciphertext']);
        $this->assertNotSame(self::PASS, PlatformMail::smtp()['pass']);
    }
}
