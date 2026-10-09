<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Flow\DateTriggerService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Fires date-based flow triggers (birthdays, reminders).
 *
 * Run once per day via cron:
 *   0 9 * * * /usr/bin/php /path/to/backend/spark flows:dates >> /dev/null 2>&1
 */
class FlowsDateScan extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'flows:dates';
    protected $description = 'Fire date_reached flow triggers (birthdays, renewal reminders).';

    public function run(array $params): void
    {
        $fired = (new DateTriggerService())->run(time());
        CLI::write("[flows:dates] Enqueued {$fired} date-based flow start(s).", $fired > 0 ? 'cyan' : 'green');
    }
}
