<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\TenantModel;
use App\Services\Crm\RecurringDealRenewalService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Generates renewal deals for won, recurring deals whose renewal date has
 * arrived (Phase L recurring deals). Opt-in — only runs if scheduled.
 * Idempotent: each renewal advances the source deal's next_renewal_at by one
 * interval, so re-running before the next cycle creates nothing.
 *
 * Run once per day via cron:
 *   15 1 * * * /usr/bin/php /path/to/backend/spark deals:renewals >> /dev/null 2>&1
 */
class DealsRenewals extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'deals:renewals';
    protected $description = 'Open renewal deals for won recurring deals that are due.';

    public function run(array $params): void
    {
        $svc     = new RecurringDealRenewalService();
        $created = 0;
        foreach ((new TenantModel())->findAll() as $t) {
            $created += $svc->generateDue((int) $t['id']);
        }
        CLI::write("[deals:renewals] Opened {$created} renewal deal(s).", 'green');
    }
}
