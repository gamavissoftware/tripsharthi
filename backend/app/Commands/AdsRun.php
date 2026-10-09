<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Ads\AdCampaignManager;
use App\Services\Ads\AdRulesService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Cron (hourly): pull campaigns + insights for every tenant with a connected ad platform, then evaluate that
 * tenant's automatic rules (pause / notify only).   0 * * * * php /path/to/backend/spark ads:run
 */
class AdsRun extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'ads:run';
    protected $description = 'Sync ad campaigns/insights and run ad rules.';

    public function run(array $params): void
    {
        $tenants = db_connect()->table('integrations')->select('tenant_id')->distinct()->whereIn('type', ['meta_ads', 'google_ads'])->where('status', 'active')->get()->getResultArray();
        $ids = array_map(static fn ($r) => (int) $r['tenant_id'], $tenants);
        if (filter_var(env('ADS_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN)) {
            $ids = array_values(array_unique(array_merge($ids, array_map(static fn ($r) => (int) $r['tenant_id'], db_connect()->table('ad_settings')->select('tenant_id')->get()->getResultArray()))));
        }
        $mgr = new AdCampaignManager();
        foreach ($ids as $tid) {
            $s = $mgr->syncAll($tid);
            $r = (new AdRulesService($mgr))->runTenant($tid);
            CLI::write("tenant {$tid}: sync=" . json_encode($s) . " rules=" . json_encode($r));
        }
        if (! $ids) { CLI::write('No tenants with a connected ad platform.'); }
    }
}
