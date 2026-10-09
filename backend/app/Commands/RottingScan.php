<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\TenantModel;
use App\Services\Crm\RottingService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Nightly stale-lead re-queue + rotting-deal report (Phase H3). Idempotent:
 * a re-queued contact has no owner so the next run skips it.
 *
 * Run once per day via cron:
 *   45 1 * * * /usr/bin/php /path/to/backend/spark crm:rotting-scan >> /dev/null 2>&1
 */
class RottingScan extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'crm:rotting-scan';
    protected $description = 'Re-queue stale leads and report rotting deals per tenant.';

    public function run(array $params): void
    {
        $svc      = new RottingService();
        $requeued = 0;
        $rotting  = 0;
        foreach ((new TenantModel())->findAll() as $t) {
            $tid       = (int) $t['id'];
            $requeued += $svc->rerouteStaleContacts($tid);
            $rotting  += count($svc->rottingDealIds($tid));
        }
        CLI::write("[crm:rotting-scan] Re-queued {$requeued} stale lead(s); {$rotting} deal(s) currently rotting.", 'green');
    }
}
