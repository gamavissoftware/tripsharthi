<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Travel\DunningService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Cron (every 15 min): WhatsApp payment reminders for booking instalments, for tenants that enabled dunning. */
class BookingsDunning extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'bookings:dunning';
    protected $description = 'Send due/overdue instalment reminders over WhatsApp and raise collection tasks.';

    public function run(array $params): void
    {
        $r = (new DunningService())->runAll();
        CLI::write("tenants={$r['tenants']} sent={$r['sent']} skipped={$r['skipped']} failed={$r['failed']} tasks={$r['tasks']}");
    }
}
