<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\BillingService;
use App\Services\Billing\PlatformRazorpay;
use App\Services\Billing\SubscriptionLifecycle;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Prepaid checkout safety nets and the end of a paid period (group "mysql"). */
#[Group('mysql')]
final class SubscriptionLifecycleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const NOW = 1_800_000_000;   // fixed clock for the lifecycle service

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['subscription_reminders', 'subscriptions', 'notifications', 'platform_settings', 'audit_logs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        foreach ([1 => 'active', 2 => 'active', 3 => 'suspended'] as $id => $status) {
            $db->table('tenants')->insert(['id' => $id, 'name' => "Agency $id", 'slug' => "a$id", 'plan' => 'starter', 'status' => $status, 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
            $db->table('users')->insert(['id' => $id, 'tenant_id' => $id, 'name' => "Owner $id", 'email' => "o{$id}@example.com", 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        }
        PlatformRazorpay::$probe = static fn (): array => ['code' => 200, 'body' => []];
        PlatformRazorpay::save('rzp_test_AbCdEf123456', 'TestSecretValue0123456789', 'webhook_secret_123', 1);
    }

    protected function tearDown(): void { PlatformRazorpay::$probe = null; db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function sub(int $tenant, string $status, string $rid, ?string $end, int $amount = 149900, string $plan = 'starter'): int
    {
        $db = db_connect();
        $db->table('subscriptions')->insert(['tenant_id' => $tenant, 'razorpay_sub_id' => $rid, 'plan' => $plan, 'amount_paise' => $amount, 'billing_cycle' => 'monthly', 'status' => $status,
            'current_period_start' => $end ? date('Y-m-d H:i:s', strtotime($end . ' UTC') - 30 * 86400) : null, 'current_period_end' => $end, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        return (int) $db->insertID();
    }

    private function row(int $id): array { return db_connect()->table('subscriptions')->where('id', $id)->get()->getRowArray(); }
    private function at(int $days): string { return gmdate('Y-m-d H:i:s', self::NOW + $days * 86400); }

    // --- payment safety net ------------------------------------------------------------------------------------------------------------

    public function testPaidOrderNoticeOpensThePlanAndALateCallbackIsHarmless(): void
    {
        $id = $this->sub(1, 'created', 'order_A1', null);
        db_connect()->table('tenants')->where('id', 1)->update(['plan' => 'free']);

        $this->assertSame('activated', (new BillingService())->confirmOrderPaid('order_A1', 'pay_A1', 149900));
        $this->assertSame('active', $this->row($id)['status']);
        $this->assertSame('pay_A1', $this->row($id)['razorpay_sub_id']);
        $this->assertSame('starter', db_connect()->table('tenants')->where('id', 1)->get()->getRowArray()['plan']);

        // the same notice again, and then the browser's own callback arriving late: no error, nothing opened twice
        $this->assertSame('already', (new BillingService())->confirmOrderPaid('order_A1', 'pay_A1', 149900));
        $sig = hash_hmac('sha256', 'order_A1|pay_A1', PlatformRazorpay::keySecret());
        (new BillingService())->verifyPayment(1, 'order_A1', 'pay_A1', $sig, 'starter', 'monthly');
        $this->assertSame(1, db_connect()->table('subscriptions')->where('tenant_id', 1)->where('status', 'active')->countAllResults());
    }

    public function testAnUnderpaidOrUnknownOrderOpensNothing(): void
    {
        $id = $this->sub(1, 'created', 'order_B1', null);
        db_connect()->table('tenants')->where('id', 1)->update(['plan' => 'free']);
        $this->assertSame('amount_mismatch', (new BillingService())->confirmOrderPaid('order_B1', 'pay_B1', 100));
        $this->assertSame('created', $this->row($id)['status']);
        $this->assertSame('free', db_connect()->table('tenants')->where('id', 1)->get()->getRowArray()['plan']);
        $this->assertSame('unknown', (new BillingService())->confirmOrderPaid('order_nope', 'pay_x', 999999));
    }

    public function testARenewalStartsWhenTheRunningPeriodEnds(): void
    {
        $end = gmdate('Y-m-d H:i:s', time() + 10 * 86400);
        $this->sub(1, 'active', 'pay_old', $end);
        $new = $this->sub(1, 'created', 'order_C1', null);
        (new BillingService())->confirmOrderPaid('order_C1', 'pay_C1', 149900);
        $r = $this->row($new);
        $this->assertSame($end, $r['current_period_start'], 'no paid days are lost');
        $this->assertEqualsWithDelta(strtotime($end . ' UTC') + 30 * 86400, strtotime($r['current_period_end'] . ' UTC'), 5);
    }

    // --- end of a paid period -------------------------------------------------------------------------------------------------------------

    public function testAnEndedPaidPeriodGoesBackToFreeButARenewedOneDoesNot(): void
    {
        $gone = $this->sub(1, 'active', 'pay_gone', $this->at(-1));
        $old  = $this->sub(2, 'active', 'pay_old2', $this->at(-1));
        $new  = $this->sub(2, 'active', 'pay_new2', $this->at(20));
        $this->assertSame(1, (new SubscriptionLifecycle(null, self::NOW))->expire());
        $this->assertSame('downgraded', $this->row($gone)['status']);
        $this->assertSame('free', db_connect()->table('tenants')->where('id', 1)->get()->getRowArray()['plan']);
        $this->assertSame('downgraded', $this->row($old)['status']);
        $this->assertSame('active', $this->row($new)['status']);
        $this->assertSame('starter', db_connect()->table('tenants')->where('id', 2)->get()->getRowArray()['plan'], 'a renewed workspace keeps its plan');
    }

    public function testRunningPeriodsAndLateRecurringChargesAreLeftAlone(): void
    {
        $run = $this->sub(1, 'active', 'pay_run', $this->at(5));
        $rec = $this->sub(2, 'active', 'sub_recurring', $this->at(-1));   // legacy recurring: 3 days' grace for a late charge notice
        $this->assertSame(0, (new SubscriptionLifecycle(null, self::NOW))->expire());
        $this->assertSame('active', $this->row($run)['status']);
        $this->assertSame('active', $this->row($rec)['status']);
    }

    // --- reminders ----------------------------------------------------------------------------------------------------------------------------

    public function testRemindersGoOutOnceAndNeverToARenewedOrSuspendedWorkspace(): void
    {
        $sent = [];
        $mailer = function (string $to, string $subject) use (&$sent): bool { $sent[] = [$to, $subject]; return true; };
        $this->sub(1, 'active', 'pay_r1', $this->at(3));                                       // ends in 3 days -> d3
        $this->sub(2, 'active', 'pay_r2', $this->at(3)); $this->sub(2, 'active', 'pay_r2b', $this->at(33));   // already renewed -> silent
        $this->sub(3, 'active', 'pay_r3', $this->at(3));                                       // suspended workspace -> silent

        $life = new SubscriptionLifecycle($mailer, self::NOW);
        $this->assertSame(['sent' => 1, 'ended' => 0], $life->remind());
        $emails = array_column($sent, 0);
        $this->assertSame(['o1@example.com'], $emails, 'only the live, not-yet-renewed workspace is told');
        $this->assertStringContainsString('3 days', $sent[0][1]);
        $this->assertSame(1, db_connect()->table('notifications')->where('tenant_id', 1)->countAllResults());

        $sent = []; $life->remind();
        $this->assertSame([], $sent, 'a second run tells nobody again');
    }

    public function testTheEndedNoticeIsSentOnceAfterExpiry(): void
    {
        $sent = [];
        $mailer = function (string $to, string $subject) use (&$sent): bool { $sent[] = $subject; return true; };
        $this->sub(1, 'active', 'pay_e1', $this->at(-1));
        $life = new SubscriptionLifecycle($mailer, self::NOW);
        $life->expire(); $life->remind(); $life->remind();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('has ended', $sent[0]);
    }

    // --- the real webhook endpoint --------------------------------------------------------------------------------------------------------

    private function hook(array $payload, ?string $secret = 'webhook_secret_123'): void
    {
        $raw = json_encode($payload);
        $sig = hash_hmac('sha256', $raw, $secret ?? 'wrong');
        $this->withBody($raw)->withHeaders(['X-Razorpay-Signature' => $sig, 'Content-Type' => 'application/json'])->call('post', 'webhooks/razorpay')->assertStatus(200);
    }

    private function orderPaid(string $order, string $pay, int $amount): array
    {
        return ['event' => 'order.paid', 'payload' => ['order' => ['entity' => ['id' => $order, 'amount' => $amount, 'amount_paid' => $amount]], 'payment' => ['entity' => ['id' => $pay, 'order_id' => $order, 'amount' => $amount, 'status' => 'captured']]]];
    }

    public function testASignedOrderPaidWebhookOpensThePlanAndAForgedOneDoesNot(): void
    {
        $id = $this->sub(1, 'created', 'order_W1', null);
        db_connect()->table('tenants')->where('id', 1)->update(['plan' => 'free']);

        $this->hook($this->orderPaid('order_W1', 'pay_W1', 149900), null);          // wrong signature
        $this->assertSame('created', $this->row($id)['status']);

        $this->hook($this->orderPaid('order_W1', 'pay_W1', 149900));                // right signature
        $this->assertSame('active', $this->row($id)['status']);
        $this->assertSame('starter', db_connect()->table('tenants')->where('id', 1)->get()->getRowArray()['plan']);

        $this->hook($this->orderPaid('order_W1', 'pay_W1', 149900));                // Razorpay retries: still exactly one active period
        $this->assertSame(1, db_connect()->table('subscriptions')->where('tenant_id', 1)->where('status', 'active')->countAllResults());
    }

    public function testCheckoutWithoutRazorpayKeysSaysSoInsteadOfCrashing(): void
    {
        PlatformRazorpay::clear(1);
        $keys = ['RAZORPAY_MOCK_MODE' => 'false', 'RAZORPAY_KEY_ID' => '', 'RAZORPAY_KEY_SECRET' => ''];
        $old = [];
        foreach ($keys as $k => $v) { $old[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null, getenv($k)]; $_ENV[$k] = $_SERVER[$k] = $v; putenv("$k=$v"); }
        try {
            (new BillingService())->createOrder(1, 'starter', 'monthly');
            $this->fail('expected a refusal');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('not switched on', $e->getMessage());
        } finally {
            foreach ($old as $k => [$e, $sv, $g]) {
                $e === null ? ($_ENV[$k] = null) : ($_ENV[$k] = $e); $sv === null ? ($_SERVER[$k] = null) : ($_SERVER[$k] = $sv);
                if ($e === null) { unset($_ENV[$k]); } if ($sv === null) { unset($_SERVER[$k]); }
                $g === false ? putenv($k) : putenv("$k=$g");
            }
        }
        $this->assertSame(0, db_connect()->table('subscriptions')->where('tenant_id', 1)->where('status', 'created')->countAllResults(), 'no half-made order is left behind');
    }
}
