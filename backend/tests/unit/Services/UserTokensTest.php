<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** One sign-in per DEVICE: the phone and the web app (or two browsers) can be signed in as the same person at the same time (group "mysql"). */
#[Group('mysql')]
final class UserTokensTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private UserModel $users;
    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['user_tokens', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->table('tenants')->insert(['id' => 1, 'name' => 'Agency', 'slug' => 'a1', 'plan' => 'starter', 'status' => 'active']);
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Asha', 'email' => 'asha@example.com', 'password_hash' => 'x', 'role' => 'owner']);
        $db->table('users')->insert(['id' => 2, 'tenant_id' => 1, 'name' => 'Ravi', 'email' => 'ravi@example.com', 'password_hash' => 'x', 'role' => 'agent']);
        $this->users = new UserModel(); $this->uid = 1;
    }

    private function ok(string $t): bool { $u = $this->users->findByToken($t); return $u !== null && (int) (is_array($u) ? $u['id'] : $u->id) === $this->uid; }

    public function testTwoDevicesAreSignedInAtTheSameTime(): void
    {
        $web = $this->users->setToken($this->uid, 'web'); $phone = $this->users->setToken($this->uid, 'mobile');
        $this->assertNotSame($web, $phone);
        $this->assertTrue($this->ok($web), 'the web sign-in survives the phone signing in');
        $this->assertTrue($this->ok($phone));
        $this->assertSame('mobile', db_connect()->table('user_tokens')->where('token_hash', hash('sha256', $phone))->get()->getRow()->client);
    }

    public function testSigningOutOneDeviceLeavesTheOthers(): void
    {
        $a = $this->users->setToken($this->uid); $b = $this->users->setToken($this->uid, 'mobile');
        $this->users->revokeToken($this->uid, $a);
        $this->assertFalse($this->ok($a));
        $this->assertTrue($this->ok($b));
        $this->users->revokeToken($this->uid);                  // no token = everywhere
        $this->assertFalse($this->ok($b));
    }

    public function testOnlyTheHashIsStoredAndTokensBelongToOnePerson(): void
    {
        $t = $this->users->setToken($this->uid);
        $this->assertSame(0, db_connect()->table('user_tokens')->where('token_hash', $t)->countAllResults(), 'the raw token is never stored');
        $this->assertNull($this->users->findByToken(str_repeat('0', 64)));
        $other = $this->users->setToken(2);
        $u = $this->users->findByToken($other);
        $this->assertSame(2, (int) (is_array($u) ? $u['id'] : $u->id), 'a token opens only its own person');
    }

    public function testAnExpiredTokenIsRefusedAndRemoved(): void
    {
        $t = $this->users->setToken($this->uid);
        db_connect()->table('user_tokens')->where('token_hash', hash('sha256', $t))->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertNull($this->users->findByToken($t));
        $this->assertSame(0, db_connect()->table('user_tokens')->countAllResults());
    }

    public function testAnOldSingleTokenSessionKeepsWorkingUntilItExpires(): void
    {
        $raw = bin2hex(random_bytes(32));
        $this->users->update($this->uid, ['api_token' => hash('sha256', $raw), 'api_token_expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->assertTrue($this->ok($raw), 'a session opened before per-device tokens existed is still honoured');
        $this->users->revokeToken($this->uid, $raw);
        $this->assertFalse($this->ok($raw));
    }

    public function testChangingPasswordSignsOutTheOtherDevicesButNotThisOne(): void
    {
        $here = $this->users->setToken($this->uid); $elsewhere = $this->users->setToken($this->uid, 'mobile');
        $this->users->revokeOthers($this->uid, $here);
        $this->assertTrue($this->ok($here));
        $this->assertFalse($this->ok($elsewhere));
    }

    public function testAtMostTenDevicesAreKeptAndTheOldestIsDropped(): void
    {
        $first = $this->users->setToken($this->uid); $last = $first;
        for ($i = 0; $i < UserModel::MAX_TOKENS; $i++) { $last = $this->users->setToken($this->uid); }
        $this->assertSame(UserModel::MAX_TOKENS, db_connect()->table('user_tokens')->where('user_id', $this->uid)->countAllResults());
        $this->assertFalse($this->ok($first), 'the oldest sign-in was dropped');
        $this->assertTrue($this->ok($last));
    }
}
