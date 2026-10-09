<?php

declare(strict_types=1);

namespace App\Services\Flow;

use App\Models\FlowModel;
use App\Models\FlowRunModel;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\WindowService;

/**
 * Handles a 'flow_start' job: snapshots the graph, checks reentry,
 * creates the flow_run, and calls FlowEngine::advance().
 */
class FlowStartHandler
{
    public function __construct(
        private readonly ?CloudApiClient $apiClientOverride = null,
        private readonly ?WindowService  $windowSvcOverride = null,
    ) {}

    public function handle(array $job, int $tenantId): void
    {
        $payload   = json_decode($job['payload'] ?? '{}', true) ?: [];
        $flowId    = (int) ($payload['flow_id']    ?? 0);
        $contactId = (int) ($payload['contact_id'] ?? 0);
        $context   = $payload['context'] ?? [];

        // Load flow — if deactivated after job was enqueued, skip silently
        $flow = (new FlowModel())->setTenant($tenantId)->find($flowId);
        if (! $flow || ($flow['status'] ?? '') !== 'active') {
            return;
        }

        $graph      = json_decode($flow['graph'] ?? '{}', true) ?: [];
        $engine     = new FlowEngine($this->apiClientOverride, $this->windowSvcOverride);
        $triggerNode = $engine->findTriggerNode($graph);

        if ($triggerNode === null) return;

        $firstEdge   = $engine->findEdge($graph, $triggerNode['id'], 'next');
        $firstNodeId = $firstEdge['target'] ?? null;
        if (! $firstNodeId) return; // Empty flow body

        // ── Reentry check (once policy) ───────────────────────────────
        if (($flow['reentry_policy'] ?? 'once') === 'once') {
            $existing = (new FlowRunModel())->findActiveRun($flowId, $contactId);
            if ($existing !== null) {
                $existing = (array) $existing;

                // A retry of this same job must not be swallowed by the guard.
                // Attempt 1 inserts the run and can then throw mid-advance (a Meta
                // timeout, a missing WABA); attempt 2 would find that run, return
                // "already enrolled", and strand it in 'running' forever — under
                // reentry_policy=once the contact could never be enrolled again.
                // A parked run is 'waiting' with its own scheduled resume, so only
                // an un-parked 'running' run is resumed here.
                if (($existing['status'] ?? '') === 'running') {
                    (new FlowEngine($this->apiClientOverride, $this->windowSvcOverride))
                        ->advance($existing, $tenantId);
                }

                return; // Already enrolled — never enrol twice
            }
        }

        // ── Create run with graph snapshot ────────────────────────────
        $runModel = new FlowRunModel();
        $runId    = (int) $runModel->setTenant($tenantId)->insert([
            // tenant_id injected by injectTenantId callback
            'flow_id'         => $flowId,
            'contact_id'      => $contactId,
            'current_node_id' => $firstNodeId,
            'status'          => 'running',
            'state'           => json_encode($context),
            'graph_snapshot'  => $flow['graph'], // immutable copy at enrollment time
            'steps_executed'  => 0,
            'entered_at'      => date('Y-m-d H:i:s'),
        ], true);

        $run = (array) $runModel->setTenant($tenantId)->find($runId);

        (new FlowEngine($this->apiClientOverride, $this->windowSvcOverride))
            ->advance($run, $tenantId);
    }
}
