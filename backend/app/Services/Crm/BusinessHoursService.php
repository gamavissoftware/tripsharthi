<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\BusinessHoursModel;
use App\Models\TicketModel;

/**
 * Business-hours-aware SLA timers (Phase H5). SLA "hours" elapse only during the
 * tenant's configured working hours (default Mon–Fri, 09:00–18:00), so an urgent
 * ticket opened at 17:30 is due the next morning, not at 19:30.
 */
final class BusinessHoursService
{
    private const DEFAULTS = ['start_hour' => 9, 'end_hour' => 18, 'workdays' => [1, 2, 3, 4, 5]];

    public function __construct(private BusinessHoursModel $model = new BusinessHoursModel()) {}

    /** @return array{start_hour:int, end_hour:int, workdays:int[]} */
    public function config(int $tenantId): array
    {
        $row = $this->model->setTenant($tenantId)->where('tenant_id', $tenantId)->first();
        if (! $row) {
            return self::DEFAULTS;
        }
        $days  = array_values(array_filter(array_map('intval', explode(',', (string) $row['workdays'])), static fn ($d) => $d >= 1 && $d <= 7));
        $start = (int) $row['start_hour'];
        $end   = (int) $row['end_hour'];
        // Guard a misconfigured window (end <= start, or out of 0..24): with no
        // positive daily window addBusinessHours would spin to its iteration guard
        // and return a far-future SLA. Fall back to the defaults instead.
        if ($end <= $start || $start < 0 || $end > 24) {
            $start = self::DEFAULTS['start_hour'];
            $end   = self::DEFAULTS['end_hour'];
        }
        return [
            'start_hour' => $start,
            'end_hour'   => $end,
            'workdays'   => $days ?: self::DEFAULTS['workdays'],
        ];
    }

    /** True if the clock-hour at $ts falls inside the working window. */
    public function isBusinessHour(array $cfg, int $ts): bool
    {
        $dow  = (int) date('N', $ts);          // 1 (Mon) … 7 (Sun)
        $hour = (int) date('G', $ts);          // 0 … 23
        return in_array($dow, $cfg['workdays'], true) && $hour >= $cfg['start_hour'] && $hour < $cfg['end_hour'];
    }

    /** Advance $from by $hours business hours, minute-precise (no day-boundary over-count). */
    public function addBusinessHours(array $cfg, int $from, int $hours): int
    {
        $remaining = max(0, $hours) * 60; // work in business minutes
        $cursor    = $from;
        $dayStart  = $cfg['start_hour'] * 3600;
        $dayEnd    = $cfg['end_hour'] * 3600;
        $guard     = 0;

        while ($remaining > 0 && $guard < 100000) {
            $guard++;
            $midnight = strtotime(date('Y-m-d 00:00:00', $cursor));
            $secOfDay = $cursor - $midnight;
            $dow      = (int) date('N', $cursor);

            // Skip non-working days, and move into the window if before/after hours.
            if (! in_array($dow, $cfg['workdays'], true) || $secOfDay >= $dayEnd) {
                $cursor = strtotime('tomorrow', $cursor) + $dayStart;
                continue;
            }
            if ($secOfDay < $dayStart) {
                $cursor = $midnight + $dayStart;
                continue;
            }

            // Inside the window: consume as many minutes as fit today.
            $availMin = intdiv($dayEnd - $secOfDay, 60);
            if ($remaining <= $availMin) {
                return $cursor + $remaining * 60;
            }
            $remaining -= $availMin;
            $cursor = strtotime('tomorrow', $cursor) + $dayStart;
        }
        return $cursor;
    }

    /** Business-hours SLA due timestamp for a ticket priority. */
    public function slaDueAt(int $tenantId, string $priority, ?int $from = null): string
    {
        $from ??= time();
        return date('Y-m-d H:i:s', $this->addBusinessHours($this->config($tenantId), $from, TicketModel::slaHours($priority)));
    }
}
