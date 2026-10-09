<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Social\SocialPublisher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Publishes due scheduled social posts (Facebook Page / Instagram Business).
 *
 * Run via cron every minute (alongside flow:work):
 *   * * * * * /usr/bin/php /path/to/backend/spark social:dispatch >> /dev/null 2>&1
 */
class SocialDispatch extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'social:dispatch';
    protected $description = 'Publish scheduled social posts whose time has arrived.';

    public function run(array $params): void
    {
        $result = (new SocialPublisher())->dispatchDue();

        if ($result['ids'] === []) {
            CLI::write('[social:dispatch] No scheduled posts due.', 'green');

            return;
        }

        CLI::write(sprintf(
            '[social:dispatch] Processed %d post(s): %d published, %d failed (#%s)',
            count($result['ids']),
            $result['published'],
            $result['failed'],
            implode(', #', $result['ids'])
        ), 'cyan');
    }
}
