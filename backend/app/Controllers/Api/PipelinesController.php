<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\PipelineModel;
use App\Models\PipelineStageModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Pipelines + their stages (CRM Phase B). Lazily provisions a default Generic
 * B2B pipeline for the tenant on first access.
 */
class PipelinesController extends ResourceController
{
    protected $format = 'json';

    // GET /api/v1/pipelines — deal pipelines, each with ordered stages.
    public function index(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $pm = new PipelineModel();
        $pm->ensureDefault($tenantId); // provision if the tenant has none yet

        $pipelines = $pm->dealsPipelines($tenantId);
        $sm = new PipelineStageModel();
        foreach ($pipelines as &$p) {
            $p['stages'] = $sm->forPipeline($tenantId, (int) $p['id']);
        }

        return $this->respond(['success' => true, 'data' => $pipelines]);
    }

    // GET /api/v1/pipelines/:id
    public function show($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $pipeline = (new PipelineModel())->setTenant($tenantId)->find((int) $id);
        if (! $pipeline) {
            return $this->failNotFound("Pipeline #{$id} not found.");
        }
        $pipeline['stages'] = (new PipelineStageModel())->forPipeline($tenantId, (int) $id);
        return $this->respond(['success' => true, 'data' => $pipeline]);
    }

    // PUT /api/v1/pipelines/stages/:id — update a stage's config (Phase H3: rotting_days).
    public function updateStage($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $sm       = (new PipelineStageModel())->setTenant($tenantId);
        if (! $sm->find((int) $id)) {
            return $this->failNotFound("Stage #{$id} not found.");
        }

        $payload = [];
        foreach (['name', 'probability', 'rotting_days'] as $k) {
            $v = $this->request->getJsonVar($k);
            if ($v !== null) {
                $payload[$k] = $k === 'name' ? $v : ($v === '' ? null : (int) $v);
            }
        }
        if ($payload) {
            $sm->update((int) $id, $payload);
        }
        return $this->respond(['success' => true, 'data' => (new PipelineStageModel())->setTenant($tenantId)->find((int) $id)]);
    }
}
