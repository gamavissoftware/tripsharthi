<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Travel\ConversionFeedbackService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Cron (every 5 min): deliver pending CRM->ad-platform conversion events. */
class ConversionsSend extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'conversions:send';
    protected $description = 'Send queued conversion events to Meta CAPI / Google Ads.';

    public function run(array $params): void
    {
        $r = (new ConversionFeedbackService())->dispatch(200);
        CLI::write("sent={$r['sent']} failed={$r['failed']} skipped={$r['skipped']}");
    }
}
