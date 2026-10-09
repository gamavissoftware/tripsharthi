<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Crm\AuditLogger;
use App\Services\Tenancy\FeatureGate;

/**
 * What the TripSarthi team (users.is_platform_admin) can do across ALL customer workspaces: see them, see what they pay and use,
 * give or change a plan by hand, suspend and reactivate. Every change is audited against the affected tenant with the acting admin.
 * Plain SQL on purpose: this is the one place that deliberately reads across tenants.
 */
final class PlatformAdminService
{
    public const PLANS = ['free', 'starter', 'growth', 'pro'];
    public const MANUAL_PREFIX = 'admin-';        // razorpay_sub_id of a plan given by hand (comp / offline payment)

    public function overview(): array
    {
        $db = db_connect(); $now = date('Y-m-d H:i:s');
        $one = static fn (string $sql, array $b = []) => $db->query($sql, $b)->getRowArray();
        $byPlan = array_fill_keys(self::PLANS, 0); $byStatus = ['active' => 0, 'suspended' => 0, 'cancelled' => 0];
        foreach ($db->query('SELECT plan, status, COUNT(*) n FROM tenants WHERE deleted_at IS NULL GROUP BY plan, status')->getResultArray() as $r) { $byPlan[$r['plan']] += (int) $r['n']; $byStatus[$r['status']] += (int) $r['n']; }
        $mrr = $one("SELECT COALESCE(SUM(CASE WHEN billing_cycle = 'annual' THEN amount_paise / 12 ELSE amount_paise END), 0) m, COUNT(*) n FROM subscriptions WHERE status = 'active' AND (current_period_end IS NULL OR current_period_end >= ?) AND amount_paise > 0", [$now]);
        $comp = $one("SELECT COUNT(*) n FROM subscriptions WHERE status = 'active' AND amount_paise = 0 AND plan <> 'free' AND (current_period_end IS NULL OR current_period_end >= ?)", [$now]);
        $soon = $db->query("SELECT s.id, s.tenant_id, t.name, s.plan, s.current_period_end FROM subscriptions s JOIN tenants t ON t.id = s.tenant_id WHERE s.status = 'active' AND s.current_period_end BETWEEN ? AND ? ORDER BY s.current_period_end LIMIT 10", [$now, date('Y-m-d H:i:s', time() + 7 * 86400)])->getResultArray();
        $halted = (int) $one("SELECT COUNT(*) n FROM subscriptions WHERE status = 'halted'")['n'];
        $paid30 = $one("SELECT COALESCE(SUM(amount_paise), 0) s, COUNT(*) n FROM subscriptions WHERE status IN ('active','cancelled','downgraded') AND amount_paise > 0 AND current_period_start >= ?", [date('Y-m-d H:i:s', time() - 30 * 86400)]);
        $table = static fn (string $t): bool => $db->tableExists($t);
        return [
            'tenants' => ['total' => array_sum($byStatus), 'by_plan' => $byPlan, 'by_status' => $byStatus,
                'new_7d' => (int) $one('SELECT COUNT(*) n FROM tenants WHERE deleted_at IS NULL AND created_at >= ?', [date('Y-m-d H:i:s', time() - 7 * 86400)])['n'],
                'new_30d' => (int) $one('SELECT COUNT(*) n FROM tenants WHERE deleted_at IS NULL AND created_at >= ?', [date('Y-m-d H:i:s', time() - 30 * 86400)])['n']],
            'subscriptions' => ['paying' => (int) $mrr['n'], 'complimentary' => (int) $comp['n'], 'halted' => $halted, 'mrr_paise' => (int) round((float) $mrr['m']), 'collected_30d_paise' => (int) $paid30['s'], 'payments_30d' => (int) $paid30['n'], 'expiring_7d' => $soon],
            'usage' => ['users' => (int) $one('SELECT COUNT(*) n FROM users WHERE deleted_at IS NULL')['n'], 'contacts' => (int) $one('SELECT COUNT(*) n FROM contacts WHERE deleted_at IS NULL')['n'],
                'trips' => $table('trips') ? (int) $one('SELECT COUNT(*) n FROM trips WHERE deleted_at IS NULL')['n'] : 0, 'bookings' => $table('bookings') ? (int) $one('SELECT COUNT(*) n FROM bookings WHERE deleted_at IS NULL')['n'] : 0],
            'inbox' => ['new_enquiries' => (int) $one("SELECT COUNT(*) n FROM contact_enquiries WHERE status = 'new'")['n'], 'open_chats' => (int) $one("SELECT COUNT(*) n FROM chat_sessions WHERE status = 'open'")['n'], 'unread_chats' => (int) $one("SELECT COUNT(*) n FROM chat_sessions WHERE status = 'open' AND unread_agent > 0")['n']],
        ];
    }

    /** @return array{rows:list<array>,total:int,page:int,per_page:int} */
    public function tenants(string $q = '', string $plan = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $db = db_connect(); $perPage = max(5, min(100, $perPage)); $page = max(1, $page);
        $w = ['t.deleted_at IS NULL']; $b = [];
        if ($q !== '') { $w[] = '(t.name LIKE ? OR t.slug LIKE ? OR EXISTS (SELECT 1 FROM users u2 WHERE u2.tenant_id = t.id AND u2.deleted_at IS NULL AND (u2.email LIKE ? OR u2.name LIKE ?)))'; $like = '%' . $q . '%'; array_push($b, $like, $like, $like, $like); }
        if (in_array($plan, self::PLANS, true)) { $w[] = 't.plan = ?'; $b[] = $plan; }
        if (in_array($status, ['active', 'suspended', 'cancelled'], true)) { $w[] = 't.status = ?'; $b[] = $status; }
        $where = implode(' AND ', $w);
        $total = (int) $db->query("SELECT COUNT(*) n FROM tenants t WHERE {$where}", $b)->getRowArray()['n'];
        $rows = $db->query("SELECT t.id, t.name, t.slug, t.plan, t.status, t.mode, t.created_at,
              (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) users,
              (SELECT COUNT(*) FROM contacts c WHERE c.tenant_id = t.id AND c.deleted_at IS NULL) contacts,
              (SELECT u.email FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL ORDER BY (u.role = 'owner') DESC, u.id LIMIT 1) owner_email,
              (SELECT u.name FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL ORDER BY (u.role = 'owner') DESC, u.id LIMIT 1) owner_name,
              (SELECT MAX(u.last_login_at) FROM users u WHERE u.tenant_id = t.id) last_login_at,
              (SELECT s.status FROM subscriptions s WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) sub_status,
              (SELECT s.current_period_end FROM subscriptions s WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) sub_ends
            FROM tenants t WHERE {$where} ORDER BY t.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $b)->getResultArray();
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['users'] = (int) $r['users']; $r['contacts'] = (int) $r['contacts']; } unset($r);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function tenant(int $id): array
    {
        $db = db_connect();
        $t = $db->query('SELECT id, name, slug, plan, status, mode, created_at, updated_at FROM tenants WHERE id = ? AND deleted_at IS NULL', [$id])->getRowArray() ?: throw new \OutOfBoundsException('Customer not found.');
        $users = $db->query('SELECT id, name, email, role, last_login_at, created_at FROM users WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY (role = \'owner\') DESC, id', [$id])->getResultArray();
        $subs = $db->query('SELECT id, plan, amount_paise, billing_cycle, status, current_period_start, current_period_end, razorpay_sub_id, halted_at, cancelled_at, created_at FROM subscriptions WHERE tenant_id = ? ORDER BY id DESC LIMIT 30', [$id])->getResultArray();
        foreach ($subs as &$s) { $s['manual'] = str_starts_with((string) $s['razorpay_sub_id'], self::MANUAL_PREFIX); unset($s['razorpay_sub_id']); } unset($s);
        $cnt = function (string $table, string $extra = '') use ($db, $id): int { return $db->tableExists($table) ? (int) $db->query("SELECT COUNT(*) n FROM {$table} WHERE tenant_id = ? {$extra}", [$id])->getRowArray()['n'] : 0; };
        $usage = ['users' => count($users), 'contacts' => $cnt('contacts', 'AND deleted_at IS NULL'), 'trips' => $cnt('trips', 'AND deleted_at IS NULL'), 'bookings' => $cnt('bookings', 'AND deleted_at IS NULL'),
            'active_flows' => $cnt('flows', "AND status = 'active' AND deleted_at IS NULL"), 'whatsapp_numbers' => $cnt('phone_numbers')];
        $audit = $db->tableExists('audit_logs') ? $db->query("SELECT action, created_at, actor_user_id FROM audit_logs WHERE tenant_id = ? AND action LIKE 'admin.%' ORDER BY id DESC LIMIT 15", [$id])->getResultArray() : [];
        return ['tenant' => $t, 'users' => $users, 'subscriptions' => $subs, 'usage' => $usage, 'limits' => FeatureGate::getPlanLimits($t['plan']), 'activity' => $audit];
    }

    /**
     * Give a customer a plan by hand (complimentary, offline/bank payment, trial extension). Creates an audited subscription row that expires on its own
     * (`subscription:check` downgrades it to free afterwards). Refuses when a live Razorpay subscription exists unless $force — Razorpay would keep charging.
     * @param int $days 1..3650
     */
    public function setPlan(int $tenantId, string $plan, int $days, float $amountRupees, int $adminId, bool $force = false, string $note = ''): array
    {
        if (! in_array($plan, self::PLANS, true)) { throw new \InvalidArgumentException('Unknown plan.'); }
        $db = db_connect(); $t = $db->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->get()->getRowArray() ?: throw new \OutOfBoundsException('Customer not found.');
        if ($amountRupees < 0 || $amountRupees > 1_000_000) { throw new \InvalidArgumentException('Amount must be between 0 and 10,00,000.'); }
        $live = $db->table('subscriptions')->where('tenant_id', $tenantId)->where('status', 'active')->where('razorpay_sub_id NOT LIKE', self::MANUAL_PREFIX . '%')->countAllResults();
        if ($live > 0 && ! $force) { throw new \DomainException('This customer has a live paid subscription. Changing the plan here will not stop Razorpay billing them — cancel it in Razorpay first, or confirm to override anyway.'); }
        $now = date('Y-m-d H:i:s'); $db->transStart();
        $db->table('subscriptions')->where('tenant_id', $tenantId)->where('status', 'active')->update(['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]);
        if ($plan !== 'free') {
            $days = max(1, min(3650, $days));
            $db->table('subscriptions')->insert(['tenant_id' => $tenantId, 'razorpay_sub_id' => self::MANUAL_PREFIX . date('YmdHis') . '-' . bin2hex(random_bytes(3)), 'razorpay_customer_id' => 'admin', 'plan' => $plan,
                'amount_paise' => (int) round($amountRupees * 100), 'billing_cycle' => $days >= 360 ? 'annual' : 'monthly', 'status' => 'active', 'current_period_start' => $now, 'current_period_end' => date('Y-m-d H:i:s', time() + $days * 86400), 'created_at' => $now, 'updated_at' => $now]);
        }
        $db->table('tenants')->where('id', $tenantId)->update(['plan' => $plan, 'updated_at' => $now]);
        $db->transComplete();
        if (! $db->transStatus()) { throw new \RuntimeException('Could not change the plan.'); }
        AuditLogger::log('admin.tenant.plan', 'tenant', $tenantId, ['plan' => $t['plan']], ['plan' => $plan, 'days' => $plan === 'free' ? 0 : $days, 'amount_rs' => $amountRupees, 'note' => mb_substr($note, 0, 200), 'forced' => $force && $live > 0], $tenantId, $adminId);
        return $this->tenant($tenantId);
    }

    public function setStatus(int $tenantId, string $status, int $adminId, int $adminTenantId, string $reason = ''): array
    {
        if (! in_array($status, ['active', 'suspended', 'cancelled'], true)) { throw new \InvalidArgumentException('Unknown status.'); }
        $db = db_connect(); $t = $db->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->get()->getRowArray() ?: throw new \OutOfBoundsException('Customer not found.');
        if ($status !== 'active' && $tenantId === $adminTenantId) { throw new \DomainException('You cannot suspend or cancel your own workspace.'); }
        if ($status === 'suspended' && trim($reason) === '') { throw new \InvalidArgumentException('Give a reason — it is kept in the audit log.'); }
        $db->table('tenants')->where('id', $tenantId)->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($status !== 'active') { $db->table('users')->where('tenant_id', $tenantId)->update(['api_token' => null, 'api_token_expires_at' => null]); }     // everyone is signed out at once
        AuditLogger::log('admin.tenant.status', 'tenant', $tenantId, ['status' => $t['status']], ['status' => $status, 'reason' => mb_substr($reason, 0, 300)], $tenantId, $adminId);
        return $this->tenant($tenantId);
    }

    /** @return array{rows:list<array>,total:int,page:int,per_page:int,totals:array} */
    public function subscriptions(string $status = '', string $plan = '', int $page = 1, int $perPage = 25): array
    {
        $db = db_connect(); $perPage = max(5, min(100, $perPage)); $page = max(1, $page); $w = ['1=1']; $b = [];
        if (in_array($status, ['created', 'authenticated', 'active', 'halted', 'cancelled', 'downgraded'], true)) { $w[] = 's.status = ?'; $b[] = $status; }
        if (in_array($plan, self::PLANS, true)) { $w[] = 's.plan = ?'; $b[] = $plan; }
        $where = implode(' AND ', $w);
        $total = (int) $db->query("SELECT COUNT(*) n FROM subscriptions s WHERE {$where}", $b)->getRowArray()['n'];
        $rows = $db->query("SELECT s.id, s.tenant_id, t.name tenant_name, s.plan, s.amount_paise, s.billing_cycle, s.status, s.current_period_start, s.current_period_end, s.created_at, (s.razorpay_sub_id LIKE '" . self::MANUAL_PREFIX . "%') is_manual
            FROM subscriptions s LEFT JOIN tenants t ON t.id = s.tenant_id WHERE {$where} ORDER BY s.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $b)->getResultArray();
        foreach ($rows as &$r) { $r['manual'] = (bool) $r['is_manual']; unset($r['is_manual']); $r['amount_paise'] = (int) $r['amount_paise']; } unset($r);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}
