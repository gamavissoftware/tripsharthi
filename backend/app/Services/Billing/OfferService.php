<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Prices a purchase with the platform's offers. The SERVER decides what is charged: the amount of the Razorpay Order comes
 * from quote(), never from the browser, so a customer cannot make up a discount.
 *
 *  - A coupon code (typed) or a promotion (automatic, time-boxed) may apply; they do NOT stack - the larger discount wins
 *    (a tie goes to the coupon, so a partner's code keeps its attribution).
 *  - Multi-payment coupons (duration_periods N, or 0 = every renewal) keep applying to that customer's later orders by
 *    themselves (continuingCoupon) without typing the code again.
 *  - Never below MIN_CHARGE_PAISE (Razorpay rejects orders under Re 1); a fully free period is a GRANT, not a coupon.
 *  - A coupon is RESERVED (pending) when the order is created and APPLIED when the signed payment is verified.
 *
 * User-facing refusals are \DomainException with a safe message ("not valid" never says whether a code exists).
 */
final class OfferService
{
    public const PLANS            = ['starter', 'growth', 'pro'];
    public const MIN_CHARGE_PAISE = 100;
    public const MAX_PERCENT      = 90;
    private const NOT_VALID       = 'This code is not valid for this plan, or it has expired.';

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(?int $t = null): string { return date('Y-m-d H:i:s', $t ?? $this->ts()); }

    public static function normalizeCode(string $code): string { return strtoupper(trim($code)); }
    public static function isValidCode(string $code): bool { return (bool) preg_match('/^[A-Z0-9][A-Z0-9_-]{2,29}$/', $code); }

    /** Discount in paise for a percent/flat rule on a list price; the customer always pays at least MIN_CHARGE_PAISE. */
    public static function discountFor(string $type, int $value, int $listPaise): int
    {
        $raw = $type === 'percent' ? (int) round($listPaise * min(self::MAX_PERCENT, $value) / 100) : $value;
        return max(0, min($raw, $listPaise - self::MIN_CHARGE_PAISE));
    }

    /**
     * @return array{list_paise:int,discount_paise:int,charged_paise:int,source:?string,coupon_id:?int,coupon_code:?string,promotion_id:?int,partner_id:?int,label:?string,notice:?string,continuing:bool}
     * @throws \DomainException when a typed code cannot be used
     */
    public function quote(int $tenantId, string $plan, string $cycle, int $listPaise, ?string $code = null): array
    {
        $out = ['list_paise' => $listPaise, 'discount_paise' => 0, 'charged_paise' => $listPaise, 'source' => null, 'coupon_id' => null, 'coupon_code' => null,
                'promotion_id' => null, 'partner_id' => null, 'label' => null, 'notice' => null, 'continuing' => false];
        $promo   = $this->bestPromotion($plan, $cycle, $listPaise);
        $typed   = self::normalizeCode((string) $code);
        $coupon  = $typed !== '' ? $this->eligibleCoupon($tenantId, $plan, $cycle, $typed) : null;
        $keeps   = $typed === '' ? $this->continuingCoupon($tenantId, $plan, $cycle, $listPaise) : null;
        $coupon ??= $keeps;
        $cDisc = $coupon ? self::discountFor($coupon['type'], (int) $coupon['value'], $listPaise) : 0;
        $pDisc = $promo ? self::discountFor('percent', (int) $promo['percent'], $listPaise) : 0;

        if ($coupon && $cDisc > 0 && $cDisc >= $pDisc) {
            $out = array_merge($out, ['discount_paise' => $cDisc, 'charged_paise' => $listPaise - $cDisc, 'source' => 'coupon', 'coupon_id' => (int) $coupon['id'], 'coupon_code' => $coupon['code'],
                'partner_id' => $coupon['partner_id'] !== null ? (int) $coupon['partner_id'] : null, 'label' => 'Code ' . $coupon['code'], 'continuing' => $keeps !== null]);
        } elseif ($promo && $pDisc > 0) {
            $out = array_merge($out, ['discount_paise' => $pDisc, 'charged_paise' => $listPaise - $pDisc, 'source' => 'promotion', 'promotion_id' => (int) $promo['id'], 'label' => $promo['badge'] ?: $promo['name']]);
            if ($coupon) { $out['notice'] = 'The ' . ($promo['badge'] ?: $promo['name']) . ' offer is better than code ' . $coupon['code'] . ', so it was applied instead (offers do not combine).'; }
        }
        return $out;
    }

    /** The best active promotion for a plan/cycle (largest discount), or null. */
    public function bestPromotion(string $plan, string $cycle, int $listPaise): ?array
    {
        $now = $this->stamp(); $best = null; $bestDisc = 0;
        $rows = db_connect()->table('promotions')->where('active', 1)->where('starts_at <=', $now)->where('ends_at >', $now)->get()->getResultArray();
        foreach ($rows as $p) {
            if (! $this->matches($p['plans'], $p['cycle'], $plan, $cycle)) { continue; }
            $d = self::discountFor('percent', (int) $p['percent'], $listPaise);
            if ($d > $bestDisc) { $best = $p; $bestDisc = $d; }
        }
        return $best;
    }

    /** @throws \DomainException */
    private function eligibleCoupon(int $tenantId, string $plan, string $cycle, string $code): array
    {
        if (! self::isValidCode($code)) { throw new \DomainException(self::NOT_VALID); }
        $db = db_connect(); $now = $this->stamp();
        $c  = $db->table('coupons')->where('code', $code)->get()->getRowArray();
        if (! $c || (int) $c['active'] !== 1
            || ($c['starts_at'] !== null && $c['starts_at'] > $now) || ($c['expires_at'] !== null && $c['expires_at'] <= $now)
            || ! $this->matches($c['plans'], $c['cycle'], $plan, $cycle)
            || ($c['max_redemptions'] !== null && (int) $c['redeemed_count'] >= (int) $c['max_redemptions'])) {
            throw new \DomainException(self::NOT_VALID);
        }
        $used = (int) $db->table('coupon_redemptions')->where('coupon_id', (int) $c['id'])->where('tenant_id', $tenantId)->where('status', 'applied')->countAllResults();
        $multi = (int) $c['duration_periods'] !== 1;
        if (($multi && $used > 0) || (! $multi && $used >= (int) $c['max_per_tenant'])) { throw new \DomainException('You have already used this code.'); }
        if ((int) $c['new_customers_only'] === 1 && $this->hasPaidBefore($tenantId)) { throw new \DomainException('This code is for new customers only.'); }
        return $c;
    }

    /** A running multi-payment coupon (duration_periods N or 0) that still has payments left for this customer. */
    private function continuingCoupon(int $tenantId, string $plan, string $cycle, int $listPaise): ?array
    {
        $rows = db_connect()->query("SELECT c.*, COUNT(r.id) AS used_n FROM coupons c JOIN coupon_redemptions r ON r.coupon_id = c.id AND r.tenant_id = ? AND r.status = 'applied'
                                      WHERE c.active = 1 AND c.duration_periods <> 1 GROUP BY c.id HAVING (c.duration_periods = 0 OR COUNT(r.id) < c.duration_periods)", [$tenantId])->getResultArray();
        $best = null; $bestDisc = 0;
        foreach ($rows as $c) {
            if (! $this->matches($c['plans'], $c['cycle'], $plan, $cycle)) { continue; }
            $d = self::discountFor($c['type'], (int) $c['value'], $listPaise);
            if ($d > $bestDisc) { $best = $c; $bestDisc = $d; }
        }
        return $best;
    }

    private function matches(?string $plansJson, string $ruleCycle, string $plan, string $cycle): bool
    {
        $plans = $plansJson === null || $plansJson === '' ? null : json_decode($plansJson, true);
        if (is_array($plans) && $plans !== [] && ! in_array($plan, $plans, true)) { return false; }
        return $ruleCycle === 'any' || $ruleCycle === $cycle;
    }

    /** "New customer" = has never completed a paid (non-manual) subscription. */
    private function hasPaidBefore(int $tenantId): bool
    {
        return db_connect()->table('subscriptions')->where('tenant_id', $tenantId)->whereIn('status', ['active', 'cancelled', 'halted', 'downgraded'])
            ->where('amount_paise >', 0)->where('razorpay_sub_id NOT LIKE', 'admin-%')->countAllResults() > 0;
    }

    // ---- reserve / apply ---------------------------------------------------------------------------------------------------------------

    /** Records the discount against the order row (status pending). Earlier unpaid reservations of the customer are voided. */
    public function reserve(int $tenantId, int $subscriptionId, string $plan, string $cycle, array $quote): void
    {
        $db = db_connect();
        $db->table('coupon_redemptions')->where('tenant_id', $tenantId)->where('status', 'pending')->update(['status' => 'void']);
        if ($quote['source'] !== 'coupon' || $quote['coupon_id'] === null) { return; }
        $db->table('coupon_redemptions')->insert(['coupon_id' => $quote['coupon_id'], 'tenant_id' => $tenantId, 'subscription_id' => $subscriptionId, 'partner_id' => $quote['partner_id'],
            'plan' => $plan, 'cycle' => $cycle, 'list_paise' => $quote['list_paise'], 'discount_paise' => $quote['discount_paise'], 'charged_paise' => $quote['charged_paise'],
            'status' => 'pending', 'created_at' => $this->stamp()]);
    }

    /** Called once the payment signature is verified: the reservation becomes a redemption and counts toward the coupon's limits. */
    public function confirm(int $tenantId, int $subscriptionId): void
    {
        $db = db_connect();
        $r  = $db->table('coupon_redemptions')->where('tenant_id', $tenantId)->where('subscription_id', $subscriptionId)->where('status', 'pending')->get()->getRowArray();
        if (! $r) { return; }
        $first = (int) $db->table('coupon_redemptions')->where('coupon_id', (int) $r['coupon_id'])->where('tenant_id', $tenantId)->where('status', 'applied')->countAllResults() === 0;
        $db->transStart();
        $db->table('coupon_redemptions')->where('id', (int) $r['id'])->update(['status' => 'applied', 'applied_at' => $this->stamp()]);
        if ($first) { $db->query('UPDATE coupons SET redeemed_count = redeemed_count + 1, updated_at = ? WHERE id = ?', [$this->stamp(), (int) $r['coupon_id']]); }   // counts CUSTOMERS, not payments
        $db->transComplete();
    }
}
