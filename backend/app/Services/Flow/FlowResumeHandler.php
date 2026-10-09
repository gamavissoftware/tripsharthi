<?php

declare(strict_types=1);

namespace App\Services\Flow;

use App\Models\FlowRunModel;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\WindowService;

/**
 * Handles a 'flow_resume' job: loads the run and calls FlowEngine::advance().
 *
 * ── Fix #2: status IN ('waiting', 'running') ──────────────────────────
 * A thrown advance() leaves status='running' (the job queue sets it back to
 * 'pending' for retry).  If we only accepted 'waiting', the retry would skip
 * and the run would be stranded forever.
 *
 * Accepting 'running' on resume is safe because:
 *   - The job claim is the real concurrency lock (one worker holds the job).
 *   - current_node_id points AT the node that failed — only that node re-runs.
 *   - Nodes before it already ran successfully and are not re-executed.
 */
class FlowResumeHandler
{
    public function __construct(
        private readonly ?CloudApiClient $apiClientOverride = null,
        private readonly ?WindowService  $windowSvcOverride = null,
    ) {}

    public function handle(array $job, int $tenantId): void
    {
        $payload   = json_decode($job['payload'] ?? '{}', true) ?: [];
        $runId     = (int) ($payload['flow_run_id'] ?? 0);

        // Fix #2: accept 'waiting' (normal resume) OR 'running' (thrown mid-advance retry)
        $run = (new FlowRunModel())
            ->setTenant($tenantId)
            ->whereIn('status', ['waiting', 'running'])
            ->find($runId);

        if ($run === null) {
            // Completed / stopped / failed — skip silently
            return;
        }

        (new FlowEngine($this->apiClientOverride, $this->windowSvcOverride))
            ->advance((array) $run, $tenantId);
    }
}
