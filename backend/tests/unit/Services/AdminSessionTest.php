<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\AdminSessionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Sessions of the separate platform-admin app, against real MySQL (group "mysql"). */
#[Group('mysql')]
final class AdminSessionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['admin_sessions', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'HQ', 'slug' => 'hq', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com', 'password_hash' => 'x', 'role' => 'owner', 'is_platform_admin' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 2, 'tenant_id' => 1, 'name' => 'Owner', 'email' => 'owner@example.com', 'password_hash' => 'x', 'role' => 'owner', 'is_platform_admin' => 0, 'created_at' => '2026-01-01 00:00:00']);
    }

    public function testAValidSessionReturnsTheAdminAndStoresOnlyAHash(): void
    {
        $svc = new AdminSessionService();
        $raw = $svc->create(1, '203.0.113.9', 'Mozilla/5.0');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $raw);
        $this->assertSame(1, (int) $svc->user($raw)['id']);
        $row = db_connect()->table('admin_sessions')->get()->getRowArray();
        $this->assertSame(hash('sha256', $raw), $row['token_hash']);
        $this->assertNotSame($raw, $row['token_hash']);
        $this->assertNull($svc->user(str_repeat('a', 64)));                // unknown token
        $this->assertNull($svc->user('not-a-token'));
        $this->assertNull($svc->user(''));
    }

    public function testASessionEndsAfterTheAbsoluteLifetimeEvenWhileActive(): void
    {
        $t0  = time();
        $raw = (new AdminSessionService($t0))->create(1);
        for ($h = 1; $h <= 11; $h++) { $this->assertNotNull((new AdminSessionService($t0 + $h * 3600))->user($raw), "active at hour {$h}"); }
        $this->assertNull((new AdminSessionService($t0 + 12 * 3600 + 1))->user($raw), 'past 12h it ends however recently it was used');
    }

    public function testAnIdleSessionExpiresButActivityKeepsItAlive(): void
    {
        $t0  = time();
        $raw = (new AdminSessionService($t0))->create(1);
        $this->assertNull((new AdminSessionService($t0 + 121 * 60))->user($raw), 'idle for 2h+');

        $raw2 = (new AdminSessionService($t0))->create(1);
        $this->assertNotNull((new AdminSessionService($t0 + 90 * 60))->user($raw2));   // used at 90 min -> refreshes last_seen
        $this->assertNotNull((new AdminSessionService($t0 + 170 * 60))->user($raw2));  // 80 min after that: still inside the idle window
    }

    public function testLogoutAndRevokeAllEndSessions(): void
    {
        $svc = new AdminSessionService();
        $a = $svc->create(1); $b = $svc->create(1);
        $svc->revoke($a);
        $this->assertNull($svc->user($a));
        $this->assertNotNull($svc->user($b));
        $this->assertSame(1, $svc->revokeAll(1));
        $this->assertNull($svc->user($b));
    }

    public function testAUserWhoIsNotAPlatformAdminNeverGetsIn(): void
    {
        $svc = new AdminSessionService();
        $raw = $svc->create(2);                                              // even with a session row...
        $this->assertNull($svc->user($raw));
    }

    public function testRevokingAdminRightsKillsLiveSessionsImmediately(): void
    {
        $svc = new AdminSessionService();
        $raw = $svc->create(1);
        $this->assertNotNull($svc->user($raw));
        db_connect()->table('users')->where('id', 1)->update(['is_platform_admin' => 0]);   // what `admin:grant --revoke` does
        $this->assertNull($svc->user($raw));
    }

    public function testOnlyTheFiveNewestSessionsStayLive(): void
    {
        $svc = new AdminSessionService();
        $tokens = [];
        for ($i = 0; $i < 6; $i++) { $tokens[] = $svc->create(1); }
        $this->assertNull($svc->user($tokens[0]), 'the oldest was ended by the sixth sign-in');
        for ($i = 1; $i < 6; $i++) { $this->assertNotNull($svc->user($tokens[$i])); }
    }
}
