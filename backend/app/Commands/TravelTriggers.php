<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Travel\TravelTriggerService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Cron (hourly): moves bookings to travelling/completed and fires the scheduled travel triggers
 * (quote_stale, departure_soon, passport_expiring, trip_started, trip_completed).
 *   0 * * * * php /path/to/backend/spark travel:triggers
 */
class TravelTriggers extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'travel:triggers';
    protected $description = 'Fire scheduled travel flow triggers and advance booking lifecycle.';

    public function run(array $params): void
    {
        $r = (new TravelTriggerService())->runAll();
        $r['holds_expired'] = (new \App\Services\Travel\DepartureService())->expireHolds();
        $r['gst_reminders'] = (new \App\Services\Billing\Docs\GstFilingReminder())->run();
        CLI::write(implode(' ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($r), $r)));
    }
}
