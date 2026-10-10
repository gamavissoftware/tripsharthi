<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Push\DigestPush;
use App\Services\Push\TargetPush;
use App\Services\Push\PushNotifier;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Cron every minute:  retries queued pushes, collects Expo receipts (disables dead devices), purges old logs, and sends the morning briefing in its window. */
class PushRun extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'push:run';
    protected $description = 'Retry queued pushes, check receipts, send the morning briefing and the sales-target nudges.';

    public function run(array $params): void
    {
        $m = (new PushNotifier())->maintain();
        $d = (new DigestPush())->run();
        $t = (new TargetPush())->run();
        CLI::write(sprintf('retried=%d receipts=%d disabled_devices=%d purged=%d digest_users=%d digest_sent=%d target_agents=%d target_behind=%d target_sent=%d team_behind=%d team_sent=%d', $m['retried'], $m['receipts'], $m['disabled'], $m['purged'], $d['users'], $d['sent'], $t['agents'], $t['behind'], $t['sent'], $t['team_behind'], $t['managers_sent']));
    }
}
