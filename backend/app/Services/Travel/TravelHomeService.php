<?php

declare(strict_types=1);

namespace App\Services\Travel;

/**
 * The home dashboard: a bird's-eye view of the travel business.
 *
 * Scope: managers (owner/admin) see the whole business or any one person; an agent is ALWAYS limited to their own trips,
 * bookings and tasks. Internal cost, margin, supplier payables and the team table are manager-only: they are not even
 * computed for an agent (a customer-facing rule of the product is that cost never leaves the back office).
 *
 * Money is integer paise. "Revenue" is ex-tax (bookings.subtotal), like the Business report. Dates for "today" / "overdue" use
 * Indian time, because that is when an agency's day starts; task due times are stored exactly as typed (no zone), so they are
 * compared with the same clock.
 */
final class TravelHomeService
{
    private const OPEN = "('enquiry','quoted','negotiating')";
    private \DateTimeZone $tz;

    public function __construct(private readonly ?int $now = null)
    {
        $this->tz = new \DateTimeZone('Asia/Kolkata');
    }

    private function dt(string $mod = 'now'): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . ($this->now ?? time())))->setTimezone($this->tz)->modify($mod);
    }

    private static function pct(int|float $a, int|float $b): float { return $b > 0 ? round($a * 100 / $b, 1) : 0.0; }

    /**
     * @param ?int $focusUser manager only: show one person's numbers (null = everyone). Ignored for agents.
     */
    public function build(int $tenantId, int $userId, bool $manager, ?int $focusUser = null): array
    {
        $own = $manager ? $focusUser : $userId;           // null = whole business (managers only)
        $db  = db_connect();
        $now = $this->dt();
        $nowS = $now->format('Y-m-d H:i:s'); $today = $now->format('Y-m-d');
        $m0 = $this->dt('first day of this month')->format('Y-m-01 00:00:00');
        $m1 = $this->dt('first day of next month')->format('Y-m-01 00:00:00');
        $p0 = $this->dt('first day of last month')->format('Y-m-01 00:00:00');

        $oT = $own !== null ? ' AND t.owner_id = ' . (int) $own : '';        // trips t
        $oB = $own !== null ? ' AND b.owner_id = ' . (int) $own : '';        // bookings b
        $oK = $own !== null ? ' AND k.assigned_user_id = ' . (int) $own : ''; // tasks k

        $count = fn (string $sql, array $a) => (int) ($db->query($sql, $a)->getRowArray()['n'] ?? 0);

        $kpiFor = function (string $from, string $to) use ($db, $tenantId, $oT, $oB, $count, $manager): array {
            $enq = $count("SELECT COUNT(*) n FROM trips t WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.created_at >= ? AND t.created_at < ?$oT", [$tenantId, $from, $to]);
            $quo = $count("SELECT COUNT(DISTINCT i.trip_id) n FROM itineraries i JOIN trips t ON t.id = i.trip_id AND t.tenant_id = i.tenant_id
                           WHERE i.tenant_id = ? AND i.sent_at >= ? AND i.sent_at < ? AND t.deleted_at IS NULL$oT", [$tenantId, $from, $to]);
            $bk = $db->query("SELECT COUNT(*) n, COALESCE(SUM(b.subtotal),0) revenue, COALESCE(SUM(b.cost_total),0) cost, SUM(b.cost_total = 0) no_cost
                              FROM bookings b WHERE b.tenant_id = ? AND b.deleted_at IS NULL AND b.status <> 'cancelled' AND b.created_at >= ? AND b.created_at < ?$oB", [$tenantId, $from, $to])->getRowArray();
            $col = (int) ($db->query("SELECT COALESCE(SUM(p.amount),0) n FROM booking_payments p JOIN bookings b ON b.id = p.booking_id AND b.tenant_id = p.tenant_id
                              WHERE p.tenant_id = ? AND p.status = 'paid' AND p.paid_at >= ? AND p.paid_at < ? AND b.deleted_at IS NULL$oB", [$tenantId, $from, $to])->getRowArray()['n'] ?? 0);
            $revenue = (int) $bk['revenue'];
            $out = ['enquiries' => $enq, 'quotes_sent' => $quo, 'bookings' => (int) $bk['n'], 'revenue' => $revenue, 'collected' => $col];
            if ($manager) { $out['margin'] = $revenue - (int) $bk['cost']; $out['bookings_without_cost'] = (int) $bk['no_cost']; }
            return $out;
        };
        $cur = $kpiFor($m0, $m1); $prev = $kpiFor($p0, $m0);
        $kpis = [];
        foreach ($cur as $k => $v) { $kpis[$k] = ['value' => $v, 'prev' => $prev[$k] ?? 0]; }
        if ($manager) { $kpis['margin']['pct'] = self::pct((int) $cur['margin'], (int) $cur['revenue']); }

        // ---- sales target (one person: an agent, or a manager focused on someone) + the days left in the month
        $targets = (new SalesTargetService())->forMonth($tenantId, $now->format('Y-m'));
        $day = (int) $now->format('j'); $dim = (int) $now->format('t');
        $targetBlock = $own !== null && isset($targets[$own]) ? self::targetBlock($targets[$own], (int) $cur['revenue'], (int) $cur['bookings'], $day, $dim) : null;

        // ---- pipeline: open trips by stage (value = what the customer said they would spend), plus what is already booked / travelling
        $stale = $this->dt('-7 days')->format('Y-m-d H:i:s');
        $rows = $db->query("SELECT t.status, COUNT(*) n, COALESCE(SUM(t.budget_max),0) value, SUM(t.updated_at < ?) stale
                            FROM trips t WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.status IN ('enquiry','quoted','negotiating')$oT GROUP BY t.status", [$stale, $tenantId])->getResultArray();
        $by = []; foreach ($rows as $r) { $by[$r['status']] = $r; }
        $bk = $db->query("SELECT t.status, COUNT(DISTINCT t.id) n, COALESCE(SUM(b.subtotal),0) value FROM trips t JOIN bookings b ON b.trip_id = t.id AND b.tenant_id = t.tenant_id AND b.status <> 'cancelled' AND b.deleted_at IS NULL
                          WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.status IN ('booked','travelling')$oT GROUP BY t.status", [$tenantId])->getResultArray();
        foreach ($bk as $r) { $by[$r['status']] = $r + ['stale' => 0]; }
        $pipeline = [];
        foreach (['enquiry', 'quoted', 'negotiating', 'booked', 'travelling'] as $s) {
            $r = $by[$s] ?? ['n' => 0, 'value' => 0, 'stale' => 0];
            $pipeline[] = ['status' => $s, 'count' => (int) $r['n'], 'value' => (int) $r['value'], 'stale' => (int) $r['stale']];
        }

        // ---- needs attention
        $overdueTasks = $count("SELECT COUNT(*) n FROM tasks k WHERE k.tenant_id = ? AND k.deleted_at IS NULL AND k.status = 'open' AND k.due_at IS NOT NULL AND k.due_at < ?$oK", [$tenantId, $nowS]);
        $todayTasks   = $count("SELECT COUNT(*) n FROM tasks k WHERE k.tenant_id = ? AND k.deleted_at IS NULL AND k.status = 'open' AND k.due_at >= ? AND DATE(k.due_at) = ?$oK", [$tenantId, $nowS, $today]);
        $noActivitySql = "FROM trips t WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.deal_id IS NOT NULL AND t.status IN ('enquiry','quoted','negotiating')$oT
                          AND NOT EXISTS (SELECT 1 FROM tasks k WHERE k.tenant_id = t.tenant_id AND k.related_type = 'deal' AND k.related_id = t.deal_id AND k.status = 'open' AND k.deleted_at IS NULL)";
        $noActivity = $count("SELECT COUNT(*) n $noActivitySql", [$tenantId]);
        $quotesSql = "FROM itineraries i JOIN trips t ON t.id = i.trip_id AND t.tenant_id = i.tenant_id
                      WHERE i.tenant_id = ? AND t.deleted_at IS NULL AND i.status IN ('sent','viewed') AND i.viewed_at IS NOT NULL AND i.accepted_at IS NULL AND t.status IN ('quoted','negotiating','enquiry')$oT";
        $quotesToChase = $count("SELECT COUNT(DISTINCT i.trip_id) n $quotesSql", [$tenantId]);
        $weekEnd = $this->dt('+7 days')->format('Y-m-d');
        $payBase = "FROM booking_payments p JOIN bookings b ON b.id = p.booking_id AND b.tenant_id = p.tenant_id
                    WHERE p.tenant_id = ? AND b.deleted_at IS NULL AND b.status <> 'cancelled' AND p.status IN ('pending','overdue') AND p.due_date IS NOT NULL$oB";
        $po = $db->query("SELECT COUNT(*) n, COALESCE(SUM(p.amount),0) amt $payBase AND p.due_date < ?", [$tenantId, $today])->getRowArray();
        $pw = $db->query("SELECT COUNT(*) n, COALESCE(SUM(p.amount),0) amt $payBase AND p.due_date >= ? AND p.due_date <= ?", [$tenantId, $today, $weekEnd])->getRowArray();
        $depBase = "FROM bookings b WHERE b.tenant_id = ? AND b.deleted_at IS NULL AND b.status <> 'cancelled' AND b.travel_start >= ? AND b.travel_start <= ?$oB";
        $dep7 = $count("SELECT COUNT(*) n $depBase", [$tenantId, $today, $weekEnd]);
        $docsPending = $count("SELECT COUNT(*) n $depBase AND EXISTS (SELECT 1 FROM booking_checklist_items c WHERE c.booking_id = b.id AND c.tenant_id = b.tenant_id AND c.required = 1 AND c.status = 'pending')", [$tenantId, $today, $weekEnd]);
        $attention = ['overdue_tasks' => $overdueTasks, 'tasks_today' => $todayTasks, 'no_activity' => $noActivity, 'quotes_to_chase' => $quotesToChase,
            'payments_overdue' => ['count' => (int) $po['n'], 'amount' => (int) $po['amt']], 'payments_week' => ['count' => (int) $pw['n'], 'amount' => (int) $pw['amt']],
            'departures_7d' => $dep7, 'docs_pending_7d' => $docsPending];

        // ---- lists
        $tasks = $db->query("SELECT k.id, k.title, k.type, k.due_at, k.priority, t.id AS trip_id, t.title AS trip_title
                             FROM tasks k LEFT JOIN trips t ON k.related_type = 'deal' AND t.deal_id = k.related_id AND t.tenant_id = k.tenant_id AND t.deleted_at IS NULL
                             WHERE k.tenant_id = ? AND k.deleted_at IS NULL AND k.status = 'open' AND k.due_at IS NOT NULL AND DATE(k.due_at) <= ?$oK
                             ORDER BY k.due_at ASC LIMIT 8", [$tenantId, $today])->getResultArray();
        $followups = $db->query("SELECT t.id, t.title, t.status, t.destination_text, t.budget_max, t.updated_at $noActivitySql ORDER BY t.updated_at ASC LIMIT 6", [$tenantId])->getResultArray();
        $chase = $db->query("SELECT t.id AS trip_id, t.title, MAX(i.viewed_at) viewed_at, MAX(i.grand_total) total $quotesSql GROUP BY t.id, t.title ORDER BY viewed_at DESC LIMIT 6", [$tenantId])->getResultArray();
        $collections = $db->query("SELECT p.id, p.label, p.due_date, p.amount, b.id AS booking_id, b.booking_ref, b.title
                             $payBase AND p.due_date <= ? ORDER BY p.due_date ASC, p.id ASC LIMIT 8", [$tenantId, $weekEnd])->getResultArray();
        // (the join to contacts is added here rather than in $payBase so the counts above stay cheap)
        if ($collections) {
            $ids = array_column($collections, 'booking_id');
            $names = [];
            foreach ($db->table('bookings b')->select('b.id, c.name')->join('contacts c', 'c.id = b.contact_id AND c.tenant_id = b.tenant_id', 'left')->where('b.tenant_id', $tenantId)->whereIn('b.id', $ids)->get()->getResultArray() as $x) { $names[(int) $x['id']] = $x['name']; }
            foreach ($collections as &$c) { $c['customer'] = $names[(int) $c['booking_id']] ?? null; $c['overdue'] = $c['due_date'] < $today; }
            unset($c);
        }
        $departures = $db->query("SELECT b.id, b.booking_ref, b.title, b.travel_start, b.status, b.total_amount, b.paid_amount, c.name AS customer,
                                         (SELECT COUNT(*) FROM booking_checklist_items x WHERE x.booking_id = b.id AND x.tenant_id = b.tenant_id AND x.required = 1 AND x.status = 'pending') AS docs_pending
                                  FROM bookings b LEFT JOIN contacts c ON c.id = b.contact_id AND c.tenant_id = b.tenant_id
                                  WHERE b.tenant_id = ? AND b.deleted_at IS NULL AND b.status <> 'cancelled' AND b.travel_start >= ? AND b.travel_start <= ?$oB
                                  ORDER BY b.travel_start ASC LIMIT 8", [$tenantId, $today, $this->dt('+14 days')->format('Y-m-d')])->getResultArray();

        // ---- 6-month trend (bookings made, cash collected)
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = $this->dt("first day of -$i months");
            $a = $start->format('Y-m-01 00:00:00'); $z = $start->modify('first day of next month')->format('Y-m-01 00:00:00');
            $r = $db->query("SELECT COUNT(*) n, COALESCE(SUM(b.subtotal),0) revenue FROM bookings b WHERE b.tenant_id = ? AND b.deleted_at IS NULL AND b.status <> 'cancelled' AND b.created_at >= ? AND b.created_at < ?$oB", [$tenantId, $a, $z])->getRowArray();
            $c = (int) $db->query("SELECT COALESCE(SUM(p.amount),0) n FROM booking_payments p JOIN bookings b ON b.id = p.booking_id AND b.tenant_id = p.tenant_id WHERE p.tenant_id = ? AND p.status = 'paid' AND p.paid_at >= ? AND p.paid_at < ? AND b.deleted_at IS NULL$oB", [$tenantId, $a, $z])->getRowArray()['n'];
            $trend[] = ['month' => substr($a, 0, 7), 'bookings' => (int) $r['n'], 'revenue' => (int) $r['revenue'], 'collected' => $c];
        }

        // ---- lead sources this month
        $won = "(t.status IN ('booked','travelling','completed') OR EXISTS (SELECT 1 FROM bookings b WHERE b.trip_id = t.id AND b.tenant_id = t.tenant_id AND b.status <> 'cancelled' AND b.deleted_at IS NULL))";
        $sources = $db->query("SELECT source, COUNT(*) enquiries, SUM(won) booked FROM (SELECT COALESCE(NULLIF(c.source,''),'unknown') source, $won AS won FROM trips t LEFT JOIN contacts c ON c.id = t.contact_id AND c.tenant_id = t.tenant_id
                               WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.created_at >= ? AND t.created_at < ?$oT) x GROUP BY source ORDER BY enquiries DESC LIMIT 6", [$tenantId, $m0, $m1])->getResultArray();

        $out = [
            'as_of' => $now->format('c'), 'month' => $now->format('F Y'),
            'scope' => ['manager' => $manager, 'user_id' => $own, 'everyone' => $own === null],
            'kpis' => $kpis, 'target' => $targetBlock, 'month_progress' => ['day' => $day, 'days_in_month' => $dim, 'days_left' => $dim - $day], 'pipeline' => $pipeline, 'attention' => $attention,
            'tasks' => $tasks, 'followups' => $followups, 'quotes_to_chase' => $chase, 'collections' => $collections, 'departures' => $departures,
            'trend' => $trend,
            'sources' => array_map(static fn ($s) => ['source' => $s['source'], 'enquiries' => (int) $s['enquiries'], 'booked' => (int) $s['booked']], $sources),
        ];

        if ($manager) {
            if ($own === null) {   // business-wide figures: only in the Everyone view (they would mislead next to one person's numbers)
                $out['payables'] = (new SupplierPayableService($this->now))->payables($tenantId)['totals'];
                $out['team'] = $this->team($tenantId, $m0, $m1, $nowS, $targets, $day, $dim); $out['team_target'] = self::teamTarget($out['team'], $day, $dim);
            }
        }
        return $out;
    }

    /**
     * Just the target progress (for the phone app): the dashboard's numbers without the other ~25 queries.
     * Same scoping rules as build(): an agent gets only their own bars (`$focusUser` is ignored); a manager gets the whole team, or one person when focused.
     * Both use SalesTargetService::actuals / forMonth, and a test keeps this equal to the dashboard.
     *
     * @return array{month:string, scope:array, month_progress:array, target:?array, team_target:?array, team:?array}
     */
    public function targets(int $tenantId, int $userId, bool $manager, ?int $focusUser = null): array
    {
        $own = $manager ? $focusUser : $userId;
        $now = $this->dt(); $month = $now->format('Y-m');
        $from = $month . '-01 00:00:00'; $to = $this->dt('first day of next month')->format('Y-m-01 00:00:00');
        $day = (int) $now->format('j'); $dim = (int) $now->format('t');
        $svc = new SalesTargetService(); $targets = $svc->forMonth($tenantId, $month);
        $out = ['as_of' => $now->format('c'), 'month' => $now->format('F Y'), 'scope' => ['manager' => $manager, 'user_id' => $own, 'everyone' => $own === null],
            'month_progress' => ['day' => $day, 'days_in_month' => $dim, 'days_left' => $dim - $day], 'target' => null, 'team_target' => null, 'team' => null];
        if ($own !== null) {
            if (isset($targets[$own])) { $a = $svc->actuals($tenantId, $own, $from, $to); $out['target'] = self::targetBlock($targets[$own], $a['revenue'], $a['bookings'], $day, $dim); }
            return $out;
        }
        $team = [];
        foreach (db_connect()->query("SELECT id, name, role FROM users WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name", [$tenantId])->getResultArray() as $u) {
            $id = (int) $u['id']; $a = $svc->actuals($tenantId, $id, $from, $to);
            $team[] = ['id' => $id, 'name' => $u['name'], 'role' => $u['role'], 'revenue' => $a['revenue'], 'bookings' => $a['bookings'],
                'target' => isset($targets[$id]) ? self::targetBlock($targets[$id], $a['revenue'], $a['bookings'], $day, $dim) : null];
        }
        usort($team, static fn ($a, $b) => [$b['target'] !== null, $b['revenue']] <=> [$a['target'] !== null, $a['revenue']]);   // people with a target first, then by revenue
        $out['team'] = $team; $out['team_target'] = self::teamTarget($team, $day, $dim);
        return $out;
    }

    /** @param array{revenue:int,bookings:int,source:string,from?:?string} $t */
    private static function targetBlock(array $t, int $revenue, int $bookings, int $day, int $dim): array
    {
        return ['source' => $t['source'], 'from' => $t['from'] ?? null, 'revenue' => SalesTargetService::pace($revenue, $t['revenue'], $day, $dim), 'bookings' => SalesTargetService::pace($bookings, $t['bookings'], $day, $dim)];
    }

    /** The team as a whole: only people who HAVE a target count, on both sides, so the bar compares like with like. */
    private static function teamTarget(array $team, int $day, int $dim): ?array
    {
        $rt = $ra = $bt = $ba = 0; $n = 0;
        foreach ($team as $m) {
            if (! $m['target']) { continue; }
            $n++; $rt += $m['target']['revenue']['target']; $ra += $m['revenue']; $bt += $m['target']['bookings']['target']; $ba += $m['bookings'];
        }
        return $n === 0 ? null : ['people' => $n, 'revenue' => SalesTargetService::pace($ra, $rt, $day, $dim), 'bookings' => SalesTargetService::pace($ba, $bt, $day, $dim)];
    }

    /** Everyone's month at a glance - manager only. */
    private function team(int $tenantId, string $m0, string $m1, string $nowS, array $targets = [], int $day = 1, int $dim = 30): array
    {
        $db = db_connect(); $by = [];
        foreach ($db->query("SELECT id, name, role FROM users WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name", [$tenantId])->getResultArray() as $u) {
            $by[(int) $u['id']] = ['id' => (int) $u['id'], 'name' => $u['name'], 'role' => $u['role'], 'enquiries' => 0, 'bookings' => 0, 'revenue' => 0, 'overdue_tasks' => 0, 'open_trips' => 0];
        }
        foreach ($db->query("SELECT owner_id, COUNT(*) n FROM trips WHERE tenant_id = ? AND deleted_at IS NULL AND owner_id IS NOT NULL AND created_at >= ? AND created_at < ? GROUP BY owner_id", [$tenantId, $m0, $m1])->getResultArray() as $r) { if (isset($by[(int) $r['owner_id']])) { $by[(int) $r['owner_id']]['enquiries'] = (int) $r['n']; } }
        foreach ($db->query("SELECT owner_id, COUNT(*) n FROM trips WHERE tenant_id = ? AND deleted_at IS NULL AND owner_id IS NOT NULL AND status IN " . self::OPEN . " GROUP BY owner_id", [$tenantId])->getResultArray() as $r) { if (isset($by[(int) $r['owner_id']])) { $by[(int) $r['owner_id']]['open_trips'] = (int) $r['n']; } }
        foreach ($db->query("SELECT owner_id, COUNT(*) n, COALESCE(SUM(subtotal),0) revenue FROM bookings WHERE tenant_id = ? AND deleted_at IS NULL AND status <> 'cancelled' AND owner_id IS NOT NULL AND created_at >= ? AND created_at < ? GROUP BY owner_id", [$tenantId, $m0, $m1])->getResultArray() as $r) { if (isset($by[(int) $r['owner_id']])) { $by[(int) $r['owner_id']]['bookings'] = (int) $r['n']; $by[(int) $r['owner_id']]['revenue'] = (int) $r['revenue']; } }
        foreach ($db->query("SELECT assigned_user_id, COUNT(*) n FROM tasks WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'open' AND due_at IS NOT NULL AND due_at < ? AND assigned_user_id IS NOT NULL GROUP BY assigned_user_id", [$tenantId, $nowS])->getResultArray() as $r) { if (isset($by[(int) $r['assigned_user_id']])) { $by[(int) $r['assigned_user_id']]['overdue_tasks'] = (int) $r['n']; } }
        foreach ($by as $id => &$m) { $m['target'] = isset($targets[$id]) ? self::targetBlock($targets[$id], $m['revenue'], $m['bookings'], $day, $dim) : null; }
        unset($m);
        $rows = array_values($by);
        usort($rows, static fn ($a, $b) => [$b['revenue'], $b['bookings'], $b['enquiries']] <=> [$a['revenue'], $a['bookings'], $a['enquiries']]);
        return $rows;
    }
}
