<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Travel\FxService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Cron (daily, after 17:00 IST when the ECB publishes): refresh every AUTO exchange rate.   30 12 * * * php /path/to/backend/spark fx:refresh */
class FxRefresh extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'fx:refresh';
    protected $description = 'Refresh automatic exchange rates for all tenants.';

    public function run(array $params): void
    {
        $r = (new FxService())->refreshAll();
        foreach ($r as $tenant => $x) { CLI::write("tenant {$tenant}: updated=" . implode(',', $x['updated']) . ($x['skipped'] ? ' skipped=' . json_encode($x['skipped']) : '') . ($x['error'] ? ' ERROR ' . $x['error'] : '')); }
        if (! $r) { CLI::write('No tenants use automatic exchange rates.'); }
    }
}
