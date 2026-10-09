<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\IntegrationModel;
use App\Services\Analytics\MetaAdsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Pull Meta ad spend for every tenant with a Meta Ads connection.
 *
 * Cron (daily): 25 5 * * *  php spark meta:ads-sync >> writable/logs/cron_ads.log 2>&1
 */
class MetaAdsSync extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'meta:ads-sync';
    protected $description = 'Sync month-on-month Meta ad spend into ad_spend.';
    protected $options     = ['--months' => 'How many months back (default 12, max 24)', '--tenant' => 'Only this tenant'];

    public function run(array $params): void
    {
        $months  = (int) (CLI::getOption('months') ?? 12);
        $onlyTid = (int) (CLI::getOption('tenant') ?? 0);
        $rows    = (new IntegrationModel())->withoutTenantScope()->where('type', MetaAdsService::TYPE)->where('status', 'active')->findAll();
        $service = new MetaAdsService();

        foreach ($rows as $row) {
            $tenantId = (int) $row['tenant_id'];
            if ($onlyTid > 0 && $tenantId !== $onlyTid) {
                continue;
            }
            try {
                $r = $service->sync($tenantId, $months);
                CLI::write("[meta:ads-sync] tenant {$tenantId}: {$r['rows']} rows across {$r['accounts']} account(s)" . ($r['errors'] ? ' — ' . implode(' | ', $r['errors']) : ''), $r['errors'] ? 'yellow' : 'green');
            } catch (\Throwable $e) {
                CLI::error("[meta:ads-sync] tenant {$tenantId}: " . $e->getMessage());
            }
        }
    }
}
