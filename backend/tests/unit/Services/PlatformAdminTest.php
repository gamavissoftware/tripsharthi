<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\PlatformAdminService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Cross-tenant admin operations against real MySQL (group "mysql"). */
#[Group('mysql')]
final class PlatformAdminTest extends CIUnitTestCase
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
        foreach (['subscriptions', 'contacts', 'users', 'audit_logs', 'contact_enquiries', 'chat_messages', 'chat_sessions', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1 => ['HQ', 'pro'], 2 => ['Sunrise Tours', 'starter'], 3 => ['Blue Travels', 'free']] as $id => [$n, $p]) {
            $db->table('tenants')->insert(['id' => $id, 'name' => $n, 'slug' => 'ws' . $id, 'plan' => $p, 'status' => 'active', 'mode' => 'saas', 'created_at' => date('Y-m-d H:i:s', time() - $id * 86400)]);
            $db->table('users')->insert(['tenant_id' => $id, 'name' => "Owner $id", 'email' => "o$id@example.com", 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        }
        $db->table('users')->where('tenant_id', 1)->update(['is_platform_admin' => 1]);
        $db->table('contacts')->insert(['tenant_id' => 2, 'name' => 'C', 'source' => 'manual', 'status' => 'new', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('subscriptions')->insert(['tenant_id' => 2, 'razorpay_sub_id' => 'pay_123', 'razorpay_customer_id' => 'pay_123', 'plan' => 'starter', 'amount_paise' => 149_900, 'billing_cycle' => 'monthly', 'status' => 'active', 'current_period_start' => date('Y-m-d H:i:s'), 'current_period_end' => date('Y-m-d H:i:s', time() + 20 * 86400), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function testOverviewCountsEveryWorkspaceAndComputesMrr(): void
    {
        $o = (new PlatformAdminService())->overview();
        $this->assertSame(3, $o['tenants']['total']);
        $this->assertSame(['free' => 1, 'starter' => 1, 'growth' => 0, 'pro' => 1], $o['tenants']['by_plan']);
        $this->assertSame([149_900, 1], [$o['subscriptions']['mrr_paise'], $o['subscriptions']['paying']]);
        $this->assertSame(1, $o['usage']['contacts']);
    }

    public function testTenantListSearchesAndFilters(): void
    {
        $s = new PlatformAdminService();
        $this->assertSame(3, $s->tenants()['total']);
        $r = $s->tenants('sunrise'); $this->assertSame([2], array_column($r['rows'], 'id'));
        $this->assertSame([2], array_column($s->tenants('o2@example.com')['rows'], 'id'));          // finds by a user's email
        $this->assertSame([3], array_column($s->tenants('', 'free')['rows'], 'id'));
        $this->assertSame(['o2@example.com', 1], [$r['rows'][0]['owner_email'], $r['rows'][0]['contacts']]);
        $this->assertSame('active', $r['rows'][0]['sub_status']);
    }

    public function testManualPlanCreatesAnExpiringSubscriptionAndIsAudited(): void
    {
        $s = new PlatformAdminService();
        $d = $s->setPlan(3, 'growth', 14, 0, 1);                                              // complimentary fortnight
        $this->assertSame('growth', $d['tenant']['plan']);
        $this->assertTrue($d['subscriptions'][0]['manual']); $this->assertSame('active', $d['subscriptions'][0]['status']);
        $this->assertSame(10, $d['limits']['agents']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'admin.tenant.plan')->where('tenant_id', 3)->countAllResults());
        // it ends by itself
        db_connect()->table('subscriptions')->where('tenant_id', 3)->update(['current_period_end' => date('Y-m-d H:i:s', time() - 60)]);
        \Config\Services::commands()->run('subscription:check', []);
        $this->assertSame('free', db_connect()->table('tenants')->where('id', 3)->get()->getRowArray()['plan']);
    }

    public function testALivePaidSubscriptionIsNotOverriddenByAccident(): void
    {
        $s = new PlatformAdminService();
        try { $s->setPlan(2, 'pro', 30, 0, 1); $this->fail('overrode a live Razorpay subscription'); } catch (\DomainException $e) { $this->assertStringContainsString('Razorpay', $e->getMessage()); }
        $this->assertSame('starter', db_connect()->table('tenants')->where('id', 2)->get()->getRowArray()['plan']);
        $this->assertSame('pro', $s->setPlan(2, 'pro', 30, 0, 1, true)['tenant']['plan']);   // deliberate override
        $this->expectException(\InvalidArgumentException::class); $s->setPlan(2, 'platinum', 30, 0, 1);
    }

    public function testSuspendSignsEveryoneOutKeepsAuditAndProtectsOwnWorkspace(): void
    {
        $db = db_connect(); $db->table('users')->where('tenant_id', 2)->update(['api_token' => 'hash', 'api_token_expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $s = new PlatformAdminService();
        try { $s->setStatus(2, 'suspended', 1, 1); $this->fail('reason is required'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame('suspended', $s->setStatus(2, 'suspended', 1, 1, 'unpaid invoice')['tenant']['status']);
        $this->assertNull($db->table('users')->where('tenant_id', 2)->get()->getRowArray()['api_token']);
        try { $s->setStatus(1, 'suspended', 1, 1, 'oops'); $this->fail('suspended own workspace'); } catch (\DomainException $e) { $this->assertStringContainsString('own workspace', $e->getMessage()); }
        $this->assertSame('active', $s->setStatus(2, 'active', 1, 1)['tenant']['status']);
        $this->assertSame(2, $db->table('audit_logs')->where('action', 'admin.tenant.status')->countAllResults());
    }

    public function testTenantDetailShowsUsageUsersAndSubscriptionsWithoutSecrets(): void
    {
        $d = (new PlatformAdminService())->tenant(2);
        $this->assertSame([1, 1], [$d['usage']['users'], $d['usage']['contacts']]);
        $this->assertSame('o2@example.com', $d['users'][0]['email']);
        $this->assertArrayNotHasKey('password_hash', $d['users'][0]); $this->assertArrayNotHasKey('api_token', $d['users'][0]);
        $this->assertArrayNotHasKey('razorpay_sub_id', $d['subscriptions'][0]);
        $this->expectException(\OutOfBoundsException::class); (new PlatformAdminService())->tenant(999);
    }

    public function testSubscriptionListFilters(): void
    {
        $s = new PlatformAdminService();
        $this->assertSame(1, $s->subscriptions('active')['total']);
        $this->assertSame(0, $s->subscriptions('halted')['total']);
        $this->assertSame('Sunrise Tours', $s->subscriptions()['rows'][0]['tenant_name']);
    }
}
