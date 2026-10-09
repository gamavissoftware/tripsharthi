<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\TenantModel;
use App\Services\Crm\LeadScoringService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Nightly lead-score recompute + recency decay (Phase H1). Idempotent: re-running
 * simply recomputes the same scores from current signals.
 *
 * Run once per day via cron:
 *   30 1 * * * /usr/bin/php /path/to/backend/spark scoring:recalc >> /dev/null 2>&1
 */
class ScoringRecalc extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'scoring:recalc';
    protected $description = 'Recompute every contact lead score (applies recency decay).';

    public function run(array $params): void
    {
        $svc     = new LeadScoringService();
        $tenants = (new TenantModel())->findAll();

        $total = 0;
        foreach ($tenants as $t) {
            $total += $svc->recalcTenant((int) $t['id']);
        }
        CLI::write("[scoring:recalc] Re-scored {$total} contact(s) across " . count($tenants) . ' tenant(s).', 'green');
    }
}
