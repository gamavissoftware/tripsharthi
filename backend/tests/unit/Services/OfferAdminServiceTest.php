<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\OfferAdminService;
use App\Services\Billing\BillingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** What the platform team can do with coupons, promotions and free-day grants (group "mysql"). */
#[Group('mysql')]
final class OfferAdminServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('RAZORPAY_MOCK_MODE=true'); $_ENV['RAZORPAY_MOCK_MODE'] = 'true'; $_SERVER['RAZORPAY_MOCK_MODE'] = 'true';
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['coupon_redemptions', 'coupons', 'promotions', 'offer_grants', 'subscriptions', 'audit_logs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1 => 'HQ', 2 => 'Sunrise Tours'] as $id => $n) { $db->table('tenants')->insert(['id' => $id, 'name' => $n, 'slug' => 'w' . $id, 'plan' => 'free', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Admin', 'email' => 'a@example.com', 'password_hash' => 'x', 'role' => 'owner', 'is_platform_admin' => 1, 'created_at' => '2026-01-01 00:00:00']);
    }

    private function svc(): OfferAdminService { return new OfferAdminService(); }

    // ---- coupons -----------------------------------------------------------------------------------------------------------------------------

    public function testCreatingACouponNormalisesTheCodeAndStoresRupeesAsPaise(): void
    {
        $c = $this->svc()->saveCoupon(['code' => ' diwali-500 ', 'type' => 'flat', 'value_rs' => 500, 'plans' => ['growth', 'pro'], 'cycle' => 'annual', 'duration_periods' => 1, 'expires_at' => '2026-12-31'], null, 1);
        $this->assertSame(['DIWALI-500', 'flat', 50_000, ['growth', 'pro'], 'annual'], [$c['code'], $c['type'], $c['value'], $c['plans'], $c['cycle']]);
        $this->assertSame('2026-12-31 18:29:59', $c['expires_at'], 'a date-only expiry runs to the end of that day in INDIA time (stored as UTC)');
        $this->assertSame('live', $c['state']);
        $this->assertSame(1, (int) db_connect()->table('audit_logs')->where('action', 'admin.coupon.create')->countAllResults());
    }

    public function testAllThreePlansMeansEveryPlan(): void
    {
        $c = $this->svc()->saveCoupon(['code' => 'ALL', 'type' => 'percent', 'value' => 10, 'plans' => ['starter', 'growth', 'pro']], null, 1);
        $this->assertSame([], $c['plans']);
        $this->assertNull(db_connect()->table('coupons')->get()->getRowArray()['plans']);
    }

    /** @return array<string,array{0:array,1:string}> */
    public static function badCoupons(): array
    {
        return [
            'percent too high'     => [['code' => 'AAA', 'type' => 'percent', 'value' => 95], 'between 1 and 90'],
            'percent zero'         => [['code' => 'AAA', 'type' => 'percent', 'value' => 0], 'between 1 and 90'],
            'flat under a rupee'   => [['code' => 'AAA', 'type' => 'flat', 'value_rs' => 0.5], 'between ₹1'],
            'bad type'             => [['code' => 'AAA', 'type' => 'free', 'value' => 10], 'percent or flat'],
            'short code'           => [['code' => 'A', 'type' => 'percent', 'value' => 10], '3-30 characters'],
            'code with a space'    => [['code' => 'TWO WORDS', 'type' => 'percent', 'value' => 10], '3-30 characters'],
            'unknown plan'         => [['code' => 'AAA', 'type' => 'percent', 'value' => 10, 'plans' => ['gold']], "Unknown plan 'gold'"],
            'bad cycle'            => [['code' => 'AAA', 'type' => 'percent', 'value' => 10, 'cycle' => 'weekly'], 'monthly or annual'],
            'expiry before start'  => [['code' => 'AAA', 'type' => 'percent', 'value' => 10, 'starts_at' => '2026-12-01', 'expires_at' => '2026-11-01'], 'after the start'],
            'garbage date'         => [['code' => 'AAA', 'type' => 'percent', 'value' => 10, 'expires_at' => 'next friday'], 'not a valid date'],
            'impossible date'      => [['code' => 'AAA', 'type' => 'percent', 'value' => 10, 'expires_at' => '2026-02-31'], 'not a valid date'],
            'zero total uses'      => [['code' => 'AAA', 'type' => 'percent', 'value' => 10, 'max_redemptions' => 0], 'between 1 and 10,00,000'],
            'absurd duration'      => [['code' => 'AAA', 'type' => 'percent', 'value' => 10, 'duration_periods' => 500], 'every renewal'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badCoupons')]
    public function testBadCouponInputIsRefusedWithAReadableReason(array $in, string $needle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($needle);
        $this->svc()->saveCoupon($in, null, 1);
    }

    public function testADuplicateCodeIsRefused(): void
    {
        $this->svc()->saveCoupon(['code' => 'SAME', 'type' => 'percent', 'value' => 10], null, 1);
        $this->expectException(\DomainException::class);
        $this->svc()->saveCoupon(['code' => 'same', 'type' => 'percent', 'value' => 20], null, 1);
    }

    public function testOnceACodeHasBeenUsedItsPriceRuleIsFrozenButItCanStillBeRetired(): void
    {
        $c = $this->svc()->saveCoupon(['code' => 'FROZEN', 'type' => 'percent', 'value' => 20, 'duration_periods' => 1], null, 1);
        $b = new BillingService(); $o = $b->createOrder(2, 'growth', 'monthly', 'FROZEN');              // reserved: already counts as in use
        $u = $this->svc()->saveCoupon(['value' => 90, 'type' => 'flat', 'plans' => ['pro'], 'duration_periods' => 0, 'description' => 'new text', 'active' => false], $c['id'], 1);
        $this->assertSame(['percent', 20, [], 1], [$u['type'], $u['value'], $u['plans'], $u['duration_periods']], 'the price rule did not change');
        $this->assertSame(['new text', false], [$u['description'], $u['active']], 'the description and on/off switch did change');
        $b->verifyPayment(2, $o['order_id'], 'pay_x', 'sig', 'growth', 'monthly');                      // still honoured: the customer paid for it
        $this->assertSame(1, (int) db_connect()->table('coupons')->get()->getRowArray()['redeemed_count']);
    }

    public function testTotalUsesCannotBeLoweredBelowWhoHasAlreadyUsedIt(): void
    {
        $c = $this->svc()->saveCoupon(['code' => 'LIMITED', 'type' => 'percent', 'value' => 10, 'max_redemptions' => 5], null, 1);
        db_connect()->table('coupons')->where('id', $c['id'])->update(['redeemed_count' => 3]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already used');
        $this->svc()->saveCoupon(['max_redemptions' => 2], $c['id'], 1);
    }

    public function testCouponStatesAndRevenueFigures(): void
    {
        $live = $this->svc()->saveCoupon(['code' => 'LIVE', 'type' => 'percent', 'value' => 10], null, 1);
        $this->svc()->saveCoupon(['code' => 'OFF', 'type' => 'percent', 'value' => 10, 'active' => false], null, 1);
        $this->svc()->saveCoupon(['code' => 'EXPIRED', 'type' => 'percent', 'value' => 10, 'expires_at' => '2020-01-01'], null, 1);
        $this->svc()->saveCoupon(['code' => 'LATER', 'type' => 'percent', 'value' => 10, 'starts_at' => '2099-01-01'], null, 1);
        $states = array_column($this->svc()->coupons(), 'state', 'code'); ksort($states);
        $this->assertSame(['EXPIRED' => 'expired', 'LATER' => 'scheduled', 'LIVE' => 'live', 'OFF' => 'inactive'], $states);

        $b = new BillingService(); $o = $b->createOrder(2, 'starter', 'monthly', 'LIVE'); $b->verifyPayment(2, $o['order_id'], 'pay_r', 'sig', 'starter', 'monthly');
        $row = array_values(array_filter($this->svc()->coupons(), fn ($c) => $c['code'] === 'LIVE'))[0];
        $this->assertSame([1, 14_990, 149_900 - 14_990, 1], [$row['payments'], $row['discount_paise'], $row['revenue_paise'], $row['redeemed_count']]);
        $red = $this->svc()->couponRedemptions($live['id']);
        $this->assertSame(['Sunrise Tours', 'applied', 14_990], [$red[0]['tenant_name'], $red[0]['status'], (int) $red[0]['discount_paise']]);
    }

    // ---- promotions -------------------------------------------------------------------------------------------------------------------------

    public function testPromotionsAreValidatedAndReportTheirState(): void
    {
        $p = $this->svc()->savePromotion(['name' => 'Festive', 'badge' => 'Festive 20% off', 'percent' => 20, 'plans' => ['growth'], 'starts_at' => date('Y-m-d', time() - 86400), 'ends_at' => date('Y-m-d', time() + 86400)], null, 1);
        $this->assertSame(['live', 20, ['growth']], [$p['state'], $p['percent'], $p['plans']]);
        $off = $this->svc()->savePromotion(['active' => false], $p['id'], 1);
        $this->assertSame('inactive', $off['state']);

        foreach ([
            [['name' => '', 'percent' => 10, 'starts_at' => '2026-01-01', 'ends_at' => '2026-02-01'], 'name'],
            [['name' => 'X', 'percent' => 99, 'starts_at' => '2026-01-01', 'ends_at' => '2026-02-01'], 'between 1 and 90'],
            [['name' => 'X', 'percent' => 10, 'starts_at' => '2026-03-01', 'ends_at' => '2026-02-01'], 'after the start'],
            [['name' => 'X', 'percent' => 10, 'ends_at' => '2026-02-01'], 'starts'],
        ] as [$in, $needle]) {
            try { $this->svc()->savePromotion($in, null, 1); $this->fail("should refuse: {$needle}"); }
            catch (\InvalidArgumentException $e) { $this->assertStringContainsStringIgnoringCase($needle, $e->getMessage()); }
        }
    }

    // ---- free days --------------------------------------------------------------------------------------------------------------------------

    public function testATrialGivesAPlanlessCustomerAPlanThatExpiresOnItsOwn(): void
    {
        $g = $this->svc()->grant(2, 'trial', 14, 'growth', 'Demo for Sunrise Tours', 1);
        $this->assertSame(['trial', 'growth', 14], [$g['kind'], $g['plan'], (int) $g['days']]);
        $t = db_connect()->table('tenants')->where('id', 2)->get()->getRowArray();
        $this->assertSame('growth', $t['plan']);
        $sub = db_connect()->table('subscriptions')->where('tenant_id', 2)->get()->getRowArray();
        $this->assertSame(['active', 0], [$sub['status'], (int) $sub['amount_paise']]);
        $this->assertStringStartsWith('admin-', $sub['razorpay_sub_id'], 'a manual row: subscription:check ends it on its own');
        $this->assertEqualsWithDelta(time() + 14 * 86400, strtotime($sub['current_period_end']), 5);
    }

    public function testATrialIsRefusedWhenThereIsAlreadyARunningPlan(): void
    {
        $this->svc()->grant(2, 'trial', 14, 'starter', 'first trial', 1);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Extend');
        $this->svc()->grant(2, 'trial', 14, 'pro', 'second trial', 1);
    }

    public function testAnExtensionAddsDaysToTheRunningPeriodAndKeepsThePlan(): void
    {
        $this->svc()->grant(2, 'trial', 10, 'growth', 'trial first', 1);
        $before = db_connect()->table('subscriptions')->where('tenant_id', 2)->get()->getRowArray()['current_period_end'];
        $g = $this->svc()->grant(2, 'extension', 7, '', 'Goodwill after an outage', 1);
        $after = db_connect()->table('subscriptions')->where('tenant_id', 2)->get()->getRowArray()['current_period_end'];
        $this->assertSame(strtotime($before) + 7 * 86400, strtotime($after));
        $this->assertSame(['extension', 'growth', $before, $after], [$g['kind'], $g['plan'], $g['period_end_before'], $g['period_end_after']]);
        $this->assertSame('growth', db_connect()->table('tenants')->where('id', 2)->get()->getRowArray()['plan']);
        $this->assertSame(1, (int) db_connect()->table('audit_logs')->where('action', 'admin.grant.extension')->countAllResults());
    }

    public function testAnExtensionNeedsARunningPlan(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no running plan');
        $this->svc()->grant(2, 'extension', 7, '', 'nothing to extend', 1);
    }

    public function testGrantInputIsValidated(): void
    {
        foreach ([[2, 'trial', 0, 'growth', 'a good reason'], [2, 'trial', 400, 'growth', 'a good reason'], [2, 'gift', 5, 'growth', 'a good reason'], [2, 'trial', 5, 'gold', 'a good reason'], [2, 'trial', 5, 'growth', 'x']] as $a) {
            try { $this->svc()->grant($a[0], $a[1], $a[2], $a[3], $a[4], 1); $this->fail('should refuse ' . json_encode($a)); }
            catch (\InvalidArgumentException) { /* expected */ }
        }
        $this->expectException(\OutOfBoundsException::class);
        $this->svc()->grant(999, 'trial', 5, 'growth', 'a good reason', 1);
    }
}
