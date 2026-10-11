<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Admin\PlatformEmailService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Every minute: send the next few queued marketing e-mails (EMAIL_MARKETING_PER_MINUTE, default 30). */
class PlatformEmailSend extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'platform-email:send';
    protected $description = 'Send queued TripSarthi marketing e-mails (cron: every minute).';

    public function run(array $params): void
    {
        $r = (new PlatformEmailService())->process();
        if ($r['sent'] + $r['failed'] + $r['skipped'] + $r['finished'] > 0) {
            CLI::write("[platform-email:send] sent={$r['sent']} failed={$r['failed']} skipped={$r['skipped']} campaigns_finished={$r['finished']}", $r['failed'] > 0 ? 'yellow' : 'green');
        }
    }
}
