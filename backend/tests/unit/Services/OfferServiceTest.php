<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\BillingService;
use App\Services\Billing\OfferService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Pricing rules for coupons and promotions, against real MySQL (group "mysql"). */
#[Group('mysql')]
final class OfferServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const LIST = 399_900;                       // Growth monthly, in paise

    protected function setUp(): void
    {
        parent::setUp();
        putenv('RAZORPAY_MOCK_MODE=true'); $_ENV['RAZORPAY_MOCK_MODE'] = 'true'; $_SERVER['RAZORPAY_MOCK_MODE'] = 'true';
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['coupon_redemptions', 'coupons', 'promotions', 'offer_grants', 'subscriptions', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1 => 'Alpha', 2 => 'Beta'] as $id => $n) { $db->table('tenants')->insert(['id' => $id, 'name' => $n, 'slug' => 'w' . $id, 'plan' => 'free', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
    }

    private function coupon(array $o = []): int
    {
        $db = db_connect();
        $db->table('coupons')->insert($o + ['code' => 'SAVE20', 'type' => 'percent', 'value' => 20, 'plans' => null, 'cycle' => 'any', 'duration_periods' => 1, 'max_redemptions' => null, 'max_per_tenant' => 1,
            'new_customers_only' => 0, 'starts_at' => null, 'expires_at' => null, 'active' => 1, 'redeemed_count' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        return (int) $db->insertID();
    }

    private function promo(array $o = []): int
    {
        $db = db_connect();
        $db->table('promotions')->insert($o + ['name' => 'Festive', 'badge' => 'Festive 15% off', 'percent' => 15, 'plans' => null, 'cycle' => 'any', 'starts_at' => date('Y-m-d H:i:s', time() - 3600),
            'ends_at' => date('Y-m-d H:i:s', time() + 3600), 'active' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        return (int) $db->insertID();
    }

    private function quote(string $code = '', int $tenant = 1, string $plan = 'growth', string $cycle = 'monthly', int $list = self::LIST): array
    {
        return (new OfferService())->quote($tenant, $plan, $cycle, $list, $code);
    }

    /** Runs a purchase to completion through the real checkout, so redemptions are applied exactly as in production. */
    private function pay(int $tenant, string $code = '', string $plan = 'growth', string $cycle = 'monthly'): array
    {
        $svc = new BillingService();
        $o = $svc->createOrder($tenant, $plan, $cycle, $code);
        $svc->verifyPayment($tenant, $o['order_id'], 'pay_' . bin2hex(random_bytes(4)), 'sig', $plan, $cycle);
        return $o;
    }

    // ---- arithmetic -----------------------------------------------------------------------------------------------------------------------

    public function testDiscountMathRoundsAndNeverGoesBelowOneRupee(): void
    {
        $this->assertSame(79_980, OfferService::discountFor('percent', 20, self::LIST));
        $this->assertSame(50_000, OfferService::discountFor('flat', 50_000, self::LIST));
        $this->assertSame(self::LIST - 100, OfferService::discountFor('flat', 9_999_999, self::LIST), 'a huge flat discount still leaves the Re 1 minimum');
        $this->assertSame((int) round(self::LIST * 0.9), OfferService::discountFor('percent', 100, self::LIST), 'percent is capped at 90');
        $this->assertSame(0, OfferService::discountFor('percent', 50, 100), 'nothing to discount on a Re 1 price');
    }

    public function testWithoutOffersThePriceIsTheListPrice(): void
    {
        $q = $this->quote();
        $this->assertSame([self::LIST, 0, self::LIST, null], [$q['list_paise'], $q['discount_paise'], $q['charged_paise'], $q['source']]);
    }

    // ---- promotions -----------------------------------------------------------------------------------------------------------------------

    public function testAPromotionAppliesAutomaticallyOnlyInsideItsWindowAndForItsPlans(): void
    {
        $this->promo(['plans' => json_encode(['growth']), 'cycle' => 'monthly']);
        $q = $this->quote();
        $this->assertSame(['promotion', 59_985, 'Festive 15% off'], [$q['source'], $q['discount_paise'], $q['label']]);
        $this->assertNull($this->quote('', 1, 'starter', 'monthly', 149_900)['source'], 'other plan');
        $this->assertNull($this->quote('', 1, 'growth', 'annual', 3_839_000)['source'], 'other cycle');

        db_connect()->table('promotions')->update(['starts_at' => date('Y-m-d H:i:s', time() + 600), 'ends_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->assertNull($this->quote()['source'], 'not started');
        db_connect()->table('promotions')->update(['starts_at' => date('Y-m-d H:i:s', time() - 7200), 'ends_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertNull($this->quote()['source'], 'ended');
        db_connect()->table('promotions')->update(['starts_at' => date('Y-m-d H:i:s', time() - 60), 'ends_at' => date('Y-m-d H:i:s', time() + 3600), 'active' => 0]);
        $this->assertNull($this->quote()['source'], 'switched off');
    }

    public function testTheBestOfSeveralPromotionsWins(): void
    {
        $this->promo(['percent' => 10, 'badge' => 'Ten']);
        $this->promo(['percent' => 25, 'badge' => 'Twenty-five']);
        $q = $this->quote();
        $this->assertSame(['Twenty-five', 99_975], [$q['label'], $q['discount_paise']]);
    }

    // ---- coupons ---------------------------------------------------------------------------------------------------------------------------

    public function testAValidCouponDiscountsTheOrderAndIsCaseInsensitive(): void
    {
        $this->coupon();
        $q = $this->quote(' save20 ');
        $this->assertSame(['coupon', 79_980, self::LIST - 79_980, 'SAVE20'], [$q['source'], $q['discount_paise'], $q['charged_paise'], $q['coupon_code']]);
    }

    public function testEveryReasonACodeCannotBeUsedGivesTheSameGenericMessage(): void
    {
        $now = date('Y-m-d H:i:s');
        $cases = [
            'unknown'      => fn () => 'NOSUCH',
            'inactive'     => function () { $this->coupon(['code' => 'OFF', 'active' => 0]); return 'OFF'; },
            'expired'      => function () { $this->coupon(['code' => 'OLD', 'expires_at' => date('Y-m-d H:i:s', time() - 60)]); return 'OLD'; },
            'not started'  => function () { $this->coupon(['code' => 'SOON', 'starts_at' => date('Y-m-d H:i:s', time() + 600)]); return 'SOON'; },
            'wrong plan'   => function () { $this->coupon(['code' => 'STARTONLY', 'plans' => json_encode(['starter'])]); return 'STARTONLY'; },
            'wrong cycle'  => function () { $this->coupon(['code' => 'YEARLY', 'cycle' => 'annual']); return 'YEARLY'; },
            'used up'      => function () { $this->coupon(['code' => 'GONE', 'max_redemptions' => 3, 'redeemed_count' => 3]); return 'GONE'; },
            'bad format'   => fn () => '!!',
        ];
        $messages = [];
        foreach ($cases as $why => $make) {
            $code = $make();
            try { $this->quote($code); $this->fail("{$why}: should have been refused"); }
            catch (\DomainException $e) { $messages[$why] = $e->getMessage(); }
        }
        $this->assertCount(1, array_unique($messages), 'the message must not reveal whether a code exists or why it failed: ' . json_encode($messages));
        $this->assertStringContainsString('not valid', reset($messages));
    }

    public function testACouponCanBeUsedOnlyUpToItsPerCustomerLimit(): void
    {
        $this->coupon();
        $this->pay(1, 'SAVE20');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already used');
        $this->quote('SAVE20', 1);
    }

    public function testTotalUsesCountCustomersAndStopAtTheLimit(): void
    {
        $this->coupon(['max_redemptions' => 1]);
        $this->pay(1, 'SAVE20');
        $this->assertSame(1, (int) db_connect()->table('coupons')->get()->getRowArray()['redeemed_count']);
        $this->expectException(\DomainException::class);
        $this->quote('SAVE20', 2);                                     // a second customer is turned away
    }

    public function testNewCustomersOnlyCodesRefuseCustomersWhoPaidBeforeButNotAdminGrants(): void
    {
        $this->coupon(['new_customers_only' => 1]);
        $db = db_connect();
        $db->table('subscriptions')->insert(['tenant_id' => 2, 'razorpay_sub_id' => 'admin-20260101', 'plan' => 'starter', 'amount_paise' => 0, 'billing_cycle' => 'monthly', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->assertSame('coupon', $this->quote('SAVE20', 2)['source'], 'a free trial given by the team does not make them an existing customer');
        $db->table('subscriptions')->insert(['tenant_id' => 1, 'razorpay_sub_id' => 'pay_old', 'plan' => 'starter', 'amount_paise' => 149_900, 'billing_cycle' => 'monthly', 'status' => 'cancelled', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('new customers');
        $this->quote('SAVE20', 1);
    }

    public function testCouponAndPromotionDoNotStackTheLargerWinsAndATieGoesToTheCoupon(): void
    {
        $this->promo(['percent' => 30, 'badge' => 'Big sale']);
        $this->coupon(['value' => 20]);
        $q = $this->quote('SAVE20');
        $this->assertSame('promotion', $q['source']);
        $this->assertStringContainsString('SAVE20', (string) $q['notice'], 'the customer is told their code was not applied and why');

        db_connect()->table('promotions')->update(['percent' => 20]);
        $this->assertSame('coupon', $this->quote('SAVE20')['source'], 'a tie keeps the coupon (partner attribution)');
        db_connect()->table('coupons')->update(['value' => 40]);
        $this->assertSame(['coupon', 159_960], [$this->quote('SAVE20')['source'], $this->quote('SAVE20')['discount_paise']]);
    }

    // ---- reserve / confirm ----------------------------------------------------------------------------------------------------------------

    public function testTheOrderIsPricedByTheServerAndTheCouponIsOnlyAppliedAfterPayment(): void
    {
        $this->coupon();
        $order = (new BillingService())->createOrder(1, 'growth', 'monthly', 'SAVE20');
        $this->assertSame(self::LIST - 79_980, $order['amount']);
        $this->assertSame(79_980, $order['discount']);
        $sub = db_connect()->table('subscriptions')->where('tenant_id', 1)->get()->getRowArray();
        $this->assertSame(self::LIST - 79_980, (int) $sub['amount_paise'], 'the subscription row stores what was charged');
        $r = db_connect()->table('coupon_redemptions')->get()->getRowArray();
        $this->assertSame(['pending', 79_980], [$r['status'], (int) $r['discount_paise']]);
        $this->assertSame(0, (int) db_connect()->table('coupons')->get()->getRowArray()['redeemed_count'], 'an unpaid order does not use up the code');

        (new BillingService())->verifyPayment(1, $order['order_id'], 'pay_1', 'sig', 'pro', 'annual');   // the request cannot change what was bought
        $this->assertSame('applied', db_connect()->table('coupon_redemptions')->get()->getRowArray()['status']);
        $this->assertSame(1, (int) db_connect()->table('coupons')->get()->getRowArray()['redeemed_count']);
    }

    public function testAnAbandonedOrderIsVoidedAndTheCodeCanBeTriedAgain(): void
    {
        $this->coupon();
        (new BillingService())->createOrder(1, 'growth', 'monthly', 'SAVE20');                           // abandoned checkout
        $o2 = (new BillingService())->createOrder(1, 'growth', 'monthly', 'SAVE20');                     // tries again
        $st = array_column(db_connect()->table('coupon_redemptions')->orderBy('id')->get()->getResultArray(), 'status');
        $this->assertSame(['void', 'pending'], $st);
        (new BillingService())->verifyPayment(1, $o2['order_id'], 'pay_2', 'sig', 'growth', 'monthly');
        $this->assertSame(1, (int) db_connect()->table('coupons')->get()->getRowArray()['redeemed_count']);
    }

    public function testAnUnusableCodeFailsTheOrderBeforeAnythingIsCreated(): void
    {
        try { (new BillingService())->createOrder(1, 'growth', 'monthly', 'NOSUCH'); $this->fail('refused'); }
        catch (\DomainException) { /* expected */ }
        $this->assertSame(0, db_connect()->table('subscriptions')->countAllResults(), 'no order row for a failed quote');
    }

    // ---- multi-payment coupons -----------------------------------------------------------------------------------------------------------

    public function testAMultiPaymentCouponKeepsApplyingToRenewalsUntilItsPaymentsAreUsed(): void
    {
        $this->coupon(['duration_periods' => 3]);
        $this->pay(1, 'SAVE20');
        $q = $this->quote('', 1);                                     // renewal #2, no code typed
        $this->assertSame(['coupon', true], [$q['source'], $q['continuing']]);
        $this->pay(1);                                                 // second discounted payment, automatically
        $this->assertSame('coupon', $this->quote('', 1)['source'], 'third payment still discounted');
        $this->pay(1);
        $this->assertNull($this->quote('', 1)['source'], 'all 3 payments used');
        $this->assertSame(1, (int) db_connect()->table('coupons')->get()->getRowArray()['redeemed_count'], 'counts customers, not payments');
    }

    public function testAnEveryRenewalCouponNeverEndsAndSwitchingItOffStopsIt(): void
    {
        $this->coupon(['duration_periods' => 0]);
        for ($i = 0; $i < 4; $i++) { $this->pay(1, $i === 0 ? 'SAVE20' : ''); }
        $this->assertSame('coupon', $this->quote('', 1)['source']);
        db_connect()->table('coupons')->update(['active' => 0]);
        $this->assertNull($this->quote('', 1)['source']);
    }

    public function testASinglePaymentCouponDoesNotContinue(): void
    {
        $this->coupon(['duration_periods' => 1]);
        $this->pay(1, 'SAVE20');
        $this->assertNull($this->quote('', 1)['source']);
    }

    public function testAMultiPaymentCodeCanBeStartedOnlyOnce(): void
    {
        $this->coupon(['duration_periods' => 3, 'max_per_tenant' => 5]);
        $this->pay(1, 'SAVE20');
        $this->expectException(\DomainException::class);
        $this->quote('SAVE20', 1);
    }

    public function testAPartnerCodeCarriesItsPartnerIntoTheRedemption(): void
    {
        $this->coupon(['partner_id' => 77]);
        $this->assertSame(77, $this->quote('SAVE20')['partner_id']);
        $this->pay(1, 'SAVE20');
        $this->assertSame(77, (int) db_connect()->table('coupon_redemptions')->get()->getRowArray()['partner_id']);
    }
}
