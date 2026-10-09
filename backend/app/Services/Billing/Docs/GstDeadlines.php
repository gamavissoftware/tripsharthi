<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

/** Pure due dates for MONTHLY filers: GSTR-1 on the 11th and GSTR-3B on the 20th of the following month. (QRMP quarterly filers have different dates — not modelled.) */
final class GstDeadlines
{
    public const DAY = ['GSTR1' => 11, 'GSTR3B' => 20];

    public static function due(string $period, string $type): string
    {
        return date('Y-m-' . str_pad((string) self::DAY[$type], 2, '0', STR_PAD_LEFT), strtotime($period . '-01 +1 month'));
    }

    /** @return array{status:string,due:string,days:int} status: filed | upcoming | due_soon (<= 3 days) | overdue; days = days late (overdue) or days left */
    public static function status(string $period, string $type, ?string $filedOn, string $today): array
    {
        $due = self::due($period, $type);
        if ($filedOn !== null) { return ['status' => 'filed', 'due' => $due, 'days' => max(0, (int) floor((strtotime($filedOn) - strtotime($due)) / 86400))]; }
        $diff = (int) floor((strtotime($due) - strtotime($today)) / 86400);
        return ['status' => $diff < 0 ? 'overdue' : ($diff <= 3 ? 'due_soon' : 'upcoming'), 'due' => $due, 'days' => abs($diff)];
    }
}
