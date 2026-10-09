<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ContactModel;
use App\Models\FlowModel;
use App\Models\FlowRunLogModel;
use App\Models\FlowRunModel;
use App\Services\Auth\CurrentUser;
use App\Services\Flow\FlowEngine;
use App\Services\Flow\FlowValidator;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Flow management — minimal Phase 2 API.
 *
 * GET    /api/v1/flows               — list
 * POST   /api/v1/flows               — create (validates graph)
 * GET    /api/v1/flows/:id           — show + validation result
 * PUT    /api/v1/flows/:id           — update graph (bumps version, re-validates)
 * PATCH  /api/v1/flows/:id/status    — activate / pause
 * GET    /api/v1/flows/:id/runs      — list runs for this flow
 * GET    /api/v1/flows/:id/runs/:rid — show run + logs
 */
class FlowsController extends ResourceController
{
    protected $format = 'json';

    /**
     * Accept a graph as an object/array or as an already-serialised JSON string.
     *
     * A client that posts `graph` as a JSON string used to have it encoded a
     * second time; the stored value then decoded back to a *string*, and
     * FlowValidator::validate() fatalled with a TypeError on activation —
     * leaving a flow that could never be switched on.
     *
     * @return array{nodes: array, edges: array}
     */
    private static function normalizeGraph(mixed $graph): array
    {
        if (is_string($graph)) {
            $decoded = json_decode($graph, true);
            $graph   = is_array($decoded) ? $decoded : [];
        }

        // getJsonVar() hands a JSON object back as stdClass, not an array —
        // an API client posting {"graph": {"nodes": [...]}} used to have the
        // whole graph replaced with an empty one, silently.
        if (is_object($graph)) {
            $graph = json_decode(json_encode($graph), true) ?: [];
        }

        if (! is_array($graph)) {
            $graph = [];
        }

        return ['nodes' => $graph['nodes'] ?? [], 'edges' => $graph['edges'] ?? []];
    }

    /** Decode a stored graph, tolerating legacy double-encoded rows. */
    private static function decodeGraph(?string $stored): array
    {
        $graph = json_decode($stored ?? '{}', true);

        // A double-encoded row decodes to a string — decode once more.
        if (is_string($graph)) {
            $graph = json_decode($graph, true);
        }

        return is_array($graph) ? $graph : [];
    }

    private function model(): FlowModel
    {
        return (new FlowModel())->setTenant(CurrentUser::tenantId());
    }

    // GET /api/v1/flows
    // Returns flows with run counts (total_runs, completed_runs, active_runs) for the list UI.
    public function index(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $db       = db_connect();

        $rows = $db->query(
            'SELECT f.*,
                (SELECT COUNT(*) FROM flow_runs fr
                 WHERE fr.flow_id = f.id AND fr.tenant_id = f.tenant_id AND fr.deleted_at IS NULL) AS total_runs,
                (SELECT COUNT(*) FROM flow_runs fr
                 WHERE fr.flow_id = f.id AND fr.tenant_id = f.tenant_id AND fr.deleted_at IS NULL AND fr.status = "completed") AS completed_runs,
                (SELECT COUNT(*) FROM flow_runs fr
                 WHERE fr.flow_id = f.id AND fr.tenant_id = f.tenant_id AND fr.deleted_at IS NULL AND fr.status = "running") AS active_runs
             FROM flows f
             WHERE f.tenant_id = ? AND f.deleted_at IS NULL
             ORDER BY f.id DESC',
            [$tenantId]
        )->getResultArray();

        return $this->respond(['success' => true, 'data' => $rows]);
    }

    // GET /api/v1/flows/:id
    public function show($id = null): ResponseInterface
    {
        $flow = $this->model()->find((int) $id);
        if (! $flow) return $this->failNotFound("Flow #{$id} not found.");

        $graph      = self::decodeGraph($flow['graph'] ?? null);
        $validation = (new FlowValidator())->validate($flow, $graph, CurrentUser::tenantId());

        return $this->respond(['success' => true, 'data' => $flow, 'validation' => $validation]);
    }

    // POST /api/v1/flows
    public function create(): ResponseInterface
    {
        $rules = [
            'name'         => 'required|max_length[255]',
            'trigger_type' => 'required|in_list[' . \App\Services\Flow\FlowTriggers::inListRule() . ']',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $graph          = self::normalizeGraph($this->request->getJsonVar('graph'));
        $triggerConfig  = $this->request->getJsonVar('trigger_config');
        $reentryPolicy  = $this->request->getJsonVar('reentry_policy') ?? 'once';

        $id = $this->model()->insert([
            'name'            => $this->request->getJsonVar('name'),
            'trigger_type'    => $this->request->getJsonVar('trigger_type'),
            'trigger_config'  => $triggerConfig ? json_encode($triggerConfig) : null,
            'graph'           => json_encode($graph),
            'reentry_policy'  => $reentryPolicy,
        ], true);

        return $this->respondCreated(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    // PUT /api/v1/flows/:id
    public function update($id = null): ResponseInterface
    {
        $flow = $this->model()->find((int) $id);
        if (! $flow) return $this->failNotFound("Flow #{$id} not found.");
        if ($flow['status'] === 'active') {
            return $this->fail('Deactivate the flow before editing.', 422);
        }

        $graph = $this->request->getJsonVar('graph');

        $payload = array_filter([
            'name'           => $this->request->getJsonVar('name'),
            'trigger_type'   => $this->request->getJsonVar('trigger_type'),
            'trigger_config' => $this->request->getJsonVar('trigger_config')
                ? json_encode($this->request->getJsonVar('trigger_config')) : null,
            'graph'          => $graph !== null ? json_encode(self::normalizeGraph($graph)) : null,
            'reentry_policy' => $this->request->getJsonVar('reentry_policy'),
            'version'        => ((int) $flow['version']) + 1,
        ], static fn ($v) => $v !== null);

        $this->model()->update((int) $id, $payload);
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    // PATCH /api/v1/flows/:id/status
    public function setStatus($id = null): ResponseInterface
    {
        $flow   = $this->model()->find((int) $id);
        if (! $flow) return $this->failNotFound("Flow #{$id} not found.");

        $status = $this->request->getJsonVar('status');
        if (! in_array($status, ['active', 'paused', 'draft'], true)) {
            return $this->fail(['status' => 'Must be active, paused, or draft.'], 422);
        }

        if ($status === 'active') {
            $graph      = self::decodeGraph($flow['graph'] ?? null);
            $validation = (new FlowValidator())->validate($flow, $graph, CurrentUser::tenantId());
            if (! $validation['valid']) {
                return $this->fail([
                    'message' => 'Flow has validation errors and cannot be activated.',
                    'errors'  => $validation['errors'],
                ], 422);
            }
        }

        $this->model()->update((int) $id, ['status' => $status]);
        return $this->respond(['success' => true, 'status' => $status]);
    }

    // DELETE /api/v1/flows/:id
    public function delete($id = null): ResponseInterface
    {
        $flow = $this->model()->find((int) $id);
        if (! $flow) return $this->failNotFound("Flow #{$id} not found.");

        if ($flow['status'] === 'active') {
            return $this->fail('Pause or draft the flow before deleting it.', 422);
        }

        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true, 'message' => 'Flow deleted.']);
    }

    // POST /api/v1/flows/:id/test
    // Dry-run the flow against one contact — no real WhatsApp messages sent.
    public function test($id = null): ResponseInterface
    {
        $tenantId  = CurrentUser::tenantId();
        $flow      = $this->model()->find((int) $id);
        if (! $flow) return $this->failNotFound("Flow #{$id} not found.");

        $contactId = (int) ($this->request->getJsonVar('contact_id') ?? 0);
        if ($contactId === 0) {
            return $this->fail(['contact_id' => 'contact_id is required.'], 422);
        }

        $contact = (new ContactModel())->setTenant($tenantId)->find($contactId);
        if (! $contact) {
            return $this->fail(['contact_id' => "Contact #{$contactId} not found."], 422);
        }

        $graph = self::decodeGraph($flow['graph'] ?? null);

        // Find trigger node to use as entry point
        $triggerTypes = ['lead_created','tag_added','form_submitted','meta_lead_received','google_lead_received','keyword_reply','inbound_message','deal_created','deal_stage_changed','deal_won','deal_lost','ticket_created','ticket_resolved'];
        $entryNode    = null;
        foreach ($graph['nodes'] ?? [] as $n) {
            if (in_array($n['type'] ?? '', $triggerTypes, true)) {
                $entryNode = $n;
                break;
            }
        }
        if ($entryNode === null) {
            return $this->fail('Flow has no trigger node — cannot test.', 422);
        }

        // Find the first non-trigger node reachable from trigger
        $firstEdge  = null;
        foreach ($graph['edges'] ?? [] as $e) {
            if ($e['source'] === $entryNode['id']) { $firstEdge = $e; break; }
        }
        $startNodeId = $firstEdge['target'] ?? null;

        // Create a test flow_run row
        $runModel = new FlowRunModel();
        $runId    = (int) $runModel->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'flow_id'         => (int) $id,
            'contact_id'      => $contactId,
            'current_node_id' => $startNodeId,
            'status'          => 'running',
            'is_test'         => 1,
            'graph_snapshot'  => $flow['graph'],
            'state'           => '{}',
            'steps_executed'  => 0,
            'entered_at'      => date('Y-m-d H:i:s'),
        ], true);

        // Execute synchronously
        $run = $runModel->withoutTenantScope()->find($runId);
        try {
            (new FlowEngine())->advance(is_array($run) ? $run : (array) $run, $tenantId);
        } catch (\Throwable $e) {
            log_message('warning', "FlowsController::test run#{$runId}: " . $e->getMessage());
        }

        // Return run + trace logs. FlowRunLogModel extends CI4's base Model
        // (no tenant scope) and keys on flow_run_id — use its helper.
        $finalRun = $runModel->withoutTenantScope()->find($runId);
        $logs     = (new FlowRunLogModel())->logsForRun($runId);

        return $this->respond([
            'success'    => true,
            'run_id'     => $runId,
            'status'     => is_array($finalRun) ? ($finalRun['status'] ?? 'unknown') : 'unknown',
            'trace'      => array_map(static fn($l) => [
                'node_id'  => $l['node_id'],
                'node_type'=> $l['node_type'],
                'outcome'  => $l['result'] ?? null, // column is 'result'
                'detail'   => $l['detail'],
                'ts'       => $l['created_at'],
            ], $logs),
        ]);
    }

    // GET /api/v1/flows/:id/runs
    // Phase 4 backend change (flagged): JOIN contacts to return contact_name + wa_number for the runs list UI.
    public function runs($id = null): ResponseInterface
    {
        $flow = $this->model()->find((int) $id);
        if (! $flow) return $this->failNotFound("Flow #{$id} not found.");

        $tenantId = CurrentUser::tenantId();
        $db       = db_connect();

        $runs = $db->query(
            'SELECT fr.*, c.name AS contact_name, c.wa_number AS contact_wa
             FROM   flow_runs fr
             LEFT   JOIN contacts c
               ON   c.id = fr.contact_id AND c.deleted_at IS NULL
             WHERE  fr.tenant_id = ? AND fr.flow_id = ? AND fr.deleted_at IS NULL
             ORDER  BY fr.created_at DESC
             LIMIT  50',
            [$tenantId, (int) $id]
        )->getResultArray();

        return $this->respond(['success' => true, 'data' => $runs]);
    }

    // GET /api/v1/flows/:id/runs/:rid
    public function runDetail($id = null, $rid = null): ResponseInterface
    {
        $flow = $this->model()->find((int) $id);
        if (! $flow) return $this->failNotFound("Flow #{$id} not found.");

        $run = (new FlowRunModel())
            ->setTenant(CurrentUser::tenantId())
            ->where('flow_id', (int) $id)
            ->find((int) $rid);
        if (! $run) return $this->failNotFound("Run #{$rid} not found.");

        $logs = (new FlowRunLogModel())->logsForRun((int) $rid);

        return $this->respond(['success' => true, 'data' => $run, 'logs' => $logs]);
    }
}
