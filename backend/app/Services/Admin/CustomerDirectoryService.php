<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Tenancy\FeatureGate;

/**
 * The admin app's Customers page: one row per workspace with who they are, what they pay, how much they use the product, how recently they
 * were active and what needs the team's attention - plus the headline numbers for the page. Everything is read-only.
 *
 * Built from a handful of grouped queries (not one query per workspace) and merged in PHP, so every filter, sort, flag and total comes from the
 * same computed rows and cannot disagree with each other.
 *
 * Billing state: paying (an active paid period, incl. one recorded by hand for an offline payment) | complimentary (an active period at 0) |
 * halted (the latest subscription is halted: payment failed) | lapsed (was paying, now free) | free.
 */
final class CustomerDirectoryService
{
    public const EXPIRING_DAYS     = 7;
    public const INACTIVE_DAYS     = 14;
    public const NOT_ACTIVATED_DAYS = 3;
    public const NEAR_LIMIT        = 0.9;

    public const SEGMENTS = ['all', 'paying', 'complimentary', 'free', 'lapsed', 'halted', 'expiring', 'inactive', 'not_activated', 'tickets', 'attention', 'suspended', 'referred', 'new'];
    public const SORTS    = ['newest', 'active', 'contacts', 'revenue', 'renewal', 'name'];

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(int $t): string { return date('Y-m-d H:i:s', $t); }

    /**
     * @param array{q?:string,plan?:string,status?:string,segment?:string,sort?:string,page?:int,per_page?:int} $p
     * @return array{rows:list<array>,total:int,page:int,per_page:int,summary:array}
     */
    public function list(array $p = []): array
    {
        $all = $this->build();
        $summary = $this->summary($all);
        $q = mb_strtolower(trim((string) ($p['q'] ?? '')));
        $plan = (string) ($p['plan'] ?? ''); $status = (string) ($p['status'] ?? '');
        $seg = in_array($p['segment'] ?? '', self::SEGMENTS, true) ? (string) $p['segment'] : 'all';
        $rows = array_values(array_filter($all, function (array $r) use ($q, $plan, $status, $seg): bool {
            if ($plan !== '' && $r['plan'] !== $plan) { return false; }
            if ($status !== '' && $r['status'] !== $status) { return false; }
            if ($q !== '') {
                $hay = mb_strtolower($r['name'] . ' ' . $r['slug'] . ' ' . $r['owner']['name'] . ' ' . $r['owner']['email'] . ' ' . $r['owner']['phone'] . ' #' . $r['id'] . ' ' . ($r['partner'] ?? ''));
                if (! str_contains($hay, $q)) { return false; }
            }
            return $this->inSegment($r, $seg);
        }));
        $this->sort($rows, in_array($p['sort'] ?? '', self::SORTS, true) ? (string) $p['sort'] : 'newest');
        $per = max(5, min(1000, (int) ($p['per_page'] ?? 25))); $page = max(1, (int) ($p['page'] ?? 1));
        return ['rows' => array_slice($rows, ($page - 1) * $per, $per), 'total' => count($rows), 'page' => $page, 'per_page' => $per, 'summary' => $summary];
    }

    /** One workspace's row (billing, flags, usage...) or null. */
    public function one(int $tenantId): ?array
    {
        foreach ($this->build() as $r) { if ($r['id'] === $tenantId) { return $r; } }
        return null;
    }

    public function inSegment(array $r, string $seg): bool
    {
        $f = array_column($r['flags'], 'key');
        return match ($seg) {
            'paying', 'complimentary', 'free', 'lapsed', 'halted' => $r['billing']['state'] === $seg,
            'expiring' => in_array('expiring', $f, true),
            'inactive' => in_array('inactive', $f, true),
            'not_activated' => in_array('not_activated', $f, true),
            'tickets' => $r['tickets_open'] > 0,
            'attention' => $r['attention'],
            'suspended' => $r['status'] !== 'active',
            'referred' => $r['partner'] !== null,
            'new' => $r['joined_days'] <= 7,
            default => true,
        };
    }

    private function sort(array &$rows, string $by): void
    {
        $nullsLast = static fn ($a, $b, bool $desc) => ($a === null) <=> ($b === null) ?: ($desc ? $b <=> $a : $a <=> $b);
        usort($rows, match ($by) {
            'active'   => fn ($a, $b) => $nullsLast($a['last_active'], $b['last_active'], true) ?: $b['id'] <=> $a['id'],
            'contacts' => fn ($a, $b) => $b['usage']['contacts'] <=> $a['usage']['contacts'] ?: $b['id'] <=> $a['id'],
            'revenue'  => fn ($a, $b) => $b['billing']['lifetime_paise'] <=> $a['billing']['lifetime_paise'] ?: $b['id'] <=> $a['id'],
            'renewal'  => fn ($a, $b) => $nullsLast($a['billing']['renews_at'], $b['billing']['renews_at'], false) ?: $b['id'] <=> $a['id'],
            'name'     => fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            default    => fn ($a, $b) => $b['id'] <=> $a['id'],
        });
    }

    /** Headline numbers + a count for every segment chip. */
    public function summary(array $all): array
    {
        $seg = array_fill_keys(self::SEGMENTS, 0); $mrr = 0; $life = 0;
        foreach ($all as $r) {
            foreach (self::SEGMENTS as $s) { if ($this->inSegment($r, $s)) { $seg[$s]++; } }
            if ($r['billing']['state'] === 'paying') { $mrr += $r['billing']['cycle'] === 'annual' ? (int) round($r['billing']['amount_paise'] / 12) : $r['billing']['amount_paise']; }
            $life += $r['billing']['lifetime_paise'];
        }
        return ['segments' => $seg, 'mrr_paise' => $mrr, 'lifetime_paise' => $life, 'new_30d' => count(array_filter($all, fn ($r) => $r['joined_days'] <= 30))];
    }

    /** @return list<array> every workspace, newest first */
    public function build(): array
    {
        $db = db_connect(); $now = $this->ts(); $nowS = $this->stamp($now);
        $has = static fn (string $t): bool => $db->tableExists($t);
        $by = static function (array $rows, string $key = 'tenant_id'): array { $o = []; foreach ($rows as $r) { $o[(int) $r[$key]] = $r; } return $o; };

        $tenants = $db->query('SELECT id, name, slug, plan, status, mode, created_at, referred_partner_id FROM tenants WHERE deleted_at IS NULL ORDER BY id DESC')->getResultArray();
        $users = $by($db->query('SELECT tenant_id, COUNT(*) n, MAX(last_login_at) last_login FROM users WHERE deleted_at IS NULL GROUP BY tenant_id')->getResultArray());
        $owners = [];
        foreach ($db->query("SELECT id, tenant_id, name, email, phone, role FROM users WHERE deleted_at IS NULL ORDER BY (role = 'owner') DESC, id")->getResultArray() as $u) { $owners[(int) $u['tenant_id']] ??= $u; }
        $count = static fn (string $t) => $has($t) ? array_map('intval', array_column($db->query("SELECT tenant_id, COUNT(*) n FROM {$t} WHERE deleted_at IS NULL GROUP BY tenant_id")->getResultArray(), 'n', 'tenant_id')) : [];
        $contacts = $count('contacts'); $trips = $count('trips'); $bookings = $count('bookings');

        $active = [];
        foreach ($db->query("SELECT id, tenant_id, plan, amount_paise, billing_cycle, current_period_end, razorpay_sub_id FROM subscriptions WHERE status = 'active' AND (current_period_end IS NULL OR current_period_end >= ?) ORDER BY id", [$nowS])->getResultArray() as $s) { $active[(int) $s['tenant_id']] = $s; }
        $lastStatus = array_column($db->query('SELECT tenant_id, status FROM subscriptions WHERE id IN (SELECT MAX(id) FROM subscriptions GROUP BY tenant_id)')->getResultArray(), 'status', 'tenant_id');
        $life = array_map('intval', array_column($db->query("SELECT tenant_id, SUM(amount_paise) s FROM subscriptions WHERE amount_paise > 0 AND status IN ('active','cancelled','downgraded') GROUP BY tenant_id")->getResultArray(), 's', 'tenant_id'));
        $tickets = $has('support_tickets') ? array_map('intval', array_column($db->query("SELECT tenant_id, COUNT(*) n FROM support_tickets WHERE status IN ('open','pending') GROUP BY tenant_id")->getResultArray(), 'n', 'tenant_id')) : [];
        $waba = [];
        if ($has('waba_accounts')) { foreach ($db->query('SELECT tenant_id, status FROM waba_accounts WHERE deleted_at IS NULL ORDER BY id')->getResultArray() as $w) { $waba[(int) $w['tenant_id']] = $w['status']; } }
        $partners = $has('partners') ? array_column($db->query('SELECT id, name FROM partners')->getResultArray(), 'name', 'id') : [];

        $rows = [];
        foreach ($tenants as $t) {
            $id = (int) $t['id']; $o = $owners[$id] ?? null; $a = $active[$id] ?? null;
            $joinedAt = strtotime((string) $t['created_at']); $joinedDays = (int) floor(($now - $joinedAt) / 86400);
            $lastLogin = $users[$id]['last_login'] ?? null; $lastTs = $lastLogin ? strtotime($lastLogin) : null;
            $lifetime = (int) ($life[$id] ?? 0);

            $amount = $a ? (int) $a['amount_paise'] : 0; $manual = $a && str_starts_with((string) $a['razorpay_sub_id'], PlatformAdminService::MANUAL_PREFIX);
            $state = $a ? ($amount > 0 ? 'paying' : 'complimentary') : (($lastStatus[$id] ?? '') === 'halted' ? 'halted' : ($lifetime > 0 ? 'lapsed' : 'free'));
            $ends = $a && $a['current_period_end'] ? strtotime((string) $a['current_period_end']) : null;
            $label = match ($state) {
                'paying' => 'Paying' . ($manual ? ' (offline)' : ''), 'complimentary' => 'Complimentary', 'halted' => 'Payment failed', 'lapsed' => 'Lapsed', default => 'Free',
            };

            $limits = FeatureGate::getPlanLimits((string) $t['plan']);
            $nContacts = (int) ($contacts[$id] ?? 0); $nUsers = (int) ($users[$id]['n'] ?? 0);
            $cLimit = (int) ($limits['contacts'] ?? 0); $uLimit = (int) ($limits['agents'] ?? 0);
            $isActive = $t['status'] === 'active';
            $paidish = in_array($state, ['paying', 'complimentary'], true);

            $flags = [];
            if ($t['status'] !== 'active') { $flags[] = ['key' => 'suspended', 'tone' => 'danger', 'label' => ucfirst((string) $t['status'])]; }
            if ($state === 'halted') { $flags[] = ['key' => 'halted', 'tone' => 'danger', 'label' => 'Payment failed']; }
            if ($ends !== null && $ends <= $now + self::EXPIRING_DAYS * 86400) { $d = max(0, (int) ceil(($ends - $now) / 86400)); $flags[] = ['key' => 'expiring', 'tone' => 'warn', 'label' => $d <= 1 ? 'Plan ends today/tomorrow' : "Plan ends in {$d} days"]; }
            if (($tickets[$id] ?? 0) > 0) { $flags[] = ['key' => 'tickets', 'tone' => 'info', 'label' => $tickets[$id] . ' open support request' . ($tickets[$id] > 1 ? 's' : '')]; }
            if (($waba[$id] ?? '') === 'suspended') { $flags[] = ['key' => 'wa_problem', 'tone' => 'danger', 'label' => 'WhatsApp suspended']; }
            $quiet = $isActive && $joinedDays >= self::INACTIVE_DAYS && ($lastTs === null || $lastTs < $now - self::INACTIVE_DAYS * 86400);
            if ($quiet) { $flags[] = ['key' => 'inactive', 'tone' => $paidish ? 'warn' : 'muted', 'label' => 'Not seen for ' . self::INACTIVE_DAYS . '+ days']; }
            $unset = $isActive && $joinedDays >= self::NOT_ACTIVATED_DAYS && $nContacts === 0 && (int) ($trips[$id] ?? 0) === 0;
            if ($unset) { $flags[] = ['key' => 'not_activated', 'tone' => 'info', 'label' => 'Nothing set up yet']; }
            $near = ($cLimit > 0 && $cLimit < 999 && $nContacts >= $cLimit * self::NEAR_LIMIT) || ($uLimit > 0 && $uLimit < 999 && $nUsers >= $uLimit);
            if ($near) { $flags[] = ['key' => 'near_limit', 'tone' => 'warn', 'label' => 'Close to a plan limit']; }
            $keys = array_column($flags, 'key');
            $attention = (bool) array_intersect($keys, ['halted', 'expiring', 'tickets', 'wa_problem', 'not_activated']) || ($quiet && $paidish);

            $rows[] = [
                'id' => $id, 'name' => (string) $t['name'], 'slug' => (string) $t['slug'], 'plan' => (string) $t['plan'], 'status' => (string) $t['status'], 'created_at' => $t['created_at'], 'joined_days' => $joinedDays,
                'owner_name' => (string) ($o['name'] ?? ''), 'owner_email' => (string) ($o['email'] ?? ''),   // kept for the customer pickers on the Offers and WhatsApp pages
                'owner' => ['name' => (string) ($o['name'] ?? ''), 'email' => (string) ($o['email'] ?? ''), 'phone' => (string) ($o['phone'] ?? '')],
                'billing' => ['state' => $state, 'label' => $label, 'amount_paise' => $amount, 'cycle' => $a['billing_cycle'] ?? null, 'renews_at' => $ends ? $this->stamp($ends) : null, 'manual' => $manual, 'lifetime_paise' => $lifetime],
                'usage' => ['users' => $nUsers, 'contacts' => $nContacts, 'trips' => (int) ($trips[$id] ?? 0), 'bookings' => (int) ($bookings[$id] ?? 0), 'contacts_limit' => $cLimit, 'users_limit' => $uLimit],
                'last_active' => $lastLogin, 'whatsapp' => $waba[$id] ?? 'none', 'tickets_open' => (int) ($tickets[$id] ?? 0),
                'partner' => $t['referred_partner_id'] ? ($partners[(int) $t['referred_partner_id']] ?? 'Partner #' . $t['referred_partner_id']) : null,
                'flags' => $flags, 'attention' => $attention,
            ];
        }
        return $rows;
    }
}
