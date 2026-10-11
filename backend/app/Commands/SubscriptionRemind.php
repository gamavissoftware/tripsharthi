<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Billing\SubscriptionLifecycle;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Daily: end paid periods that have run out (back to Free) and send the 7/3/1-day and "ended" reminders. */
class SubscriptionRemind extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'subscription:remind';
    protected $description = 'Expire ended paid periods and remind workspace owners (cron: daily).';

    public function run(array $params): void
    {
        $life = new SubscriptionLifecycle();
        $moved = $life->expire();
        $r = $life->remind();
        CLI::write("[subscription:remind] {$moved} workspace(s) back on Free; reminders sent={$r['sent']} ended-notices={$r['ended']}", 'green');
    }
}
