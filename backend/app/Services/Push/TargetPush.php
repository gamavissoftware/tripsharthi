<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Services\Travel\SalesTargetService;

/**
 * A friendly nudge to an AGENT who is behind pace on their monthly sales target - at most once per checkpoint (day 10 and day 20 of
 * the month, Indian time), sent at about 11:00 IST - plus ONE summary push per checkpoint to the owners/admins naming who is behind
 * (counting agents who have no phone too; silent when nobody is behind). Managers get the summary, never the agent nudge. Nothing is sent to an agent when the agent is on pace or has hit
 * the target, has no target, has no phone registered, or had their target changed in the last 2 days (a target set today would
 * otherwise read as "behind" straight away). The person can switch the category off; quiet hours and "minimal" privacy apply.
 * Run from `push:run` (every minute); the dedupe key makes the repeats inside the window harmless.
 */
final class TargetPush
{
    public const CHECKPOINTS = [10, 20];
    private const GRACE_DAYS = 2;          // a checkpoint nudge may still go out up to 2 days late (the job was down, or the phone was off)
    private const TARGET_SETTLE = 2 * 86400;

    public function __construct(private readonly ?PushNotifier $notifier = null, private readonly ?int $now = null) {}

    public static function inWindow(int $ts): bool
    {
        $h = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        $m = (int) $h->format('G') * 60 + (int) $h->format('i');
        return $m >= 11 * 60 && $m < 11 * 60 + 45;
    }

    /** The checkpoint a day of the month belongs to (10 or 20), or null on the days in between. */
    public static function checkpoint(int $day): ?int
    {
        $cp = null;
        foreach (self::CHECKPOINTS as $c) { if ($day >= $c) { $cp = $c; } }
        return ($cp !== null && $day - $cp <= self::GRACE_DAYS) ? $cp : null;
    }

    private static function inr(int $paise): string
    {
        $n = $paise / 100;
        return $n >= 100000 ? '₹' . rtrim(rtrim(number_format($n / 100000, 2, '.', ''), '0'), '.') . 'L' : '₹' . number_format($n, 0, '.', ',');
    }

    /**
     * @return array{agents:int, behind:int, sent:int, team_behind:int, managers_sent:int}
     *   agents = agents with a phone that were looked at; behind = of those, how many were behind; sent = agent nudges queued;
     *   team_behind = agents behind in the whole team (phone or not); managers_sent = team summaries queued
     */
    public function run(): array
    {
        $out = ['agents' => 0, 'behind' => 0, 'sent' => 0, 'team_behind' => 0, 'managers_sent' => 0];
        $now = $this->now ?? time();
        if (! self::inWindow($now)) { return $out; }
        $ist = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        $day = (int) $ist->format('j'); $dim = (int) $ist->format('t');
        $cp = self::checkpoint($day);
        if ($cp === null) { return $out; }
        $month = $ist->format('Y-m'); $from = $month . '-01 00:00:00'; $to = $ist->modify('first day of next month')->format('Y-m-01 00:00:00');

        $db = db_connect();
        $svc = new SalesTargetService();
        $n = $this->notifier ?? new PushNotifier(null, $now);
        $phones = array_flip(array_map('intval', array_column($db->query("SELECT DISTINCT d.user_id FROM mobile_devices d WHERE d.disabled_at IS NULL")->getResultArray(), 'user_id')));
        $tenants = array_map('intval', array_column($db->query("SELECT DISTINCT tenant_id FROM users WHERE role = 'agent' AND deleted_at IS NULL")->getResultArray(), 'tenant_id'));
        foreach ($tenants as $tenant) {
            $targets = $svc->forMonth($tenant, $month);
            $team = ['revenue' => 0, 'target' => 0]; $late = [];
            foreach ($db->query("SELECT id, name FROM users WHERE tenant_id = ? AND role = 'agent' AND deleted_at IS NULL ORDER BY id", [$tenant])->getResultArray() as $u) {
                $uid = (int) $u['id']; $t = $targets[$uid] ?? null;
                $hasPhone = isset($phones[$uid]);
                if ($hasPhone) { $out['agents']++; }
                if (! $t) { continue; }
                if ($t['since'] !== null && $now - strtotime($t['since'] . ' UTC') < self::TARGET_SETTLE) { continue; }   // changed in the last 2 days: give it time
                $a = $svc->actuals($tenant, $uid, $from, $to);
                $rev = SalesTargetService::pace($a['revenue'], $t['revenue'], $day, $dim);
                $bk  = SalesTargetService::pace($a['bookings'], $t['bookings'], $day, $dim);
                if ($rev['status'] !== 'none') { $team['revenue'] += $a['revenue']; $team['target'] += $t['revenue']; }
                $behind = array_values(array_filter([['revenue', $rev], ['bookings', $bk]], static fn ($x) => $x[1]['status'] === 'behind'));
                if (! $behind) { continue; }
                $worst = min(array_map(static fn ($x) => $x[1]['pct'] / max(1, $x[1]['expected_pct']), $behind));   // how far under the even pace the worst metric is
                $late[] = ['id' => $uid, 'name' => (string) $u['name'], 'worst' => $worst, 'worst_pct' => min(array_map(static fn ($x) => $x[1]['pct'], $behind))];
                $out['team_behind']++;
                if (! $hasPhone) { continue; }
                $out['behind']++;

                $parts = [];
                foreach ($behind as [$k, $p]) {
                    $parts[] = $k === 'revenue'
                        ? self::inr($p['actual']) . ' of ' . self::inr($p['target']) . ' revenue (' . $p['pct'] . '%)'
                        : $p['actual'] . ' of ' . $p['target'] . ' booking' . ($p['target'] === 1 ? '' : 's');
                }
                $left = $dim - $day;
                $body = implode(' · ', $parts) . ' — ' . $left . ' day' . ($left === 1 ? '' : 's') . ' left.';
                $idle = $this->idleEnquiries($tenant, $uid);
                if ($idle > 0) { $body .= ' ' . $idle . ' open enquir' . ($idle === 1 ? 'y has' : 'ies have') . ' no next step — a quick follow-up can help.'; }
                $r = $n->notify($tenant, $uid, 'target', 'target_behind', '🎯 A little behind on your target', $body, ['screen' => 'Targets', 'params' => new \stdClass()], 'target:' . $month . ':cp' . $cp, '/dashboard');
                if ($r['status'] === 'queued') { $out['sent']++; }
            }

            // ---- the same picture for the managers: one summary per checkpoint, only when somebody is behind
            if (! $late) { continue; }
            usort($late, static fn ($x, $y) => $x['worst'] <=> $y['worst']);          // furthest behind first
            $names = array_map(static fn ($x) => $x['name'] . ' ' . $x['worst_pct'] . '%', array_slice($late, 0, 3));
            $more = count($late) - count($names);
            $teamPct = $team['target'] > 0 ? (int) round($team['revenue'] * 100 / $team['target']) : null;
            $body = implode(' · ', $names) . ($more > 0 ? " · +{$more} more" : '') . '.';
            if ($teamPct !== null) { $body .= ' Agents with a target are at ' . self::inr($team['revenue']) . ' of ' . self::inr($team['target']) . ' (' . $teamPct . '%) with ' . ($dim - $day) . ' days left.'; }
            $title = '🎯 ' . count($late) . ' agent' . (count($late) === 1 ? '' : 's') . ' behind pace on targets';
            foreach ($db->query("SELECT id FROM users WHERE tenant_id = ? AND role IN ('owner','admin') AND deleted_at IS NULL", [$tenant])->getResultArray() as $m) {
                if (! isset($phones[(int) $m['id']])) { continue; }
                $r = $n->notify($tenant, (int) $m['id'], 'target', 'target_team', $title, $body, ['screen' => 'Targets', 'params' => new \stdClass()], 'target-team:' . $month . ':cp' . $cp, '/dashboard');
                if ($r['status'] === 'queued') { $out['managers_sent']++; }
            }
        }
        return $out;
    }

    /** Open enquiries of this agent with no open task on their deal - the same rule as the dashboard's "needs a next step". */
    private function idleEnquiries(int $tenantId, int $userId): int
    {
        return (int) (db_connect()->query("SELECT COUNT(*) n FROM trips t WHERE t.tenant_id = ? AND t.owner_id = ? AND t.deleted_at IS NULL AND t.deal_id IS NOT NULL AND t.status IN ('enquiry','quoted','negotiating')
            AND NOT EXISTS (SELECT 1 FROM tasks k WHERE k.tenant_id = t.tenant_id AND k.related_type = 'deal' AND k.related_id = t.deal_id AND k.status = 'open' AND k.deleted_at IS NULL)", [$tenantId, $userId])->getRowArray()['n'] ?? 0);
    }
}
