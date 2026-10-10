<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Services\Travel\SalesTargetService;

/**
 * A friendly nudge to an AGENT who is behind pace on their monthly sales target - at most once per checkpoint (day 10 and day 20 of
 * the month, Indian time), sent at about 11:00 IST. Managers are not nudged. Nothing is sent when the agent is on pace or has hit
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

    /** @return array{agents:int, behind:int, sent:int} */
    public function run(): array
    {
        $out = ['agents' => 0, 'behind' => 0, 'sent' => 0];
        $now = $this->now ?? time();
        if (! self::inWindow($now)) { return $out; }
        $ist = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        $day = (int) $ist->format('j'); $dim = (int) $ist->format('t');
        $cp = self::checkpoint($day);
        if ($cp === null) { return $out; }
        $month = $ist->format('Y-m'); $from = $month . '-01 00:00:00'; $to = $ist->modify('first day of next month')->format('Y-m-01 00:00:00');

        $svc = new SalesTargetService();
        $n = $this->notifier ?? new PushNotifier(null, $now);
        $targets = [];   // per workspace
        $agents = db_connect()->query("SELECT DISTINCT u.id, u.tenant_id FROM users u JOIN mobile_devices d ON d.user_id = u.id AND d.disabled_at IS NULL WHERE u.role = 'agent' AND u.deleted_at IS NULL")->getResultArray();
        foreach ($agents as $u) {
            $out['agents']++;
            $tenant = (int) $u['tenant_id']; $uid = (int) $u['id'];
            $targets[$tenant] ??= $svc->forMonth($tenant, $month);
            $t = $targets[$tenant][$uid] ?? null;
            if (! $t) { continue; }
            if ($t['since'] !== null && $now - strtotime($t['since'] . ' UTC') < self::TARGET_SETTLE) { continue; }   // changed in the last 2 days: give it time
            $a = $svc->actuals($tenant, $uid, $from, $to);
            $rev = SalesTargetService::pace($a['revenue'], $t['revenue'], $day, $dim);
            $bk  = SalesTargetService::pace($a['bookings'], $t['bookings'], $day, $dim);
            $behind = array_values(array_filter([['revenue', $rev], ['bookings', $bk]], static fn ($x) => $x[1]['status'] === 'behind'));
            if (! $behind) { continue; }
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

            $r = $n->notify($tenant, $uid, 'target', 'target_behind', '🎯 A little behind on your target', $body, ['screen' => 'Notifications', 'params' => new \stdClass()], 'target:' . $month . ':cp' . $cp, '/dashboard');
            if ($r['status'] === 'queued') { $out['sent']++; }
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
