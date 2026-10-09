<?php

declare(strict_types=1);

namespace App\Services\Travel;

/**
 * Pure scheduling logic for payment reminders (no I/O — unit tested).
 *
 * A step has an `offset_days` relative to the due date (-3 = three days before,
 * 0 = on the day, 5 = five days overdue) and a `kind`: 'message' (WhatsApp) or
 * 'task' (alert a human). For each kind, only the MOST RECENT eligible step is
 * run; older eligible steps that were never sent (cron was off, payment created
 * late) are marked skipped, so a customer is never hit with a burst of reminders.
 */
final class DunningPlanner
{
    /** Defaults used until a tenant customises them. */
    public const DEFAULT_STEPS = [
        ['key' => 'before_3d', 'offset_days' => -3, 'kind' => 'message', 'template_id' => null],
        ['key' => 'due_today', 'offset_days' => 0,  'kind' => 'message', 'template_id' => null],
        ['key' => 'overdue_2d', 'offset_days' => 2, 'kind' => 'message', 'template_id' => null],
        ['key' => 'overdue_5d', 'offset_days' => 5, 'kind' => 'message', 'template_id' => null],
        ['key' => 'escalate_7d', 'offset_days' => 7, 'kind' => 'task', 'template_id' => null],
    ];

    /** Stop messaging customers this long after the due date; a person must take over. */
    public const MAX_MESSAGE_DAYS_OVERDUE = 45;

    /**
     * @param array{status:string,due_date:?string,created_at:?string} $payment
     * @param list<array{key:string,offset_days:int,kind?:string}>     $steps
     * @param list<string>                                              $doneKeys steps already sent/skipped/reserved
     * @return array{run:list<array>,skip:list<string>}
     */
    public static function plan(array $payment, string $today, array $steps, array $doneKeys, int $nowTs, int $minAgeHours = 12): array
    {
        $none = ['run' => [], 'skip' => []];
        if (! in_array($payment['status'], ['pending', 'overdue'], true) || empty($payment['due_date'])) {
            return $none;
        }
        // A freshly created instalment (e.g. the deposit) is being handled by the agent right now.
        if (! empty($payment['created_at']) && $nowTs - strtotime($payment['created_at']) < $minAgeHours * 3600) {
            return $none;
        }

        $days = (int) floor((strtotime($today) - strtotime($payment['due_date'])) / 86400);

        $eligible = ['message' => [], 'task' => []];
        foreach ($steps as $s) {
            if (in_array($s['key'], $doneKeys, true) || (int) $s['offset_days'] > $days) {
                continue;
            }
            $eligible[($s['kind'] ?? 'message') === 'task' ? 'task' : 'message'][] = $s;
        }

        $run = [];
        $skip = [];
        foreach ($eligible as $kind => $list) {
            if (! $list) { continue; }
            usort($list, static fn ($a, $b) => (int) $b['offset_days'] <=> (int) $a['offset_days']);
            $latest = array_shift($list);
            $run[] = $latest;
            foreach ($list as $older) { $skip[] = $older['key']; }
        }
        // Past the cut-off, customers are no longer messaged — only the human task remains.
        if ($days > self::MAX_MESSAGE_DAYS_OVERDUE) {
            foreach ($run as $i => $r) {
                if (($r['kind'] ?? 'message') === 'message') { $skip[] = $r['key']; unset($run[$i]); }
            }
        }
        return ['run' => array_values($run), 'skip' => $skip];
    }

    /** True when `$nowTs` falls inside the tenant's allowed sending hours (Asia/Kolkata). */
    public static function withinSendingHours(int $nowTs, int $fromHour, int $toHour): bool
    {
        $h = (int) (new \DateTimeImmutable('@' . $nowTs))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('G');
        return $h >= $fromHour && $h < $toHour;
    }

    /** Message text used inside the 24h window (free-form). Strictly transactional wording. */
    public static function freeFormText(string $step, array $v): string
    {
        $head = match (true) {
            str_starts_with($step, 'before') => "Hi {$v['name']}, a gentle reminder that a payment for your trip booking {$v['ref']} is due on {$v['due']}.",
            $step === 'due_today'            => "Hi {$v['name']}, your payment for booking {$v['ref']} is due today.",
            $step === 'receipt'              => "Hi {$v['name']}, we have received your payment for booking {$v['ref']}. Thank you!",
            default                          => "Hi {$v['name']}, your payment for booking {$v['ref']} was due on {$v['due']} and is still pending.",
        };
        if ($step === 'receipt') {
            return $head . "\nAmount received: {$v['amount']}.";
        }
        $tail = "\nAmount: {$v['amount']}";
        if (! empty($v['link'])) { $tail .= "\nPay securely here: {$v['link']}"; }
        return $head . $tail . "\nIf you have already paid, please ignore this message.";
    }
}
