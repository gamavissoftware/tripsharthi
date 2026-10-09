<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\TemplateModel;
use App\Models\WabaAccountModel;
use App\Services\WhatsApp\TemplateApiClient;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Polls Meta for template approval status on all pending/submitted templates.
 *
 * Runs across ALL tenants that have an active WABA account.
 * Also picks up templates that were approved but may have been re-reviewed
 * (Meta can pause/reject previously approved templates).
 *
 * Cron (every 2 hours):
 *   0 *&#47;2 * * * /usr/bin/php /path/to/backend/spark template:sync >> /dev/null 2>&1
 */
class TemplateSync extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'template:sync';
    protected $description = 'Sync WhatsApp template approval statuses from Meta.';

    /** Statuses worth re-checking (not just pending — Meta can reject approved templates) */
    private const SYNC_STATUSES = ['pending', 'submitted', 'approved'];

    public function run(array $params): void
    {
        CLI::write('[template:sync] Starting…', 'cyan');

        $wabaModel = new WabaAccountModel();
        // Cron runs with no tenant context — this sweep is deliberately
        // cross-tenant. Without it the command aborts on the tenancy guard and
        // template approval statuses never refresh from Meta.
        $accounts  = $wabaModel->withoutTenantScope()->where('status', 'active')->findAll();

        if (empty($accounts)) {
            CLI::write('[template:sync] No active WABA accounts. Done.', 'yellow');
            return;
        }

        $totalSynced  = 0;
        $totalChanged = 0;

        foreach ($accounts as $account) {
            $tenantId  = (int) $account['tenant_id'];
            $accountId = (int) $account['id'];
            $rawToken  = $wabaModel->getDecryptedToken($account);

            if (empty($rawToken) || empty($account['waba_id'])) {
                CLI::write("[template:sync] Skipping account #{$accountId} (missing token or waba_id).", 'yellow');
                continue;
            }

            $apiClient     = new TemplateApiClient($account['waba_id'], $rawToken);
            $templateModel = (new TemplateModel())->setTenant($tenantId);

            // Load templates worth syncing for this tenant
            $templates = $templateModel
                ->whereIn('meta_status', self::SYNC_STATUSES)
                // whereNotNull() is a query-builder method the CI4 Model does not
                // proxy — calling it threw BadMethodCallException on every run.
                ->where('meta_template_id IS NOT NULL')
                ->findAll();

            if (empty($templates)) {
                CLI::write("[template:sync] Tenant #{$tenantId}: no templates to sync.", 'green');
                continue;
            }

            CLI::write("[template:sync] Tenant #{$tenantId}: syncing " . count($templates) . " templates…", 'cyan');

            foreach ($templates as $tpl) {
                $result = $apiClient->syncStatus($tpl['meta_template_id']);

                if (! $result['success']) {
                    CLI::write(
                        "[template:sync] Failed to sync '{$tpl['name']}': " . ($result['error'] ?? 'unknown'),
                        'red'
                    );
                    continue;
                }

                $newStatus = $result['meta_status'] ?? null;
                $oldStatus = $tpl['meta_status'];
                $totalSynced++;

                if ($newStatus !== null && $newStatus !== $oldStatus) {
                    $totalChanged++;
                    $update = ['meta_status' => $newStatus];
                    if (! empty($result['rejection_reason'])) {
                        $update['rejection_reason'] = $result['rejection_reason'];
                    }
                    $templateModel->update((int) $tpl['id'], $update);

                    CLI::write(
                        "[template:sync] Tenant #{$tenantId} '{$tpl['name']}': {$oldStatus} → {$newStatus}",
                        $newStatus === 'approved' ? 'green' : ($newStatus === 'rejected' ? 'red' : 'yellow')
                    );
                }
            }
        }

        CLI::write(
            "[template:sync] Done — {$totalSynced} synced, {$totalChanged} status changes.",
            $totalChanged > 0 ? 'yellow' : 'green'
        );
    }
}
