<?php

declare(strict_types=1);

namespace App\Services\Admin;

/**
 * The admin Subscriptions page: every paid / given period with what it cost the customer (price, GST, total), how long it has left, where the
 * money came from, which offer was used, and the GST invoice that belongs to it. Read-only.
 *
 * State of a row: paying (an active paid period) | complimentary (an active period at 0) | halted | pending (checkout started, not paid) | ended
 * (cancelled, downgraded, or a period that ran out). "Ending soon" is a flag on a running period that ends within 7 days.
 * Source: online (Razorpay), offline (recorded by hand), complimentary (given by hand at 0), legacy (an old recurring Razorpay subscription), pending.
 * Money: price = what the plan cost before GST; for rows without a recorded split the amount received is the price (GST was inside it).
 */
final class SubscriptionDirectoryService
{
    public const EXPIRING_DAYS = 7;
    public const VIEWS = ['all', 'paying', 'complimentary', 'ending', 'halted', 'pending', 'ended'];
    public const SORTS = ['newest', 'ending', 'amount', 'customer'];

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }

    /** @return array{rows:list<array>,total:int,page:int,per_page:int,summary:array} */
    public function list(array $p = []): array
    {
        $all = $this->build(); $summary = $this->summary($all);
        $q = mb_strtolower(trim((string) ($p['q'] ?? '')));
        $view = in_array($p['view'] ?? '', self::VIEWS, true) ? (string) $p['view'] : 'all';
        $plan = (string) ($p['plan'] ?? ''); $status = (string) ($p['status'] ?? ''); $source = (string) ($p['source'] ?? '');
        $rows = array_values(array_filter($all, function (array $r) use ($q, $view, $plan, $status, $source): bool {
            if ($plan !== '' && $r['plan'] !== $plan) { return false; }
            if ($status !== '' && $r['status'] !== $status) { return false; }
            if ($source !== '' && $r['source'] !== $source) { return false; }
            if (! $this->inView($r, $view)) { return false; }
            if ($q !== '') {
                $hay = mb_strtolower($r['tenant_name'] . ' ' . $r['owner_name'] . ' ' . $r['owner_email'] . ' ' . ($r['payment_ref'] ?? '') . ' ' . ($r['invoice']['number'] ?? '') . ' ' . ($r['offer']['label'] ?? '') . ' #' . $r['tenant_id']);
                if (! str_contains($hay, $q)) { return false; }
            }
            return true;
        }));
        $nullsLast = static fn ($a, $b) => ($a === null) <=> ($b === null) ?: $a <=> $b;
        usort($rows, match ($p['sort'] ?? 'newest') {
            'ending'   => fn ($a, $b) => $nullsLast($a['days_left'], $b['days_left']) ?: $b['id'] <=> $a['id'],
            'amount'   => fn ($a, $b) => $b['amount_paise'] <=> $a['amount_paise'] ?: $b['id'] <=> $a['id'],
            'customer' => fn ($a, $b) => strcasecmp($a['tenant_name'], $b['tenant_name']) ?: $b['id'] <=> $a['id'],
            default    => fn ($a, $b) => $b['id'] <=> $a['id'],
        });
        $per = max(5, min(1000, (int) ($p['per_page'] ?? 25))); $page = max(1, (int) ($p['page'] ?? 1));
        return ['rows' => array_slice($rows, ($page - 1) * $per, $per), 'total' => count($rows), 'page' => $page, 'per_page' => $per, 'summary' => $summary];
    }

    public function inView(array $r, string $view): bool
    {
        return match ($view) {
            'paying', 'complimentary', 'halted', 'pending', 'ended' => $r['state'] === $view,
            'ending' => $r['ending_soon'],
            default => true,
        };
    }

    public function summary(array $all): array
    {
        $now = $this->ts(); $cut = $now - 30 * 86400;
        $views = array_fill_keys(self::VIEWS, 0); $mrr = 0; $paid30 = ['n' => 0, 'price' => 0, 'gst' => 0, 'total' => 0]; $abandoned = 0; $ended30 = 0;
        foreach ($all as $r) {
            foreach (self::VIEWS as $v) { if ($this->inView($r, $v)) { $views[$v]++; } }
            if ($r['state'] === 'paying') { $mrr += $r['billing_cycle'] === 'annual' ? (int) round($r['price_paise'] / 12) : $r['price_paise']; }
            $started = $r['period_start'] ? strtotime($r['period_start'] . ' UTC') : strtotime($r['created_at'] . ' UTC');
            if ($r['amount_paise'] > 0 && in_array($r['status'], ['active', 'cancelled', 'downgraded'], true) && $started >= $cut) { $paid30['n']++; $paid30['price'] += $r['price_paise']; $paid30['gst'] += (int) ($r['gst_paise'] ?? 0); $paid30['total'] += $r['amount_paise']; }
            if ($r['state'] === 'pending' && strtotime($r['created_at'] . ' UTC') >= $now - 7 * 86400) { $abandoned++; }
            if ($r['state'] === 'ended' && $r['period_end'] && strtotime($r['period_end'] . ' UTC') >= $cut && $r['amount_paise'] > 0) { $ended30++; }
        }
        return ['views' => $views, 'mrr_paise' => $mrr, 'arr_paise' => $mrr * 12, 'last_30_days' => $paid30, 'abandoned_checkouts_7d' => $abandoned, 'ended_30d' => $ended30];
    }

    /** @return list<array> every subscription row, newest first */
    public function build(): array
    {
        $db = db_connect(); $now = $this->ts();
        $has = static fn (string $t): bool => $db->tableExists($t);
        $sql = 'SELECT s.id, s.tenant_id, t.name tenant_name, s.plan, s.billing_cycle, s.status, s.amount_paise, s.taxable_paise, s.gst_paise, s.gst_rate, s.list_paise, s.discount_paise, s.promotion_id,
                       s.current_period_start, s.current_period_end, s.razorpay_sub_id, s.created_at, t.referred_partner_id';
        $sql .= $has('platform_invoices') ? ', i.id inv_id, i.number inv_number' : ', NULL inv_id, NULL inv_number';
        $sql .= $has('coupon_redemptions') ? ', c.code coupon_code, r.discount_paise coupon_discount' : ', NULL coupon_code, NULL coupon_discount';
        $sql .= $has('promotions') ? ', pr.name promo_name, pr.badge promo_badge' : ', NULL promo_name, NULL promo_badge';
        $sql .= ' FROM subscriptions s LEFT JOIN tenants t ON t.id = s.tenant_id';
        if ($has('platform_invoices')) { $sql .= ' LEFT JOIN platform_invoices i ON i.subscription_id = s.id'; }
        if ($has('coupon_redemptions')) { $sql .= " LEFT JOIN coupon_redemptions r ON r.subscription_id = s.id AND r.status IN ('applied','pending') LEFT JOIN coupons c ON c.id = r.coupon_id"; }
        if ($has('promotions')) { $sql .= ' LEFT JOIN promotions pr ON pr.id = s.promotion_id'; }
        $sql .= ' ORDER BY s.id DESC';
        $subs = $db->query($sql)->getResultArray();

        $owners = [];
        foreach ($db->query("SELECT tenant_id, name, email FROM users WHERE deleted_at IS NULL ORDER BY (role = 'owner') DESC, id")->getResultArray() as $u) { $owners[(int) $u['tenant_id']] ??= $u; }
        $partners = $has('partners') ? array_column($db->query('SELECT id, name FROM partners')->getResultArray(), 'name', 'id') : [];

        $rows = [];
        foreach ($subs as $s) {
            $amount = (int) $s['amount_paise']; $ref = (string) $s['razorpay_sub_id'];
            $start = $s['current_period_start'] ? strtotime($s['current_period_start'] . ' UTC') : null; $end = $s['current_period_end'] ? strtotime($s['current_period_end'] . ' UTC') : null;
            $running = $s['status'] === 'active' && ($end === null || $end >= $now);
            $state = match (true) {
                in_array($s['status'], ['created', 'authenticated'], true) => 'pending',
                $s['status'] === 'halted' => 'halted',
                $running => $amount > 0 ? 'paying' : 'complimentary',
                default => 'ended',
            };
            $source = match (true) {
                $state === 'pending' => 'pending',
                str_starts_with($ref, 'admin-') => $amount > 0 ? 'offline' : 'complimentary',
                str_starts_with($ref, 'sub_') => 'legacy',
                default => 'online',
            };
            $split = $s['taxable_paise'] !== null;
            $price = $split ? (int) $s['taxable_paise'] : $amount;
            $daysLeft = $running && $end !== null ? (int) ceil(($end - $now) / 86400) : null;
            $progress = $running && $start !== null && $end !== null && $end > $start ? max(0, min(100, (int) round((($now - $start) / ($end - $start)) * 100))) : null;
            $offer = null;
            if ($s['coupon_code']) { $offer = ['kind' => 'coupon', 'label' => (string) $s['coupon_code'], 'discount_paise' => (int) ($s['coupon_discount'] ?? $s['discount_paise'] ?? 0)]; }
            elseif ($s['promo_name']) { $offer = ['kind' => 'promotion', 'label' => (string) ($s['promo_badge'] ?: $s['promo_name']), 'discount_paise' => (int) ($s['discount_paise'] ?? 0)]; }
            $o = $owners[(int) $s['tenant_id']] ?? null;
            $rows[] = ['id' => (int) $s['id'], 'tenant_id' => (int) $s['tenant_id'], 'tenant_name' => (string) ($s['tenant_name'] ?? ('#' . $s['tenant_id'])), 'owner_name' => (string) ($o['name'] ?? ''), 'owner_email' => (string) ($o['email'] ?? ''),
                'plan' => (string) $s['plan'], 'billing_cycle' => $s['billing_cycle'] ?: 'monthly', 'status' => (string) $s['status'], 'state' => $state, 'source' => $source,
                'amount_paise' => $amount, 'price_paise' => $price, 'gst_paise' => $split ? (int) $s['gst_paise'] : null, 'gst_rate' => $s['gst_rate'] !== null ? (float) $s['gst_rate'] : null, 'gst_basis' => $amount <= 0 ? null : ($split ? 'extra' : 'included'),
                'list_paise' => $s['list_paise'] !== null ? (int) $s['list_paise'] : null, 'offer' => $offer,
                'period_start' => $s['current_period_start'], 'period_end' => $s['current_period_end'], 'days_left' => $daysLeft, 'progress' => $progress,
                'ending_soon' => $running && $end !== null && $end <= $now + self::EXPIRING_DAYS * 86400,
                'payment_ref' => $source === 'offline' || $source === 'complimentary' ? null : $ref, 'invoice' => $s['inv_id'] ? ['id' => (int) $s['inv_id'], 'number' => (string) $s['inv_number']] : null,
                'partner' => $s['referred_partner_id'] ? ($partners[(int) $s['referred_partner_id']] ?? null) : null, 'created_at' => (string) $s['created_at']];
        }
        return $rows;
    }
}
