<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Services\Crm\AuditLogger;

/**
 * Monthly sales targets per person, stored in the existing `sales_targets` table (the same rows the Forecast page reads):
 * metric 'won_value' = revenue ex-tax in paise, metric 'won_count' = number of bookings, one row per person per calendar month.
 *
 * Carry-over: a person with no row for a month keeps their latest earlier month's target, so a manager sets it once and it
 * keeps applying until it is changed. An explicit 0 is a row too: it means "no target from this month on" and blocks the carry-over.
 * Owners/admins set targets; the dashboard shows each person only their own progress.
 */
final class SalesTargetService
{
    public const MAX_REVENUE  = 100_000_000_000;   // INR 100 crore in paise: far above any agency, but stops a typo with ten extra zeros
    public const MAX_BOOKINGS = 10_000;
    private const METRICS = ['revenue' => 'won_value', 'bookings' => 'won_count'];

    public static function validMonth(string $m): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m);
    }

    /**
     * How far along a person is, and whether that is where they should be today.
     * "Expected" is a straight line through the month (target x day / days in month): a deliberately simple pace, not a forecast.
     *
     * @return array{target:int, actual:int, pct:int, expected_pct:int, to_go:int, status:string}   status: none | achieved | ahead | on_track | behind
     */
    public static function pace(int $actual, int $target, int $day, int $daysInMonth): array
    {
        $expectedPct = $daysInMonth > 0 ? (int) round(min($day, $daysInMonth) * 100 / $daysInMonth) : 0;
        if ($target <= 0) { return ['target' => 0, 'actual' => $actual, 'pct' => 0, 'expected_pct' => $expectedPct, 'to_go' => 0, 'status' => 'none']; }
        $expected = $target * min($day, $daysInMonth) / max(1, $daysInMonth);
        $ratio    = $expected > 0 ? $actual / $expected : 1.0;
        $status   = $actual >= $target ? 'achieved' : ($ratio >= 1.0 ? 'ahead' : ($ratio >= 0.8 ? 'on_track' : 'behind'));
        return ['target' => $target, 'actual' => $actual, 'pct' => (int) round($actual * 100 / $target), 'expected_pct' => $expectedPct, 'to_go' => max(0, $target - $actual), 'status' => $status];
    }

    private static function bounds(string $month): array
    {
        $start = $month . '-01';
        return [$start, date('Y-m-t', strtotime($start))];
    }

    /**
     * The targets in force for a month, keyed by user id. People whose effective target is 0 are left out.
     *
     * @return array<int, array{revenue:int, bookings:int, source:string, from:?string, since:?string}>   source: 'month' (set for this month) | 'carried' (from an earlier month, named in `from`); since = when it was last changed
     */
    public function forMonth(int $tenantId, string $month): array
    {
        if (! self::validMonth($month)) { throw new \InvalidArgumentException('Choose a valid month.'); }
        [$start] = self::bounds($month);
        // Monthly rows only (a whole calendar month), newest first: the first row seen per person+metric is the one in force.
        $rows = db_connect()->query("SELECT user_id, metric, period_start, target_amount, updated_at FROM sales_targets
            WHERE tenant_id = ? AND deleted_at IS NULL AND metric IN ('won_value','won_count') AND period_start <= ?
              AND DAYOFMONTH(period_start) = 1 AND period_end = LAST_DAY(period_start)
            ORDER BY period_start DESC, id DESC", [$tenantId, $start])->getResultArray();
        $seen = []; $out = [];
        foreach ($rows as $r) {
            $k = $r['user_id'] . '|' . $r['metric'];
            if (isset($seen[$k])) { continue; }
            $seen[$k] = true;
            $u = (int) $r['user_id']; $field = $r['metric'] === 'won_value' ? 'revenue' : 'bookings';
            $own = $r['period_start'] === $start;
            $out[$u] ??= ['revenue' => 0, 'bookings' => 0, 'source' => 'carried', 'from' => null, 'since' => null];
            if ($r['updated_at'] !== null && ($out[$u]['since'] === null || $r['updated_at'] > $out[$u]['since'])) { $out[$u]['since'] = $r['updated_at']; }
            $out[$u][$field] = (int) $r['target_amount'];
            if ($own) { $out[$u]['source'] = 'month'; }
            elseif ($out[$u]['source'] !== 'month') { $out[$u]['from'] = substr((string) $r['period_start'], 0, 7); }
        }
        return array_filter($out, static fn ($t) => $t['revenue'] > 0 || $t['bookings'] > 0);
    }

    /**
     * Month-to-date results for one person: bookings they own made in [$from, $to), revenue ex-tax (paise) and how many.
     * (The dashboard computes the same two numbers inside its own queries; a test keeps them equal.)
     *
     * @return array{revenue:int, bookings:int}
     */
    public function actuals(int $tenantId, int $userId, string $from, string $to): array
    {
        $r = db_connect()->query("SELECT COUNT(*) n, COALESCE(SUM(subtotal),0) revenue FROM bookings WHERE tenant_id = ? AND owner_id = ? AND deleted_at IS NULL AND status <> 'cancelled' AND created_at >= ? AND created_at < ?", [$tenantId, $userId, $from, $to])->getRowArray();
        return ['revenue' => (int) $r['revenue'], 'bookings' => (int) $r['n']];
    }

    /** Everyone in the workspace with the target that applies to $month (for the settings page). */
    public function members(int $tenantId, string $month): array
    {
        $t = $this->forMonth($tenantId, $month);
        $users = db_connect()->query("SELECT id, name, role FROM users WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name", [$tenantId])->getResultArray();
        return ['month' => $month, 'members' => array_map(static fn ($u) => [
            'id' => (int) $u['id'], 'name' => $u['name'], 'role' => $u['role'],
            'revenue' => $t[(int) $u['id']]['revenue'] ?? 0, 'bookings' => $t[(int) $u['id']]['bookings'] ?? 0,
            'source' => $t[(int) $u['id']]['source'] ?? null, 'from' => $t[(int) $u['id']]['from'] ?? null,
        ], $users)];
    }

    /**
     * Save the targets for one month. Every person sent is written (0 and 0 = "no target from this month on").
     *
     * @param list<array{user_id:int, revenue:int, bookings:int}> $items revenue in paise
     */
    public function save(int $tenantId, string $month, array $items, ?int $actorId = null): array
    {
        if (! self::validMonth($month)) { throw new \InvalidArgumentException('Choose a valid month.'); }
        if (count($items) > 500) { throw new \InvalidArgumentException('Too many people in one request.'); }
        $db = db_connect();
        $valid = array_map('intval', array_column($db->query("SELECT id FROM users WHERE tenant_id = ? AND deleted_at IS NULL", [$tenantId])->getResultArray(), 'id'));
        $clean = [];
        foreach ($items as $i) {
            $uid = (int) ($i['user_id'] ?? 0);
            if (! in_array($uid, $valid, true)) { throw new \InvalidArgumentException('One of the people is not in your team.'); }
            $rev = $i['revenue'] ?? 0; $bk = $i['bookings'] ?? 0;
            if (! is_numeric($rev) || (float) $rev < 0 || (float) $rev > self::MAX_REVENUE || (float) $rev != (int) $rev) { throw new \InvalidArgumentException('Revenue target must be a whole amount between 0 and INR 100 crore.'); }
            if (! is_numeric($bk) || (float) $bk < 0 || (float) $bk > self::MAX_BOOKINGS || (float) $bk != (int) $bk) { throw new \InvalidArgumentException('Bookings target must be a whole number between 0 and ' . self::MAX_BOOKINGS . '.'); }
            $clean[$uid] = ['won_value' => (int) $rev, 'won_count' => (int) $bk];
        }
        [$start, $end] = self::bounds($month);
        $now = date('Y-m-d H:i:s');
        $db->transStart();
        foreach ($clean as $uid => $byMetric) {
            foreach ($byMetric as $metric => $amount) {
                $where = ['tenant_id' => $tenantId, 'user_id' => $uid, 'metric' => $metric, 'period_start' => $start, 'period_end' => $end, 'deleted_at' => null];
                $row = $db->table('sales_targets')->select('id')->where($where)->orderBy('id', 'DESC')->get()->getRowArray();
                if ($row) { $db->table('sales_targets')->where('id', (int) $row['id'])->update(['target_amount' => $amount, 'updated_at' => $now]); }
                else { $db->table('sales_targets')->insert(['tenant_id' => $tenantId, 'user_id' => $uid, 'metric' => $metric, 'period_start' => $start, 'period_end' => $end, 'target_amount' => $amount, 'created_at' => $now, 'updated_at' => $now]); }
            }
        }
        $db->transComplete();
        if (! $db->transStatus()) { throw new \RuntimeException('Could not save the targets.'); }
        AuditLogger::log('sales_targets.save', 'sales_targets', null, null, ['month' => $month, 'people' => count($clean)], $tenantId, $actorId);
        return $this->members($tenantId, $month);
    }
}
