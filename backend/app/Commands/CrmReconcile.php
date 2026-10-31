<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Admin\PlatformCrmService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Every 10 minutes: move TripSarthi's own Facebook leads along the Subscriber Funnel (replied / signed up / subscribed). */
class CrmReconcile extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'crm:reconcile';
    protected $description = 'Match marketing-workspace leads to real TripSarthi accounts and update their funnel stage (cron: every 10 minutes).';

    public function run(array $params): void
    {
        $r = (new PlatformCrmService())->reconcile();
        if ($r['adopted'] + $r['signed_up'] + $r['subscribed'] + $r['engaged'] + $r['contacted'] > 0) {
            CLI::write("[crm:reconcile] adopted={$r['adopted']} checked={$r['checked']} contacted={$r['contacted']} engaged={$r['engaged']} signed_up={$r['signed_up']} subscribed={$r['subscribed']}", 'green');
        }
    }
}
