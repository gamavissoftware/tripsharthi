<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\MetaLeadEventModel;
use App\Services\Flow\JobDispatcher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Re-queue Meta lead events that ended `failed`, e.g. after a bug fix.
 *
 *   php spark meta:leads-retry --ids 14        # specific events
 *   php spark meta:leads-retry --all           # every failed event
 *
 * The leadgen_id is still valid at Meta (leads are retrievable for 90 days),
 * so the fetch simply runs again. Idempotent: an event already processed is
 * left alone.
 */
class MetaLeadsRetry extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'meta:leads-retry';
    protected $description = 'Re-queue failed Meta lead events for a fresh fetch.';
    protected $options     = ['--ids' => 'Comma-separated meta_lead_events ids', '--all' => 'All failed events'];

    public function run(array $params): void
    {
        $idsRaw = (string) (CLI::getOption('ids') ?? '');
        $all    = CLI::getOption('all') !== null;
        if ($idsRaw === '' && ! $all) {
            CLI::error('Give --ids 14,15 or --all.');

            return;
        }

        $model = new MetaLeadEventModel();
        $query = $model->withoutTenantScope()->where('status', 'failed');
        if ($idsRaw !== '') {
            $query->whereIn('id', array_values(array_filter(array_map('intval', explode(',', $idsRaw)))));
        }
        $events = $query->findAll();
        if ($events === []) {
            CLI::write('No failed events matched.', 'yellow');

            return;
        }

        foreach ($events as $e) {
            $id = (int) $e['id'];
            $model->withoutTenantScope()->update($id, ['status' => 'queued', 'error' => null]);
            $jobId = JobDispatcher::dispatch(
                tenantId:   (int) $e['tenant_id'],
                type:       'meta_lead_fetch',
                payload:    ['meta_lead_event_id' => $id],
                sourceType: 'meta_lead_events',
                sourceId:   $id,
            );
            CLI::write("  ✓ event #{$id} (lead {$e['leadgen_id']}) re-queued as job #{$jobId}", 'green');
        }
        CLI::write('flow:work picks these up within a minute.');
    }
}
