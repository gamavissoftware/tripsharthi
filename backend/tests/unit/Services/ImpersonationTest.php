<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\ImpersonationService;
use App\Services\Auth\CurrentUser;
use App\Services\Crm\AuditLogger;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** TripSarthi team "log in as customer": separate session, reason, limits, blocked actions, view-only, audit tagging, owner untouched (group "mysql"). */
#[Group('mysql')]
final class ImpersonationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const REASON = 'Customer reported that invoices will not generate';
    private string $ownerToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['impersonation_sessions', 'audit_logs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        foreach ([1, 2] as $id) { $db->table('tenants')->insert(['id' => $id, 'name' => "Agency $id", 'slug' => "a$id", 'plan' => 'growth', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $this->ownerToken = bin2hex(random_bytes(32));
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Real Owner', 'email' => 'owner@example.com', 'password_hash' => 'x', 'role' => 'owner', 'api_token' => hash('sha256', $this->ownerToken), 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 2, 'tenant_id' => 1, 'name' => 'An Agent', 'email' => 'agent@example.com', 'password_hash' => 'x', 'role' => 'agent', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 9, 'tenant_id' => 2, 'name' => 'Team Member', 'email' => 'team@example.com', 'password_hash' => 'x', 'role' => 'owner', 'is_platform_admin' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 8, 'tenant_id' => 2, 'name' => 'Other Admin', 'email' => 'team2@example.com', 'password_hash' => 'x', 'role' => 'owner', 'is_platform_admin' => 1, 'created_at' => '2026-01-01 00:00:00']);
        CurrentUser::reset();
    }

    protected function tearDown(): void { CurrentUser::reset(); db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    public function testReasonIsRequiredAndTrimmed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ImpersonationService())->start(9, 1, 'too short', false);
    }

    public function testStartActsAsTheOwnerAndNeverTouchesTheOwnersLogin(): void
    {
        $r = (new ImpersonationService())->start(9, 1, self::REASON, false, '10.0.0.1');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r['token']);
        $this->assertSame('Real Owner', $r['as']['name']);
        $hash = db_connect()->table('users')->select('api_token')->where('id', 1)->get()->getRowArray()['api_token'];
        $this->assertSame(hash('sha256', $this->ownerToken), $hash, 'the owner\'s own token must be untouched');
        $this->assertNotSame(hash('sha256', $r['token']), $hash);
        $row = db_connect()->table('impersonation_sessions')->get()->getRowArray();
        $this->assertSame(hash('sha256', $r['token']), $row['token_hash']);
        $this->assertStringNotContainsString($r['token'], json_encode($row), 'only the hash is stored');
    }

    public function testResolveAndEnd(): void
    {
        $s = new ImpersonationService(); $r = $s->start(9, 1, self::REASON, false);
        $live = $s->resolve($r['token']);
        $this->assertSame(1, (int) $live['user']['id']);
        $this->assertNull($s->resolve(str_repeat('a', 64)));
        $this->assertNull($s->resolve('not-a-token'));
        $s->end($r['session_id'], 'admin');
        $this->assertNull($s->resolve($r['token']), 'an ended session is dead');
    }

    public function testExpiryIdleAndAdminRevocation(): void
    {
        $now = time(); $a = new ImpersonationService($now); $r = $a->start(9, 1, self::REASON, false);
        $this->assertNotNull((new ImpersonationService($now + 20 * 60))->resolve($r['token']));
        $this->assertNull((new ImpersonationService($now + 55 * 60))->resolve($r['token']), 'idle for more than 30 minutes (the +20 min use slid the window)');
        $r2 = $a->start(9, 1, self::REASON . ' again', false);
        $this->assertNull((new ImpersonationService($now + 125 * 60))->resolve($r2['token']), 'past the 2 hour limit');
        $r3 = $a->start(9, 1, self::REASON . ' third', false);
        db_connect()->table('users')->where('id', 9)->update(['is_platform_admin' => 0]);
        $this->assertNull($a->resolve($r3['token']), 'admin:grant --revoke ends the session at once');
    }

    public function testCannotEnterAnotherAdminOrAMissingWorkspace(): void
    {
        $s = new ImpersonationService();
        try { $s->start(9, 2, self::REASON, false); $this->fail('workspace 2 is owned by a platform admin'); } catch (\DomainException) { $this->assertTrue(true); }
        $this->expectException(\OutOfBoundsException::class);
        $s->start(9, 99, self::REASON, false);
    }

    public function testRateLimit(): void
    {
        $s = new ImpersonationService();
        for ($i = 0; $i < ImpersonationService::PER_HOUR; $i++) { $s->start(9, 1, self::REASON . " $i", false); }
        $this->expectException(\DomainException::class);
        $s->start(9, 1, self::REASON . ' one too many', false);
    }

    public function testRefusalRules(): void
    {
        $this->assertNotNull(ImpersonationService::refusal('PUT', '/api/v1/auth/change-password', false));
        $this->assertNotNull(ImpersonationService::refusal('PUT', 'api/v1/auth/profile', false));
        $this->assertNotNull(ImpersonationService::refusal('POST', '/api/v1/billing/create-order', false));
        $this->assertNotNull(ImpersonationService::refusal('POST', '/api/v1/billing/verify-payment', false));
        $this->assertNull(ImpersonationService::refusal('POST', '/api/v1/contacts', false), 'everything else is open in a full-access session');
        $this->assertNull(ImpersonationService::refusal('GET', '/api/v1/contacts', true));
        $this->assertNotNull(ImpersonationService::refusal('POST', '/api/v1/contacts', true), 'view-only refuses writes');
        $this->assertNotNull(ImpersonationService::refusal('DELETE', '/api/v1/contacts/1', true));
        $this->assertNull(ImpersonationService::refusal('POST', '/api/v1/crm/reports/run-batch', true), 'report queries only read');
        $this->assertNull(ImpersonationService::refusal('POST', '/api/v1/auth/logout', true));
    }

    public function testWorkspaceAuditShowsSupportAccessWithTheReason(): void
    {
        $s = new ImpersonationService(); $r = $s->start(9, 1, self::REASON, true);
        $s->end($r['session_id'], 'admin');
        $rows = db_connect()->table('audit_logs')->where('tenant_id', 1)->orderBy('id')->get()->getResultArray();
        $this->assertSame(['support.access.start', 'support.access.end'], array_column($rows, 'action'));
        $this->assertSame([9, 9], array_map('intval', array_column($rows, 'impersonated_by')), 'both rows are tagged as support, not as the owner');
        $this->assertStringContainsString(self::REASON, $rows[0]['after']);
        $this->assertStringContainsString('"view_only":true', $rows[0]['after']);
    }

    public function testChangesInsideASessionAreTaggedButNormalOnesAreNot(): void
    {
        $r = (new ImpersonationService())->start(9, 1, self::REASON, false);
        $imp = (new ImpersonationService())->resolve($r['token']);
        CurrentUser::set($imp['user']); CurrentUser::setImpersonation($imp['session']);
        AuditLogger::log('updated', 'contact', 5, ['name' => 'a'], ['name' => 'b']);
        CurrentUser::reset(); CurrentUser::set(['id' => 1, 'tenant_id' => 1, 'role' => 'owner']);
        AuditLogger::log('updated', 'contact', 6, ['name' => 'a'], ['name' => 'b']);
        $a = db_connect()->table('audit_logs')->where('entity_id', 5)->get()->getRowArray();
        $b = db_connect()->table('audit_logs')->where('entity_id', 6)->get()->getRowArray();
        $this->assertSame(9, (int) $a['impersonated_by']);
        $this->assertNull($b['impersonated_by']);
    }

    // ---- through the real request filter -----------------------------------------------------------------------------------------------

    private function as(string $token): self { return $this->withHeaders(['Authorization' => 'Bearer ' . $token]); }

    public function testFilterAcceptsTheSessionAndLimitsIt(): void
    {
        $r = (new ImpersonationService())->start(9, 1, self::REASON, false);
        $me = $this->as($r['token'])->get('api/v1/auth/me'); $me->assertStatus(200);
        $json = json_decode($me->getJSON(), true);
        $this->assertSame('owner@example.com', $json['user']['email']);
        $this->assertNotNull($json['support_session']);
        $this->as($r['token'])->put('api/v1/auth/change-password', ['current_password' => 'x', 'new_password' => 'newpassword1'])->assertStatus(403);
        $this->as($r['token'])->put('api/v1/auth/profile', ['email' => 'x@y.com'])->assertStatus(403);
    }

    public function testViewOnlySessionRefusesWrites(): void
    {
        $r = (new ImpersonationService())->start(9, 1, self::REASON, true);
        $this->as($r['token'])->get('api/v1/auth/me')->assertStatus(200);
        $this->as($r['token'])->post('api/v1/contacts', ['wa_number' => '919876500001', 'name' => 'X'])->assertStatus(403);
    }

    public function testLogoutEndsOnlyTheSupportSession(): void
    {
        $r = (new ImpersonationService())->start(9, 1, self::REASON, false);
        $this->as($r['token'])->post('api/v1/auth/logout')->assertStatus(200);
        $this->as($r['token'])->get('api/v1/auth/me')->assertStatus(401);
        $this->as($this->ownerToken)->get('api/v1/auth/me')->assertStatus(200);   // the real owner is still signed in
    }

    public function testSuspendedWorkspaceIsStillReachableForSupport(): void
    {
        db_connect()->table('tenants')->where('id', 1)->update(['status' => 'suspended']);
        $r = (new ImpersonationService())->start(9, 1, self::REASON, true);
        $this->as($r['token'])->get('api/v1/auth/me')->assertStatus(200);
        $this->as($this->ownerToken)->get('api/v1/auth/me')->assertStatus(403);
    }

    public function testAdminSessionTokenIsNotAcceptedAsASupportSession(): void
    {
        $raw = (new \App\Services\Admin\AdminSessionService())->create(9);
        $this->as($raw)->get('api/v1/auth/me')->assertStatus(401);
    }
}
