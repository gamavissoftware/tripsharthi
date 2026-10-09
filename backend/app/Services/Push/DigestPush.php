<?php

declare(strict_types=1);

namespace App\Services\Push;

/**
 * One morning briefing per person per day (08:00–08:45 IST), skipped when there is nothing to say. Staff see the whole
 * agency; agents see their own bookings and tasks. Run `push:digest` every 15 minutes.
 */
final class DigestPush
{
    public function __construct(private readonly ?PushNotifier $notifier = null, private readonly ?int $now = null) {}

    public static function inWindow(int $ts): bool
    {
        $h = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        $m = (int) $h->format('G') * 60 + (int) $h->format('i');
        return $m >= 8 * 60 && $m < 8 * 60 + 45;
    }

    /** @return array{users:int,sent:int} */
    public function run(): array
    {
        $now = $this->now ?? time();
        $out = ['users' => 0, 'sent' => 0];
        if (! self::inWindow($now)) { return $out; }
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d');
        $n = $this->notifier ?? new PushNotifier(null, $now);
        $users = db_connect()->query("SELECT DISTINCT u.id, u.tenant_id, u.role FROM users u JOIN mobile_devices d ON d.user_id = u.id AND d.disabled_at IS NULL WHERE u.deleted_at IS NULL")->getResultArray();
        foreach ($users as $u) {
            $out['users']++;
            $s = $this->stats((int) $u['tenant_id'], (int) $u['id'], in_array($u['role'], ['owner', 'admin'], true), $today);
            $parts = array_values(array_filter([
                $s['departures'] ? $s['departures'] . ' departure' . ($s['departures'] > 1 ? 's' : '') . ' today' : null,
                $s['dues_count'] ? $s['dues_count'] . ' payment' . ($s['dues_count'] > 1 ? 's' : '') . ' due (₹' . number_format($s['dues_amount'] / 100, 0, '.', ',') . ')' : null,
                $s['tasks'] ? $s['tasks'] . ' task' . ($s['tasks'] > 1 ? 's' : '') . ' to do' : null,
            ]));
            if (! $parts) { continue; }
            $r = $n->notify((int) $u['tenant_id'], (int) $u['id'], 'digest', 'digest', '☀️ Your day', implode(' · ', $parts), ['screen' => 'Notifications', 'params' => new \stdClass()], 'digest:' . $today, '/dashboard');
            if (in_array($r['status'], ['queued'], true)) { $out['sent']++; }
        }
        return $out;
    }

    /** @return array{departures:int,dues_count:int,dues_amount:int,tasks:int} */
    public function stats(int $tenantId, int $userId, bool $staff, string $today): array
    {
        $db = db_connect();
        $own = $staff ? '' : ' AND b.owner_id = ' . (int) $userId;
        $dep = (int) ($db->query("SELECT COUNT(*) c FROM bookings b WHERE b.tenant_id = ? AND b.deleted_at IS NULL AND b.status NOT IN ('cancelled','completed') AND b.travel_start = ?{$own}", [$tenantId, $today])->getRowArray()['c'] ?? 0);
        $due = $db->query("SELECT COUNT(*) c, COALESCE(SUM(p.amount),0) a FROM booking_payments p JOIN bookings b ON b.id = p.booking_id WHERE p.tenant_id = ? AND p.deleted_at IS NULL AND b.deleted_at IS NULL
                           AND b.status <> 'cancelled' AND p.status IN ('pending','overdue') AND p.due_date <= ?{$own}", [$tenantId, $today])->getRowArray();
        $tasks = (int) ($db->query("SELECT COUNT(*) c FROM tasks WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'open' AND assigned_user_id = ? AND due_at <= ?", [$tenantId, $userId, $today . ' 23:59:59'])->getRowArray()['c'] ?? 0);
        return ['departures' => $dep, 'dues_count' => (int) $due['c'], 'dues_amount' => (int) $due['a'], 'tasks' => $tasks];
    }
}
