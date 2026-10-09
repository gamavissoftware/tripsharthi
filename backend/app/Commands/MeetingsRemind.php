<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\TenantModel;
use App\Services\Crm\MeetingReminderService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Fires the meeting_reminder flow trigger for meetings about to start.
 *
 * Run EVERY MINUTE, alongside spark flow:work. The scan is a single indexed
 * range query per tenant and matches nothing most minutes, so it is cheap; a
 * coarser schedule just makes the reminder arrive further from the intended
 * 30-minute mark:
 *   [* * * * *] /usr/bin/php /path/to/backend/spark meetings:remind >> /dev/null 2>&1
 */
class MeetingsRemind extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'meetings:remind';
    protected $description = 'Fire meeting_reminder flow triggers for meetings starting shortly.';

    public function run(array $params): void
    {
        $svc   = new MeetingReminderService();
        $fired = 0;
        $due   = 0;

        foreach ((new TenantModel())->findAll() as $t) {
            $res    = $svc->run((int) $t['id']);
            $fired += $res['fired'];
            $due   += $res['due'];
        }

        CLI::write(
            "[meetings:remind] {$due} meeting(s) within "
            . MeetingReminderService::LEAD_MINUTES . " minutes — fired {$fired} reminder(s).",
            $fired > 0 ? 'cyan' : 'green'
        );
    }
}
