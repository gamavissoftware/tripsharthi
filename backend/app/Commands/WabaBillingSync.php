<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\WabaAccountModel;
use App\Services\Analytics\MetaBillingService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Pull Meta's WhatsApp billing for every connected WABA.
 *
 * Cron (daily, early morning):
 *   0 5 * * *  php spark waba:billing-sync >> writable/logs/cron_billing.log 2>&1
 *
 *   php spark waba:billing-sync --months 12
 *   php spark waba:billing-sync --tenant 1 --probe   # print what Meta returns, store nothing
 */
class WabaBillingSync extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'waba:billing-sync';
    protected $description = 'Sync month-on-month WhatsApp spend from Meta into waba_billing.';
    protected $options     = [
        '--months' => 'How many months back (default 12, max 24)',
        '--tenant' => 'Only this tenant',
        '--probe'  => 'Fetch and print, do not store',
        '--edge'   => 'With --probe: "conversation" to read conversation_analytics instead',
        '--wabas'  => 'List the WABAs and phone numbers the token can see, then exit',
        '--granularity' => 'With --probe: DAILY or MONTHLY (default)',
    ];

    public function run(array $params): void
    {
        $months   = (int) (CLI::getOption('months') ?? 12);
        $onlyTid  = (int) (CLI::getOption('tenant') ?? 0);
        $probe    = CLI::getOption('probe') !== null;

        $wabaModel = new WabaAccountModel();
        $accounts  = $wabaModel->withoutTenantScope()->where('status', 'active')->findAll();
        $service   = new MetaBillingService();

        foreach ($accounts as $account) {
            $tenantId = (int) $account['tenant_id'];
            if ($onlyTid > 0 && $tenantId !== $onlyTid) {
                continue;
            }
            try {
                if (CLI::getOption('wabas') !== null) {
                    $graph = new \App\Services\Social\GraphClient();
                    $token = $wabaModel->getDecryptedToken($account);
                    $waba  = (string) $account['waba_id'];
                    $info  = $graph->get($waba, ['fields' => 'id,name,currency,timezone_id,owner_business_info', 'access_token' => $token]);
                    CLI::write(sprintf('WABA %s  %s  %s  owner %s', $info['id'] ?? '', $info['name'] ?? '', $info['currency'] ?? '', json_encode($info['owner_business_info'] ?? null)), 'cyan');
                    $nums = $graph->get($waba . '/phone_numbers', ['fields' => 'id,display_phone_number,verified_name,quality_rating', 'access_token' => $token]);
                    foreach ((array) ($nums['data'] ?? []) as $pn) {
                        $pn = (array) $pn;
                        CLI::write(sprintf('    phone %s  %s  %s  %s', $pn['id'] ?? '', $pn['display_phone_number'] ?? '', $pn['verified_name'] ?? '', $pn['quality_rating'] ?? ''));
                    }
                    foreach ((new \App\Models\PhoneNumberModel())->withoutTenantScope()->where('waba_account_id', (int) $account['id'])->findAll() as $local) {
                        CLI::write("    TravelPilot sends from phone_number_id {$local['phone_number_id']} ({$local['display_number']})", 'yellow');
                    }
                    continue;
                }
                if ($probe) {
                    [$s, $e] = MetaBillingService::window($months, time());
                    $data = $service->fetch((string) $account['waba_id'], $wabaModel->getDecryptedToken($account), $s, $e, (string) (CLI::getOption('edge') ?? 'auto'), strtoupper((string) (CLI::getOption('granularity') ?? 'MONTHLY')));
                    CLI::write("tenant {$tenantId} WABA {$account['waba_id']} currency {$data['currency']} via {$data['source']}", 'cyan');
                    foreach ($data['rows'] as $r) {
                        CLI::write(sprintf('  %s  %-15s %-22s vol %6d  cost %10.4f', $r['month'], $r['category'], $r['pricing_type'], $r['volume'], $r['cost']));
                    }
                    continue;
                }
                $r = $service->sync($tenantId, $months);
                CLI::write("[waba:billing-sync] tenant {$tenantId}: {$r['rows']} rows, {$r['months']} months, {$r['currency']} via {$r['source']}", 'green');
            } catch (\Throwable $e) {
                CLI::error("[waba:billing-sync] tenant {$tenantId}: " . $e->getMessage());
            }
        }
    }
}
