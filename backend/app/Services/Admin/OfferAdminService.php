<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Billing\OfferService;
use App\Services\Crm\AuditLogger;

/**
 * What the TripSarthi team does with offers: coupon codes, plan promotions and free-day grants. Every change is audited.
 * Exceptions: \InvalidArgumentException = bad input (422), \OutOfBoundsException = not found (404), \DomainException = not allowed now (409).
 * Money in the API is RUPEES for flat coupons (stored as paise).
 */
final class OfferAdminService
{
    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(?int $t = null): string { return date('Y-m-d H:i:s', $t ?? $this->ts()); }

    /** The tenant the audit row belongs to: the acting admin's own workspace (looked up, so it never depends on the request). */
    private function tid(int $adminId): ?int
    {
        $r = db_connect()->table('users')->select('tenant_id')->where('id', $adminId)->get()->getRowArray();
        return $r ? (int) $r['tenant_id'] : null;
    }

    // ---- coupons ---------------------------------------------------------------------------------------------------------------------------

    public function coupons(): array
    {
        $db = db_connect(); $rows = $db->table('coupons')->orderBy('id', 'DESC')->get()->getResultArray();
        $agg = [];
        foreach ($db->query("SELECT coupon_id, COUNT(*) payments, COALESCE(SUM(discount_paise),0) discount, COALESCE(SUM(charged_paise),0) revenue FROM coupon_redemptions WHERE status = 'applied' GROUP BY coupon_id")->getResultArray() as $a) { $agg[(int) $a['coupon_id']] = $a; }
        return array_map(fn (array $c) => $this->couponView($c, $agg[(int) $c['id']] ?? null), $rows);
    }

    public function saveCoupon(array $in, ?int $id, int $adminId): array
    {
        $db = db_connect(); $existing = null;
        if ($id !== null) { $existing = $db->table('coupons')->where('id', $id)->get()->getRowArray() ?: throw new \OutOfBoundsException('Coupon not found.'); }
        $locked = $existing !== null && (int) $db->table('coupon_redemptions')->where('coupon_id', $id)->whereIn('status', ['pending', 'applied'])->countAllResults() > 0;

        $row = [];
        $row['description'] = array_key_exists('description', $in) ? (mb_substr(trim((string) $in['description']), 0, 200) ?: null) : ($existing['description'] ?? null);
        $row['active']      = array_key_exists('active', $in) ? (int) ! empty($in['active']) : (int) ($existing['active'] ?? 1);
        $row['starts_at']   = array_key_exists('starts_at', $in) ? $this->dt($in['starts_at'], 'Start date') : ($existing['starts_at'] ?? null);
        $row['expires_at']  = array_key_exists('expires_at', $in) ? $this->dt($in['expires_at'], 'Expiry date', true) : ($existing['expires_at'] ?? null);
        if ($row['starts_at'] !== null && $row['expires_at'] !== null && $row['expires_at'] <= $row['starts_at']) { throw new \InvalidArgumentException('The expiry must be after the start.'); }
        $max = array_key_exists('max_redemptions', $in) ? $in['max_redemptions'] : ($existing['max_redemptions'] ?? null);
        $row['max_redemptions'] = ($max === null || $max === '') ? null : (int) $max;
        if ($row['max_redemptions'] !== null && ($row['max_redemptions'] < 1 || $row['max_redemptions'] > 1_000_000)) { throw new \InvalidArgumentException('Total uses must be between 1 and 10,00,000 (or empty for unlimited).'); }
        if ($existing !== null && $row['max_redemptions'] !== null && $row['max_redemptions'] < (int) $existing['redeemed_count']) { throw new \InvalidArgumentException('Total uses cannot be below the ' . $existing['redeemed_count'] . ' customers who already used it.'); }

        if ($existing === null || ! $locked) {              // the price rule can only change while nobody has used (or started paying with) the code
            $type = $in['type'] ?? ($existing['type'] ?? 'percent');
            if (! in_array($type, ['percent', 'flat'], true)) { throw new \InvalidArgumentException('Choose percent or flat.'); }
            if ($type === 'percent') {
                $value = (int) ($in['value'] ?? $existing['value'] ?? 0);
                if ($value < 1 || $value > OfferService::MAX_PERCENT) { throw new \InvalidArgumentException('Percent must be between 1 and ' . OfferService::MAX_PERCENT . '. A fully free period is a grant, not a coupon.'); }
            } else {
                $value = array_key_exists('value_rs', $in) ? (int) round(((float) $in['value_rs']) * 100) : (int) ($existing['value'] ?? 0);
                if ($value < 100 || $value > 100_000_000) { throw new \InvalidArgumentException('A flat discount must be between ₹1 and ₹10,00,000.'); }
            }
            $plans = $in['plans'] ?? (isset($existing['plans']) && $existing['plans'] ? json_decode($existing['plans'], true) : []);
            $row['type'] = $type; $row['value'] = $value; $row['plans'] = $this->plans($plans);
            $cycle = $in['cycle'] ?? ($existing['cycle'] ?? 'any');
            if (! in_array($cycle, ['any', 'monthly', 'annual'], true)) { throw new \InvalidArgumentException('Choose any, monthly or annual.'); }
            $row['cycle'] = $cycle;
            $dur = (int) ($in['duration_periods'] ?? $existing['duration_periods'] ?? 1);
            if ($dur < 0 || $dur > 120) { throw new \InvalidArgumentException('Duration must be 0 (every renewal) or 1 to 120 payments.'); }
            $row['duration_periods'] = $dur;
            $perTenant = (int) ($in['max_per_tenant'] ?? $existing['max_per_tenant'] ?? 1);
            if ($perTenant < 1 || $perTenant > 100) { throw new \InvalidArgumentException('Uses per customer must be between 1 and 100.'); }
            $row['max_per_tenant'] = $perTenant;
            $row['new_customers_only'] = array_key_exists('new_customers_only', $in) ? (int) ! empty($in['new_customers_only']) : (int) ($existing['new_customers_only'] ?? 0);
        }

        if (array_key_exists('partner_id', $in)) {                                    // a partner's code: the sale is attributed to (and earns a commission for) that partner
            $pid = $in['partner_id'] === null || $in['partner_id'] === '' ? null : (int) $in['partner_id'];
            if ($pid !== null && $db->table('partners')->where('id', $pid)->countAllResults() === 0) { throw new \InvalidArgumentException('That partner does not exist.'); }
            if ($locked && $pid !== ($existing['partner_id'] !== null ? (int) $existing['partner_id'] : null)) { throw new \DomainException('This code has been used, so its partner cannot be changed.'); }
            $row['partner_id'] = $pid;
        }

        $row['updated_at'] = $this->stamp();
        if ($existing === null) {
            $code = OfferService::normalizeCode((string) ($in['code'] ?? ''));
            if (! OfferService::isValidCode($code)) { throw new \InvalidArgumentException('A code is 3-30 characters: letters, numbers, - or _.'); }
            if ($db->table('coupons')->where('code', $code)->countAllResults() > 0) { throw new \DomainException("The code {$code} already exists."); }
            $db->table('coupons')->insert($row + ['code' => $code, 'created_by' => $adminId, 'created_at' => $this->stamp()]);
            $id = (int) $db->insertID();
            AuditLogger::log('admin.coupon.create', 'coupon', $id, null, ['code' => $code, 'type' => $row['type'], 'value' => $row['value']], $this->tid($adminId), $adminId);
        } else {
            $db->table('coupons')->where('id', $id)->update($row);
            AuditLogger::log('admin.coupon.update', 'coupon', $id, null, array_diff_key($row, ['updated_at' => 1]), $this->tid($adminId), $adminId);
        }
        return $this->couponView($db->table('coupons')->where('id', $id)->get()->getRowArray(), null);
    }

    public function couponRedemptions(int $id): array
    {
        $db = db_connect();
        $db->table('coupons')->where('id', $id)->countAllResults() ?: throw new \OutOfBoundsException('Coupon not found.');
        return $db->query("SELECT r.id, r.tenant_id, t.name tenant_name, r.plan, r.cycle, r.list_paise, r.discount_paise, r.charged_paise, r.status, r.created_at, r.applied_at
                             FROM coupon_redemptions r LEFT JOIN tenants t ON t.id = r.tenant_id WHERE r.coupon_id = ? AND r.status <> 'void' ORDER BY r.id DESC LIMIT 200", [$id])->getResultArray();
    }

    private function couponView(array $c, ?array $agg): array
    {
        $now = $this->stamp();
        $state = (int) $c['active'] !== 1 ? 'inactive' : (($c['expires_at'] !== null && $c['expires_at'] <= $now) ? 'expired' : (($c['starts_at'] !== null && $c['starts_at'] > $now) ? 'scheduled'
                : (($c['max_redemptions'] !== null && (int) $c['redeemed_count'] >= (int) $c['max_redemptions']) ? 'used_up' : 'live')));
        return ['id' => (int) $c['id'], 'code' => $c['code'], 'description' => $c['description'], 'type' => $c['type'], 'value' => (int) $c['value'], 'plans' => $c['plans'] ? json_decode($c['plans'], true) : [],
            'cycle' => $c['cycle'], 'duration_periods' => (int) $c['duration_periods'], 'max_redemptions' => $c['max_redemptions'] !== null ? (int) $c['max_redemptions'] : null, 'max_per_tenant' => (int) $c['max_per_tenant'],
            'new_customers_only' => (bool) $c['new_customers_only'], 'starts_at' => $c['starts_at'], 'expires_at' => $c['expires_at'], 'partner_id' => $c['partner_id'] !== null ? (int) $c['partner_id'] : null,
            'active' => (bool) $c['active'], 'state' => $state, 'redeemed_count' => (int) $c['redeemed_count'], 'created_at' => $c['created_at'],
            'payments' => $agg ? (int) $agg['payments'] : null, 'discount_paise' => $agg ? (int) $agg['discount'] : null, 'revenue_paise' => $agg ? (int) $agg['revenue'] : null];
    }

    // ---- promotions -----------------------------------------------------------------------------------------------------------------------

    public function promotions(): array
    {
        $now = $this->stamp();
        return array_map(function (array $p) use ($now) {
            $state = (int) $p['active'] !== 1 ? 'inactive' : ($p['ends_at'] <= $now ? 'ended' : ($p['starts_at'] > $now ? 'scheduled' : 'live'));
            return ['id' => (int) $p['id'], 'name' => $p['name'], 'badge' => $p['badge'], 'percent' => (int) $p['percent'], 'plans' => $p['plans'] ? json_decode($p['plans'], true) : [], 'cycle' => $p['cycle'],
                'starts_at' => $p['starts_at'], 'ends_at' => $p['ends_at'], 'active' => (bool) $p['active'], 'state' => $state];
        }, db_connect()->table('promotions')->orderBy('id', 'DESC')->get()->getResultArray());
    }

    public function savePromotion(array $in, ?int $id, int $adminId): array
    {
        $db = db_connect(); $existing = null;
        if ($id !== null) { $existing = $db->table('promotions')->where('id', $id)->get()->getRowArray() ?: throw new \OutOfBoundsException('Promotion not found.'); }
        $name = trim((string) ($in['name'] ?? $existing['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) { throw new \InvalidArgumentException('Give the promotion a name (up to 120 characters).'); }
        $percent = (int) ($in['percent'] ?? $existing['percent'] ?? 0);
        if ($percent < 1 || $percent > OfferService::MAX_PERCENT) { throw new \InvalidArgumentException('Percent must be between 1 and ' . OfferService::MAX_PERCENT . '.'); }
        $cycle = $in['cycle'] ?? ($existing['cycle'] ?? 'any');
        if (! in_array($cycle, ['any', 'monthly', 'annual'], true)) { throw new \InvalidArgumentException('Choose any, monthly or annual.'); }
        $starts = $this->dt($in['starts_at'] ?? $existing['starts_at'] ?? null, 'Start', false) ?? throw new \InvalidArgumentException('Choose when the promotion starts.');
        $ends   = $this->dt($in['ends_at'] ?? $existing['ends_at'] ?? null, 'End', true) ?? throw new \InvalidArgumentException('Choose when the promotion ends.');
        if ($ends <= $starts) { throw new \InvalidArgumentException('The end must be after the start.'); }
        $plans = $in['plans'] ?? ($existing && $existing['plans'] ? json_decode($existing['plans'], true) : []);
        $row = ['name' => $name, 'badge' => mb_substr(trim((string) ($in['badge'] ?? $existing['badge'] ?? '')), 0, 40) ?: null, 'percent' => $percent, 'plans' => $this->plans($plans), 'cycle' => $cycle,
                'starts_at' => $starts, 'ends_at' => $ends, 'active' => array_key_exists('active', $in) ? (int) ! empty($in['active']) : (int) ($existing['active'] ?? 1), 'updated_at' => $this->stamp()];
        if ($existing === null) { $db->table('promotions')->insert($row + ['created_by' => $adminId, 'created_at' => $this->stamp()]); $id = (int) $db->insertID(); AuditLogger::log('admin.promotion.create', 'promotion', $id, null, ['name' => $name, 'percent' => $percent], $this->tid($adminId), $adminId); }
        else { $db->table('promotions')->where('id', $id)->update($row); AuditLogger::log('admin.promotion.update', 'promotion', $id, null, ['name' => $name, 'percent' => $percent, 'active' => $row['active']], $this->tid($adminId), $adminId); }
        return array_values(array_filter($this->promotions(), fn ($p) => $p['id'] === $id))[0];
    }

    // ---- free days ------------------------------------------------------------------------------------------------------------------------

    public function grants(int $limit = 100): array
    {
        return db_connect()->query('SELECT g.id, g.tenant_id, t.name tenant_name, g.kind, g.plan, g.days, g.reason, g.period_end_before, g.period_end_after, g.granted_by, u.name granted_by_name, g.created_at
                                      FROM offer_grants g LEFT JOIN tenants t ON t.id = g.tenant_id LEFT JOIN users u ON u.id = g.granted_by ORDER BY g.id DESC LIMIT ' . max(1, min(500, $limit)))->getResultArray();
    }

    /**
     * kind 'trial': give a customer WITHOUT a running plan N free days of a plan (a normal admin-given subscription that expires on its own).
     * kind 'extension': add N days to a customer's CURRENT running period (a paying customer keeps their plan and gets longer).
     */
    public function grant(int $tenantId, string $kind, int $days, string $plan, string $reason, int $adminId): array
    {
        if (! in_array($kind, ['trial', 'extension'], true)) { throw new \InvalidArgumentException('Choose a trial or an extension.'); }
        if ($days < 1 || $days > 365) { throw new \InvalidArgumentException('Days must be between 1 and 365.'); }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 300) { throw new \InvalidArgumentException('Give a reason (5-300 characters) - it is kept in the audit log.'); }
        $db = db_connect();
        $t = $db->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->get()->getRowArray() ?: throw new \OutOfBoundsException('Customer not found.');
        $now = $this->stamp();
        $running = $db->table('subscriptions')->where('tenant_id', $tenantId)->where('status', 'active')->where('current_period_end >', $now)->orderBy('id', 'DESC')->get()->getRowArray();

        if ($kind === 'trial') {
            if (! in_array($plan, OfferService::PLANS, true)) { throw new \InvalidArgumentException('Choose the plan to trial.'); }
            if ($running) { throw new \DomainException('This customer already has a running plan (until ' . substr($running['current_period_end'], 0, 10) . '). Use "Extend" to add days instead.'); }
            (new PlatformAdminService())->setPlan($tenantId, $plan, $days, 0.0, $adminId, false, 'Free trial grant: ' . $reason);
            $after = date('Y-m-d H:i:s', $this->ts() + $days * 86400); $before = null;
        } else {
            if (! $running) { throw new \DomainException('This customer has no running plan to extend. Use "Free trial" to give them a plan.'); }
            $before = $running['current_period_end']; $after = date('Y-m-d H:i:s', strtotime($before) + $days * 86400);
            $db->table('subscriptions')->where('id', (int) $running['id'])->update(['current_period_end' => $after, 'updated_at' => $now]);
            $plan = $running['plan'];
            AuditLogger::log('admin.grant.extension', 'tenant', $tenantId, ['period_end' => $before], ['period_end' => $after, 'days' => $days, 'reason' => $reason], $tenantId, $adminId);
        }
        $db->table('offer_grants')->insert(['tenant_id' => $tenantId, 'kind' => $kind, 'plan' => $plan, 'days' => $days, 'reason' => $reason, 'period_end_before' => $before, 'period_end_after' => $after, 'granted_by' => $adminId, 'created_at' => $now]);
        $gid = (int) $db->insertID();
        return array_values(array_filter($this->grants(20), fn ($g) => (int) $g['id'] === $gid))[0] ?? ['id' => $gid];
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------------------------

    /** @return ?string JSON list of plan keys, null = every plan */
    private function plans(mixed $plans): ?string
    {
        if (! is_array($plans) || $plans === []) { return null; }
        foreach ($plans as $p) { if (! in_array($p, OfferService::PLANS, true)) { throw new \InvalidArgumentException("Unknown plan '{$p}'."); } }
        $u = array_values(array_unique($plans));
        return count($u) === count(OfferService::PLANS) ? null : json_encode($u);
    }

    /**
     * Admin-entered dates are INDIA time (the business day): 'YYYY-MM-DD' means the whole day, so a start is 00:00 IST and an expiry is
     * 23:59:59 IST of that date; 'YYYY-MM-DD HH:MM' is taken as IST too. Stored as UTC like every other timestamp.
     */
    private function dt(mixed $v, string $label, bool $endOfDay = false): ?string
    {
        if ($v === null || $v === '') { return null; }
        $s = str_replace('T', ' ', trim((string) $v));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) { $s .= $endOfDay ? ' 23:59:59' : ' 00:00:00'; }
        elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) { $s .= ':00'; }
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) { throw new \InvalidArgumentException("{$label} is not a valid date."); }
        try { $d = new \DateTimeImmutable($s, new \DateTimeZone('Asia/Kolkata')); } catch (\Throwable) { throw new \InvalidArgumentException("{$label} is not a valid date."); }
        if ($d->format('Y-m-d H:i:s') !== $s) { throw new \InvalidArgumentException("{$label} is not a valid date."); }          // 2026-02-31 and friends
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
