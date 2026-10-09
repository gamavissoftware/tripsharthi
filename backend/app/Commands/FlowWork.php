<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\JobModel;
use App\Services\Queue\OrphanedRowSweeper;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Durable job queue worker.
 *
 * Run via cron every minute:
 *   * * * * * /usr/bin/php /path/to/backend/spark flow:work >> /dev/null 2>&1
 *
 * Each invocation:
 *   1. Reclaims stale locks (crashed-worker recovery).
 *   2. Atomically claims up to BATCH due jobs.
 *   3. Processes each job; on success → done; on failure → exponential backoff
 *      reschedule (clears lock owner) or permanent failure at max_attempts.
 *   4. Exits — cron restarts the worker next minute.
 *
 * Phase 2 will replace the stub handlers with real FlowEngine calls.
 * Phase 5 will replace stub campaign_send / lead_import handlers.
 */
class FlowWork extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'flow:work';
    protected $description = 'Process pending queue jobs (flow runs, campaign sends, lead imports).';

    // ── Tuneable constants ────────────────────────────────────────────
    /** Seconds before a locked job is reclaimed as stale. */
    public const STALE_TIMEOUT = 300;
    /** Max jobs claimed per cron invocation. */
    public const BATCH         = 20;
    /** Base for exponential backoff: delay = BACKOFF_BASE ^ attempts (seconds). */
    public const BACKOFF_BASE  = 2;
    /** Hard ceiling on backoff delay (seconds). */
    public const BACKOFF_MAX   = 3600;

    // ------------------------------------------------------------------

    public function run(array $params): void
    {
        $now      = time();
        // Unique per-invocation worker ID — prevents two concurrent instances
        // claiming each other's rows after a restart.
        $workerId = 'wrk_' . substr(bin2hex(random_bytes(8)), 0, 12);
        $model    = new JobModel();

        // ── 1. Reclaim stale locks ────────────────────────────────────
        $model->reclaimStaleLocks(self::STALE_TIMEOUT, $now);

        // ── 1.5. Re-dispatch orphaned source rows ─────────────────────
        // Finds source rows (meta_lead_events / campaigns / lead_imports) stuck
        // in a pre-terminal state with no live job — the "insert-then-dispatch"
        // gap where the source row was committed but JobDispatcher threw before
        // the job row was written.  Runs BEFORE claimBatch so freshly re-dispatched
        // jobs are eligible for claiming in this same invocation.
        $requeued = (new OrphanedRowSweeper())->sweep($now);
        if ($requeued > 0) {
            CLI::write("[flow:work] Swept {$requeued} orphaned source row(s) — jobs re-dispatched.", 'yellow');
        }

        // ── 2. Atomic claim (single UPDATE — see JobModel::claimBatch) ─
        $claimed = $model->claimBatch($workerId, self::BATCH, $now);

        if ($claimed === 0) {
            CLI::write('[flow:work] No pending jobs.', 'green');
            return;
        }

        $jobs = $model->findClaimed($workerId);
        CLI::write("[flow:work] Claimed {$claimed} job(s) as {$workerId}.", 'cyan');

        $done   = 0;
        $failed = 0;
        $retry  = 0;

        // ── 3. Process each claimed job ───────────────────────────────
        foreach ($jobs as $job) {
            $jobId    = (int) $job['id'];
            $attempts = (int) $job['attempts'] + 1; // this attempt number

            try {
                $this->dispatch($job);
                $model->markDone($jobId);
                $done++;
            } catch (\Throwable $e) {
                $maxAttempts = (int) $job['max_attempts'];

                if ($attempts >= $maxAttempts) {
                    // Permanently failed — all retries exhausted
                    $model->markFailed($jobId, $e->getMessage());
                    $failed++;
                    log_message('error',
                        "[flow:work] Job #{$jobId} type={$job['type']} permanently failed "
                        . "after {$attempts} attempt(s): {$e->getMessage()}"
                    );
                    // Terminal-failure wiring: set source row to 'failed' so the
                    // frontend's polling loop sees a terminal state and stops.
                    // Without this the campaigns/lead_imports row stays 'processing'
                    // and the frontend polls forever.
                    self::markSourceRowFailed($job, $e->getMessage());
                } else {
                    // Exponential backoff: delay = BACKOFF_BASE ^ attempts (capped)
                    $delay   = (int) min(
                        (int) round(self::BACKOFF_BASE ** $attempts),
                        self::BACKOFF_MAX
                    );
                    $runAt   = $now + $delay;
                    $model->reschedule($jobId, $runAt, $attempts, $e->getMessage());
                    $retry++;
                    log_message('warning',
                        "[flow:work] Job #{$jobId} type={$job['type']} attempt {$attempts} failed "
                        . "(retry in {$delay}s): {$e->getMessage()}"
                    );
                }
            }
        }

        CLI::write(
            sprintf('[flow:work] done=%d  retry=%d  failed=%d', $done, $retry, $failed),
            $failed > 0 ? 'yellow' : 'green'
        );
    }

    // ------------------------------------------------------------------
    // Handler dispatch
    // ------------------------------------------------------------------

    private function dispatch(array $job): void
    {
        $type     = $job['type']      ?? '';
        $tenantId = (int) ($job['tenant_id'] ?? 0);

        switch ($type) {
            case 'flow_start':
                (new \App\Services\Flow\FlowStartHandler())->handle($job, $tenantId);
                return;

            case 'flow_resume':
                (new \App\Services\Flow\FlowResumeHandler())->handle($job, $tenantId);
                return;

            case 'campaign_send':
                (new \App\Services\Queue\CampaignSendHandler())->handle($job, $tenantId);
                return;

            case 'lead_import':
                (new \App\Services\Queue\LeadImportHandler())->handle($job, $tenantId);
                return;

            case 'meta_lead_fetch':
                (new \App\Services\Queue\MetaLeadFetchHandler())->handle($job, $tenantId);
                return;

            case 'google_lead_process':
                (new \App\Services\Queue\GoogleLeadProcessHandler())->handle($job, $tenantId);
                return;

            case 'webhook_deliver':
                (new \App\Services\Queue\WebhookDeliverHandler())->handle($job, $tenantId);
                return;

            case 'email_campaign_send':
                (new \App\Services\Queue\EmailCampaignSendHandler())->handle($job, $tenantId);
                return;

            default:
                throw new \RuntimeException("Unknown job type: '{$type}'");
        }
    }

    // ------------------------------------------------------------------
    // Terminal-failure → source row wiring
    // ------------------------------------------------------------------

    /**
     * When a job exhausts max_attempts, mark the source business row as
     * 'failed' so the frontend's polling loop sees a terminal state and stops.
     *
     * Only RuntimeExceptions from transient DB failures should be swallowed
     * here — programming errors propagate naturally.
     */
    public static function markSourceRowFailed(array $job, string $lastError): void
    {
        $payload  = json_decode($job['payload'] ?? '{}', true) ?: [];
        $tenantId = (int) ($job['tenant_id'] ?? 0);
        if ($tenantId <= 0) return;

        switch ($job['type'] ?? '') {
            case 'campaign_send':
                $id = (int) ($payload['campaign_id'] ?? 0);
                if ($id > 0) {
                    try {
                        (new \App\Models\CampaignModel())->setTenant($tenantId)->update($id, ['status' => 'failed']);
                        log_message('error', "[flow:work] campaign #{$id} marked failed: {$lastError}");
                    } catch (\RuntimeException $e) {
                        log_message('error', "[flow:work] could not mark campaign #{$id} failed: {$e->getMessage()}");
                    }
                }
                break;

            case 'email_campaign_send':
                $id = (int) ($payload['email_campaign_id'] ?? 0);
                if ($id > 0) {
                    try {
                        (new \App\Models\EmailCampaignModel())->setTenant($tenantId)->update($id, [
                            'status'     => 'failed',
                            'last_error' => mb_substr($lastError, 0, 500),
                        ]);
                        log_message('error', "[flow:work] email campaign #{$id} marked failed: {$lastError}");
                    } catch (\RuntimeException $e) {
                        log_message('error', "[flow:work] could not mark email campaign #{$id} failed: {$e->getMessage()}");
                    }
                }
                break;

            case 'lead_import':
                $id = (int) ($payload['import_id'] ?? 0);
                if ($id > 0) {
                    try {
                        (new \App\Models\LeadImportModel())->setTenant($tenantId)->update($id, ['status' => 'failed']);
                        log_message('error', "[flow:work] import #{$id} marked failed: {$lastError}");
                    } catch (\RuntimeException $e) {
                        log_message('error', "[flow:work] could not mark import #{$id} failed: {$e->getMessage()}");
                    }
                }
                break;

            case 'meta_lead_fetch':
                $eventId = (int) ($payload['meta_lead_event_id'] ?? 0);
                if ($eventId > 0) {
                    try {
                        (new \App\Models\MetaLeadEventModel())->setTenant($tenantId)->update($eventId, ['status' => 'failed']);
                        log_message('error', "[flow:work] meta_lead_event #{$eventId} marked failed: {$lastError}");
                    } catch (\RuntimeException $e) {
                        log_message('error', "[flow:work] could not mark meta_lead_event #{$eventId} failed: {$e->getMessage()}");
                    }
                }
                break;

            case 'google_lead_process':
                $eventId = (int) ($payload['google_lead_event_id'] ?? 0);
                if ($eventId > 0) {
                    try {
                        (new \App\Models\GoogleLeadEventModel())->setTenant($tenantId)->update($eventId, ['status' => 'failed']);
                        log_message('error', "[flow:work] google_lead_event #{$eventId} marked failed: {$lastError}");
                    } catch (\RuntimeException $e) {
                        log_message('error', "[flow:work] could not mark google_lead_event #{$eventId} failed: {$e->getMessage()}");
                    }
                }
                break;
        }
    }
}
