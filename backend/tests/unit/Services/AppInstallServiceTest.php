<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\AppInstallService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class AppInstallServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['app_installs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'Agency', 'slug' => 'agency', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        foreach ([1, 2] as $u) { $db->table('users')->insert(['id' => $u, 'tenant_id' => 1, 'name' => "User $u", 'email' => "u$u@example.com", 'password_hash' => 'x', 'role' => 'agent', 'created_at' => '2026-01-01 00:00:00']); }
    }

    private function hello(int $user, string $install, string $ver, array $more = []): bool
    {
        return AppInstallService::ping($user, 1, array_merge(['install_id' => $install, 'app_version' => $ver, 'platform' => 'android', 'runtime_version' => '2', 'device_name' => 'Pixel 8'], $more));
    }

    public function testSecondHelloUpdatesTheSameInstallInsteadOfAddingOne(): void
    {
        $this->assertTrue($this->hello(1, 'aaaaaaaaaaaa1111', '1.4.1'));
        $this->assertTrue($this->hello(1, 'aaaaaaaaaaaa1111', '1.5.0', ['update_id' => 'abcd1234']));
        $rows = db_connect()->table('app_installs')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame('1.5.0', $rows[0]['app_version']);
        $this->assertSame('abcd1234', $rows[0]['update_id']);
    }

    public function testJunkIsRefused(): void
    {
        $this->assertFalse($this->hello(1, 'short', '1.5.0'));
        $this->assertFalse($this->hello(1, 'aaaaaaaaaaaa1111', 'latest'));
        $this->assertFalse($this->hello(1, "aaaa'; DROP TABLE users;--", '1.5.0'));
        $this->assertSame(0, (int) db_connect()->table('app_installs')->countAllResults());
        $this->assertTrue($this->hello(1, 'aaaaaaaaaaaa1111', '1.5.0', ['device_name' => "Evil\x00\x07 name", 'runtime_version' => '<script>', 'platform' => 'windows']));
        $r = db_connect()->table('app_installs')->get()->getRowArray();
        $this->assertSame('Evil name', $r['device_name']);
        $this->assertNull($r['runtime_version']);
        $this->assertSame('android', $r['platform']);
    }

    public function testSummaryCountsVersionsAndWhoIsBehind(): void
    {
        $this->hello(1, 'aaaaaaaaaaaa1111', '1.5.0'); $this->hello(2, 'bbbbbbbbbbbb2222', '1.4.1'); $this->hello(2, 'cccccccccccc3333', '1.1.0');
        db_connect()->table('app_installs')->where('install_id', 'cccccccccccc3333')->update(['last_seen_at' => date('Y-m-d H:i:s', time() - 40 * 86400)]);   // not seen for 40 days
        $s = AppInstallService::summary('1.5.0');
        $this->assertSame(2, $s['phones'], 'a phone not seen for 30 days is not counted');
        $this->assertSame(1, $s['behind']);
        $this->assertSame(['1.5.0', '1.4.1'], array_column($s['versions'], 'version'));
        $this->assertSame(2, $s['active_24h']);
        $this->assertSame('User 1', $s['recent'][0]['user_name'] === 'User 1' ? 'User 1' : $s['recent'][1]['user_name']);
        $this->assertNull(AppInstallService::summary(null)['latest']);
    }
}
