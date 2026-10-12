<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\OfferAdminService;
use App\Services\Admin\SubscriptionDirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** The admin Subscriptions page data and the offers headline numbers (group "mysql"). */
#[Group('mysql')]
final class SubscriptionDirectoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = strtotime('2026-10-11 12:00:00 UTC');
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['coupon_redemptions', 'coupons', 'promotions', 'offer_grants', 'platform_credit_notes', 'platform_invoices', 'subscriptions', 'partners', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $d = fn (int $days) => date('Y-m-d H:i:s', $this->now - $days * 86400);
        $f = fn (int $days) => date('Y-m-d H:i:s', $this->now + $days * 86400);
        foreach ([1 => 'Alpha Travels', 2 => 'Bravo Tours', 3 => 'Charlie Trips', 4 => 'Delta Holidays'] as $id => $name) {
            $db->table('tenants')->insert(['id' => $id, 'name' => $name, 'slug' => "t$id", 'plan' => 'growth', 'status' => 'active', 'mode' => 'saas', 'created_at' => $d(90)]);
            $db->table('users')->insert(['id' => $id, 'tenant_id' => $id, 'name' => "Owner $id", 'email' => "o{$id}@example.com", 'password_hash' => 'x', 'role' => 'owner', 'created_at' => $d(90)]);
        }
        $db->table('promotions')->insert(['id' => 1, 'name' => 'Festive 2026', 'badge' => 'Festive 15% off', 'percent' => 15, 'plans' => null, 'cycle' => 'any', 'starts_at' => $d(10), 'ends_at' => $f(10), 'active' => 1, 'created_at' => $d(10), 'updated_at' => $d(10)]);
        $db->table('coupons')->insert(['id' => 1, 'code' => 'SAVE20', 'type' => 'percent', 'value' => 20, 'plans' => null, 'cycle' => 'any', 'duration_periods' => 1, 'max_per_tenant' => 1, 'new_customers_only' => 0, 'active' => 1, 'redeemed_count' => 1, 'created_at' => $d(10), 'updated_at' => $d(10)]);
        $sub = fn (array $x) => $db->table('subscriptions')->insert($x + ['razorpay_customer_id' => 'c', 'billing_cycle' => 'monthly', 'created_at' => $d(5), 'updated_at' => $d(5)]);
        $sub(['tenant_id' => 1, 'razorpay_sub_id' => 'pay_1', 'plan' => 'growth', 'amount_paise' => 471_882, 'taxable_paise' => 399_900, 'gst_paise' => 71_982, 'gst_rate' => 18, 'status' => 'active', 'current_period_start' => $d(5), 'current_period_end' => $f(25)]);                 // paying, GST extra
        $sub(['tenant_id' => 2, 'razorpay_sub_id' => 'pay_2', 'plan' => 'growth', 'amount_paise' => 359_910, 'taxable_paise' => 305_000, 'gst_paise' => 54_910, 'gst_rate' => 18, 'list_paise' => 359_910, 'discount_paise' => 54_000, 'promotion_id' => 1, 'status' => 'active', 'current_period_start' => $d(27), 'current_period_end' => $f(3)]);   // promo, ending soon
        $sub(['tenant_id' => 3, 'razorpay_sub_id' => 'admin-1', 'plan' => 'pro', 'amount_paise' => 0, 'status' => 'active', 'current_period_start' => $d(2), 'current_period_end' => $f(12)]);                                          // complimentary
        $sub(['tenant_id' => 4, 'razorpay_sub_id' => 'sub_9', 'plan' => 'growth', 'amount_paise' => 399_900, 'status' => 'halted', 'current_period_start' => $d(40), 'current_period_end' => $d(10)]);                                // halted (legacy recurring)
        $sub(['tenant_id' => 4, 'razorpay_sub_id' => 'order_X', 'plan' => 'growth', 'amount_paise' => 471_882, 'status' => 'created']);                                                                                                // abandoned checkout
        $sub(['tenant_id' => 1, 'razorpay_sub_id' => 'pay_old', 'plan' => 'starter', 'amount_paise' => 59_000, 'status' => 'downgraded', 'current_period_start' => $d(70), 'current_period_end' => $d(40)]);                       // ended, GST inside
        $sub(['tenant_id' => 2, 'razorpay_sub_id' => 'admin-5', 'plan' => 'growth', 'amount_paise' => 118_000, 'status' => 'active', 'current_period_start' => $d(3), 'current_period_end' => $f(27)]);                              // offline, GST inside
        $db->table('coupon_redemptions')->insert(['coupon_id' => 1, 'tenant_id' => 2, 'subscription_id' => 7, 'plan' => 'growth', 'cycle' => 'monthly', 'list_paise' => 399_900, 'discount_paise' => 79_980, 'charged_paise' => 319_920, 'status' => 'applied', 'created_at' => $d(3), 'applied_at' => $d(3)]);
        $db->table('platform_invoices')->insert(['number' => 'GS/26-27/00001', 'series' => 'GS', 'fy' => '2026-27', 'seq' => 1, 'issue_date' => date('Y-m-d', $this->now), 'tenant_id' => 1, 'subscription_id' => 1, 'source' => 'online', 'plan' => 'growth', 'description' => 'x', 'buyer_type' => 'b2c', 'buyer_name' => 'Alpha', 'place_of_supply' => '06', 'supply_type' => 'intra', 'sac' => '998314', 'gst_rate' => 18,
            'taxable_value' => 399_900, 'cgst' => 35_991, 'sgst' => 35_991, 'igst' => 0, 'total' => 471_882, 'seller' => '{}', 'buyer' => '{}', 'created_at' => $d(5)]);
        $db->table('offer_grants')->insert(['tenant_id' => 3, 'kind' => 'trial', 'plan' => 'pro', 'days' => 14, 'reason' => 'demo for a prospect', 'period_end_after' => $f(12), 'granted_by' => 1, 'created_at' => $d(2)]);
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['coupon_redemptions', 'coupons', 'promotions', 'offer_grants'] as $t) { $db->table($t)->truncate(); }   // a live promotion left behind would change the prices other test classes expect
        $db->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown();
    }

    private function svc(): SubscriptionDirectoryService { return new SubscriptionDirectoryService($this->now); }
    private function row(int $id): array { foreach ($this->svc()->list(['per_page' => 100])['rows'] as $r) { if ($r['id'] === $id) { return $r; } } $this->fail("no row $id"); }

    public function testStatesSourcesAndMoneyBreakdown(): void
    {
        $a = $this->row(1);
        $this->assertSame(['paying', 'online', 'extra', 399_900, 71_982, 471_882, 25], [$a['state'], $a['source'], $a['gst_basis'], $a['price_paise'], $a['gst_paise'], $a['amount_paise'], $a['days_left']]);
        $this->assertSame('GS/26-27/00001', $a['invoice']['number']);
        $this->assertSame('complimentary', $this->row(3)['state']); $this->assertSame('complimentary', $this->row(3)['source']);
        $this->assertSame(['halted', 'legacy'], [$this->row(4)['state'], $this->row(4)['source']]);
        $this->assertSame(['pending', 'pending'], [$this->row(5)['state'], $this->row(5)['source']]);
        $this->assertSame('ended', $this->row(6)['state']); $this->assertSame('included', $this->row(6)['gst_basis']);
        $off = $this->row(7); $this->assertSame(['paying', 'offline', 'included', null], [$off['state'], $off['source'], $off['gst_basis'], $off['payment_ref']]);
        $this->assertSame(118_000, $off['price_paise'], 'with no recorded split the amount received is the price');
    }

    public function testOffersAreShownForCouponsAndPromotions(): void
    {
        $this->assertSame(['promotion', 'Festive 15% off', 54_000], [$this->row(2)['offer']['kind'], $this->row(2)['offer']['label'], $this->row(2)['offer']['discount_paise']]);
        $this->assertSame(['coupon', 'SAVE20', 79_980], [$this->row(7)['offer']['kind'], $this->row(7)['offer']['label'], $this->row(7)['offer']['discount_paise']]);
        $this->assertNull($this->row(1)['offer']);
    }

    public function testViewsSearchSortAndSummaryAgree(): void
    {
        $s = $this->svc(); $sum = $s->list(['per_page' => 100])['summary'];
        foreach (SubscriptionDirectoryService::VIEWS as $v) { $this->assertSame($sum['views'][$v], $s->list(['view' => $v, 'per_page' => 100])['total'], "view $v"); }
        $this->assertSame([2], array_column($s->list(['view' => 'ending'])['rows'], 'id'));
        $this->assertSame([5], array_column($s->list(['view' => 'pending'])['rows'], 'id'));
        $this->assertSame([7], array_column($s->list(['q' => 'save20'])['rows'], 'id'), 'search by coupon code');
        $this->assertSame([6, 1], array_column($s->list(['q' => 'o1@example.com'])['rows'], 'id'), 'search by the owner e-mail, newest first');
        $this->assertSame([3], array_column($s->list(['plan' => 'pro'])['rows'], 'id'));
        $this->assertSame(2, $s->list(['sort' => 'ending'])['rows'][0]['id'], 'soonest to end first');
        $this->assertSame(1, $s->list(['view' => 'paying', 'sort' => 'amount'])['rows'][0]['id'], 'highest amount first');
        $this->assertSame(399_900 + 305_000 + 118_000, $sum['mrr_paise'], 'MRR is the price before GST of the paying rows (the offline row has no split, so its amount is the price)');
        $this->assertSame(1, $sum['abandoned_checkouts_7d']);
        $this->assertSame(3, $sum['last_30_days']['n']);
    }

    public function testPromotionsAndOffersHeadlineNumbers(): void
    {
        $o = new OfferAdminService($this->now); $p = $o->promotions()[0];
        $this->assertSame([1, 54_000, 305_000], [$p['payments'], $p['discount_paise'], $p['revenue_paise']]);
        $s = $o->summary();
        $this->assertSame(1, $s['promotions']['live']); $this->assertSame(1, $s['coupons']['by_state']['live']);
        $this->assertSame([2, 54_000 + 79_980, 3], [$s['last_30_days']['orders_with_offer'], $s['last_30_days']['discount_paise'], $s['last_30_days']['paid_orders']], 'one promotion order + one coupon order, out of 3 paid orders');
        $this->assertSame([1, 14], [$s['grants_30d']['count'], $s['grants_30d']['days']]);
        $this->assertSame(['starter', 'growth', 'pro'], array_keys($s['prices'])); $this->assertSame(399_900, $s['prices']['growth']['monthly']);
        $this->assertSame('SAVE20', $s['top_coupons'][0]['code']);
    }
}
