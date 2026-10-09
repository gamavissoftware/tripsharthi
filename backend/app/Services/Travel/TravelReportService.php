<?php

declare(strict_types=1);

namespace App\Services\Travel;

/**
 * Owner-level business view: funnel, revenue & margin, destinations, sources, agents, cash and a simple forecast.
 * Revenue is EX-TAX (subtotal): GST and TCS are not income. Margin = subtotal - supplier cost; bookings whose cost is not yet
 * entered are counted and flagged because they overstate margin. Cancelled bookings are excluded everywhere except where noted.
 */
final class TravelReportService
{
    public function __construct(private readonly ?int $now = null) {}
    private function today(): string { return date('Y-m-d', $this->now ?? time()); }

    public static function pct(int|float $a, int|float $b): float { return $b > 0 ? round($a * 100 / $b, 1) : 0.0; }

    public function report(int $tenantId, string $from, string $to): array
    {
        foreach ([$from, $to] as $d) { if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || strtotime($d) === false) { throw new \InvalidArgumentException('Choose a valid date range.'); } }
        if ($from > $to) { throw new \InvalidArgumentException('The start date is after the end date.'); }
        if ((strtotime($to) - strtotime($from)) / 86400 > 800) { throw new \InvalidArgumentException('Choose a range of at most about two years.'); }
        $db = db_connect(); $a = [$tenantId, $from . ' 00:00:00', $to . ' 23:59:59'];

        // ---- funnel (trips created in the range)
        $f = $db->query("SELECT COUNT(*) enquiries,
                SUM(t.status IN ('quoted','negotiating','booked','travelling','completed') OR EXISTS (SELECT 1 FROM itineraries i WHERE i.trip_id = t.id AND i.tenant_id = t.tenant_id AND i.sent_at IS NOT NULL)) quoted,
                SUM(t.status IN ('booked','travelling','completed') OR EXISTS (SELECT 1 FROM bookings b WHERE b.trip_id = t.id AND b.tenant_id = t.tenant_id AND b.status <> 'cancelled')) booked,
                SUM(t.status = 'lost') lost
            FROM trips t WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.created_at BETWEEN ? AND ?", $a)->getRowArray();
        $funnel = ['enquiries' => (int) $f['enquiries'], 'quoted' => (int) $f['quoted'], 'booked' => (int) $f['booked'], 'lost' => (int) $f['lost'],
            'quote_rate' => self::pct((int) $f['quoted'], (int) $f['enquiries']), 'win_rate' => self::pct((int) $f['booked'], (int) $f['enquiries']), 'quote_to_book' => self::pct((int) $f['booked'], (int) $f['quoted'])];

        // ---- revenue & margin (bookings created in the range)
        $r = $db->query("SELECT COUNT(*) n, COALESCE(SUM(subtotal),0) revenue, COALESCE(SUM(cost_total),0) cost, COALESCE(SUM(gst_amount),0) gst, COALESCE(SUM(tcs_amount),0) tcs,
                COALESCE(SUM(total_amount),0) gross, SUM(cost_total = 0) no_cost
            FROM bookings WHERE tenant_id = ? AND deleted_at IS NULL AND status <> 'cancelled' AND created_at BETWEEN ? AND ?", $a)->getRowArray();
        $revenue = (int) $r['revenue']; $margin = $revenue - (int) $r['cost'];
        $money = ['bookings' => (int) $r['n'], 'revenue' => $revenue, 'cost' => (int) $r['cost'], 'margin' => $margin, 'margin_pct' => self::pct($margin, $revenue), 'gst' => (int) $r['gst'], 'tcs' => (int) $r['tcs'],
            'billed' => (int) $r['gross'], 'avg_booking' => (int) $r['n'] > 0 ? intdiv($revenue, (int) $r['n']) : 0, 'bookings_without_cost' => (int) $r['no_cost']];
        $cancelled = (int) $db->query("SELECT COUNT(*) n FROM bookings WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'cancelled' AND created_at BETWEEN ? AND ?", $a)->getRowArray()['n'];

        // ---- by destination / by source / by agent
        $dest = $db->query("SELECT name, COUNT(*) bookings, SUM(subtotal) revenue, SUM(subtotal - cost_total) margin FROM (
                SELECT COALESCE(NULLIF(d.name,''), NULLIF(t.destination_text,''), 'Unspecified') AS name, b.subtotal, b.cost_total
                FROM bookings b LEFT JOIN trips t ON t.id = b.trip_id AND t.tenant_id = b.tenant_id LEFT JOIN destinations d ON d.id = t.destination_id AND d.tenant_id = b.tenant_id
                WHERE b.tenant_id = ? AND b.deleted_at IS NULL AND b.status <> 'cancelled' AND b.created_at BETWEEN ? AND ?) x GROUP BY name ORDER BY revenue DESC LIMIT 10", $a)->getResultArray();
        $won = "(t.status IN ('booked','travelling','completed') OR EXISTS (SELECT 1 FROM bookings b WHERE b.trip_id = t.id AND b.tenant_id = t.tenant_id AND b.status <> 'cancelled' AND b.deleted_at IS NULL))";
        $rev = "COALESCE((SELECT SUM(b.subtotal) FROM bookings b WHERE b.trip_id = t.id AND b.tenant_id = t.tenant_id AND b.status <> 'cancelled' AND b.deleted_at IS NULL), 0)";
        $src = $db->query("SELECT source, COUNT(*) enquiries, SUM(won) booked, SUM(revenue) revenue FROM (
                SELECT COALESCE(NULLIF(c.source,''), 'unknown') AS source, $won AS won, $rev AS revenue
                FROM trips t LEFT JOIN contacts c ON c.id = t.contact_id AND c.tenant_id = t.tenant_id
                WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.created_at BETWEEN ? AND ?) x GROUP BY source ORDER BY enquiries DESC LIMIT 12", $a)->getResultArray();
        $agents = $db->query("SELECT uid, uname AS name, COUNT(*) enquiries, SUM(won) booked, SUM(revenue) revenue FROM (
                SELECT u.id AS uid, u.name AS uname, $won AS won, $rev AS revenue
                FROM trips t JOIN users u ON u.id = t.owner_id
                WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.created_at BETWEEN ? AND ?) x GROUP BY uid, uname ORDER BY revenue DESC LIMIT 15", $a)->getResultArray();

        // ---- 6-month trend ending at $to
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = date('Y-m', strtotime(substr($to, 0, 7) . "-01 -$i months"));
            $x = $db->query("SELECT COUNT(*) n, COALESCE(SUM(subtotal),0) revenue, COALESCE(SUM(subtotal - cost_total),0) margin FROM bookings WHERE tenant_id = ? AND deleted_at IS NULL AND status <> 'cancelled' AND DATE_FORMAT(created_at,'%Y-%m') = ?", [$tenantId, $m])->getRowArray();
            $trend[] = ['month' => $m, 'bookings' => (int) $x['n'], 'revenue' => (int) $x['revenue'], 'margin' => (int) $x['margin']];
        }

        return ['range' => ['from' => $from, 'to' => $to], 'funnel' => $funnel, 'money' => $money, 'cancelled' => $cancelled,
            'destinations' => array_map(fn ($d) => ['name' => $d['name'], 'bookings' => (int) $d['bookings'], 'revenue' => (int) $d['revenue'], 'margin' => (int) $d['margin']], $dest),
            'sources' => array_map(fn ($s) => ['source' => $s['source'], 'enquiries' => (int) $s['enquiries'], 'booked' => (int) $s['booked'], 'win_rate' => self::pct((int) $s['booked'], (int) $s['enquiries']), 'revenue' => (int) $s['revenue']], $src),
            'agents' => array_map(fn ($g) => ['name' => $g['name'], 'enquiries' => (int) $g['enquiries'], 'booked' => (int) $g['booked'], 'win_rate' => self::pct((int) $g['booked'], (int) $g['enquiries']), 'revenue' => (int) $g['revenue']], $agents),
            'fx' => $this->fx($tenantId, $a),
            'trend' => $trend, 'cash' => $this->cash($tenantId), 'forecast' => $this->forecast($tenantId, $funnel['quote_to_book'])];
    }

    /** Foreign-currency exposure today + the forex variance realised on services settled for bookings created in the range. */
    private function fx(int $tenantId, array $range): array
    {
        $v = db_connect()->query("SELECT COALESCE(SUM(s.fx_variance),0) variance, COUNT(s.id) n FROM booking_services s JOIN bookings b ON b.id = s.booking_id AND b.tenant_id = s.tenant_id
            WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND b.deleted_at IS NULL AND s.fx_variance IS NOT NULL AND b.created_at BETWEEN ? AND ?", $range)->getRowArray();
        return ['exposure' => (new SupplierPayableService($this->now))->payables($tenantId)['fx_exposure'], 'realised_variance' => (int) $v['variance'], 'settled_services' => (int) $v['n']];
    }

    /** What is owed to us vs what we owe — as of today, independent of the range. */
    private function cash(int $tenantId): array
    {
        $db = db_connect(); $today = $this->today();
        $rec = $db->query("SELECT COALESCE(SUM(CASE WHEN p.status IN ('pending','overdue') AND p.due_date < ? THEN p.amount ELSE 0 END),0) overdue,
                COALESCE(SUM(CASE WHEN p.status IN ('pending','overdue') AND p.due_date >= ? THEN p.amount ELSE 0 END),0) upcoming
            FROM booking_payments p JOIN bookings b ON b.id = p.booking_id AND b.tenant_id = p.tenant_id
            WHERE p.tenant_id = ? AND p.deleted_at IS NULL AND b.deleted_at IS NULL AND b.status <> 'cancelled'", [$today, $today, $tenantId])->getRowArray();
        $pay = (new SupplierPayableService($this->now))->payables($tenantId)['totals'];
        return ['receivable_overdue' => (int) $rec['overdue'], 'receivable_upcoming' => (int) $rec['upcoming'], 'payable_outstanding' => $pay['outstanding'], 'payable_overdue' => $pay['overdue'],
            'net_position' => (int) $rec['overdue'] + (int) $rec['upcoming'] - $pay['outstanding']];
    }

    /** Departures and collections coming up + an open-quote pipeline weighted by the period's quote-to-book rate (a rough guide, not a promise). */
    private function forecast(int $tenantId, float $quoteToBook): array
    {
        $db = db_connect(); $today = $this->today();
        $dep = $db->query("SELECT COUNT(*) n, COALESCE(SUM(subtotal),0) revenue FROM bookings WHERE tenant_id = ? AND deleted_at IS NULL AND status NOT IN ('cancelled','completed') AND travel_start BETWEEN ? AND ?",
            [$tenantId, $today, date('Y-m-d', strtotime($today . ' +90 days'))])->getRowArray();
        $col = $db->query("SELECT COALESCE(SUM(p.amount),0) amt FROM booking_payments p JOIN bookings b ON b.id = p.booking_id AND b.tenant_id = p.tenant_id
            WHERE p.tenant_id = ? AND p.deleted_at IS NULL AND b.status <> 'cancelled' AND p.status IN ('pending','overdue') AND p.due_date BETWEEN ? AND ?", [$tenantId, $today, date('Y-m-d', strtotime($today . ' +30 days'))])->getRowArray();
        $open = $db->query("SELECT COUNT(*) n, COALESCE(SUM(sell_subtotal),0) amt FROM itineraries WHERE tenant_id = ? AND deleted_at IS NULL AND is_template = 0 AND status IN ('sent','viewed')
            AND (valid_until IS NULL OR valid_until >= ?)", [$tenantId, $today])->getRowArray();
        return ['departures_90d' => ['count' => (int) $dep['n'], 'revenue' => (int) $dep['revenue']], 'collections_30d' => (int) $col['amt'],
            'open_quotes' => ['count' => (int) $open['n'], 'value' => (int) $open['amt'], 'win_rate_used' => $quoteToBook, 'weighted_value' => (int) round((int) $open['amt'] * $quoteToBook / 100)]];
    }

    /**
     * Ads -> bookings: trips created in the range grouped by FIRST-touch platform + campaign (leads without any attribution are "direct"),
     * with quote / booking counts, ex-tax revenue and margin, the platform spend of the same period and the delivery state of the
     * conversion events sent back to Meta / Google. Money is integer paise.
     */
    public function ads(int $tenantId, string $from, string $to): array
    {
        foreach ([$from, $to] as $d) { if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || strtotime($d) === false) { throw new \InvalidArgumentException('Choose a valid date range.'); } }
        if ($from > $to) { throw new \InvalidArgumentException('The start date is after the end date.'); }
        if ((strtotime($to) - strtotime($from)) / 86400 > 800) { throw new \InvalidArgumentException('Choose a range of at most about two years.'); }
        $db = db_connect(); $a = [$tenantId, $from . ' 00:00:00', $to . ' 23:59:59'];
        $rows = $db->query("SELECT x.platform, x.campaign, COUNT(*) leads, SUM(x.quoted) quoted, SUM(x.bookings) bookings, SUM(x.revenue) revenue, SUM(x.margin) margin FROM (
                SELECT COALESCE(NULLIF(la.platform, ''), 'direct') platform, COALESCE(NULLIF(la.campaign_name, ''), '(no campaign)') campaign,
                       (t.status IN ('quoted','negotiating','booked','travelling','completed')) quoted,
                       (SELECT COUNT(*) FROM bookings b WHERE b.trip_id = t.id AND b.tenant_id = t.tenant_id AND b.status <> 'cancelled' AND b.deleted_at IS NULL) bookings,
                       (SELECT COALESCE(SUM(b.subtotal), 0) FROM bookings b WHERE b.trip_id = t.id AND b.tenant_id = t.tenant_id AND b.status <> 'cancelled' AND b.deleted_at IS NULL) revenue,
                       (SELECT COALESCE(SUM(b.subtotal - b.cost_total), 0) FROM bookings b WHERE b.trip_id = t.id AND b.tenant_id = t.tenant_id AND b.status <> 'cancelled' AND b.deleted_at IS NULL) margin
                  FROM trips t
                  LEFT JOIN lead_attributions la ON la.id = (SELECT MAX(l2.id) FROM lead_attributions l2 WHERE l2.contact_id = t.contact_id AND l2.tenant_id = t.tenant_id AND l2.touch = 'first' AND l2.deleted_at IS NULL)
                 WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.created_at BETWEEN ? AND ?) x
             GROUP BY x.platform, x.campaign ORDER BY bookings DESC, leads DESC, x.campaign", $a)->getResultArray();
        $campaigns = []; $tot = ['leads' => 0, 'quoted' => 0, 'bookings' => 0, 'revenue' => 0];
        foreach ($rows as $r) {
            $c = ['platform' => $r['platform'], 'campaign' => $r['campaign'], 'leads' => (int) $r['leads'], 'quoted' => (int) $r['quoted'], 'bookings' => (int) $r['bookings'], 'revenue' => (int) $r['revenue'], 'gross_margin' => (int) $r['margin']];
            $campaigns[] = $c; foreach (['leads', 'quoted', 'bookings', 'revenue'] as $k) { $tot[$k] += $c[$k]; }
        }
        $spend = (int) ($db->query('SELECT COALESCE(SUM(spend), 0) s FROM ad_insights_daily WHERE tenant_id = ? AND day BETWEEN ? AND ?', [$tenantId, $from, $to])->getRowArray()['s'] ?? 0);
        $fb = ['sent' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($db->query('SELECT status, COUNT(*) n FROM conversion_events WHERE tenant_id = ? AND deleted_at IS NULL AND created_at BETWEEN ? AND ? GROUP BY status', $a)->getResultArray() as $r) { $fb[$r['status']] = (int) $r['n']; }
        $platformRevenue = 0; foreach ($campaigns as $c) { if (in_array($c['platform'], ['meta', 'google'], true)) { $platformRevenue += $c['revenue']; } }
        return ['totals' => $tot, 'ad_spend' => $spend, 'roas' => $spend > 0 ? round($platformRevenue / $spend, 2) : null, 'campaigns' => $campaigns, 'conversion_feedback' => $fb];
    }
}
