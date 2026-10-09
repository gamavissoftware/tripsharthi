<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Leads\ScheduledCampaignDispatcher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Promotes due scheduled campaigns into the send queue.
 *
 * Run via cron every minute (alongside flow:work):
 *   * * * * * /usr/bin/php /path/to/backend/spark campaigns:dispatch-scheduled >> /dev/null 2>&1
 *
 * Each due 'scheduled' campaign is flipped to 'processing' and a 'campaign_send'
 * job is enqueued; flow:work then performs the actual chunked send.
 */
class CampaignScheduleDispatch extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'campaigns:dispatch-scheduled';
    protected $description = 'Enqueue scheduled broadcasts whose send time has arrived.';

    public function run(array $params): void
    {
        $result = (new ScheduledCampaignDispatcher())->dispatchDue(time());

        // Email campaigns ride the same minute cron. Isolated so an email-side
        // failure can never stop a WhatsApp broadcast from going out.
        try {
            $email = (new \App\Services\Email\Marketing\EmailCampaignScheduler())->dispatchDue(time());
            if ($email['dispatched'] > 0) {
                CLI::write('[campaigns:dispatch-scheduled] Dispatched ' . $email['dispatched']
                    . ' email campaign(s): #' . implode(', #', $email['ids']), 'cyan');
            }
        } catch (\Throwable $e) {
            log_message('error', '[campaigns:dispatch-scheduled] email campaigns: ' . $e->getMessage());
        }

        // Reply tracking (every 5 min) + the 09:00 IST daily email report.
        try {
            foreach ((new \App\Services\Email\Marketing\EmailInboxRunner())->tick(time()) as $line) {
                CLI::write("[email] {$line}", 'cyan');
            }
        } catch (\Throwable $e) {
            log_message('error', '[campaigns:dispatch-scheduled] email inbox: ' . $e->getMessage());
        }

        if ($result['dispatched'] === 0) {
            CLI::write('[campaigns:dispatch-scheduled] No scheduled campaigns due.', 'green');
            return;
        }

        CLI::write(
            '[campaigns:dispatch-scheduled] Dispatched ' . $result['dispatched']
            . ' campaign(s): #' . implode(', #', $result['ids']),
            'cyan'
        );
    }
}
