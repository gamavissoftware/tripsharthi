<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\SegmentModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Named audience segment management.
 *
 * GET    /api/v1/segments              — list
 * POST   /api/v1/segments              — create
 * GET    /api/v1/segments/:id          — show
 * PUT    /api/v1/segments/:id          — update
 * DELETE /api/v1/segments/:id          — soft-delete
 * GET    /api/v1/segments/:id/count    — live contact count for this segment
 */
class SegmentsController extends ResourceController
{
    protected $format = 'json';

    private function model(): SegmentModel
    {
        return (new SegmentModel())->setTenant(CurrentUser::tenantId());
    }

    // GET /api/v1/segments
    public function index(): ResponseInterface
    {
        $segments = $this->model()->orderBy('name', 'ASC')->findAll();
        // Attach live contact count to each segment
        $tenantId  = CurrentUser::tenantId();
        $segModel  = new SegmentModel();
        foreach ($segments as &$seg) {
            $filters        = is_string($seg['filters']) ? json_decode($seg['filters'], true) : ($seg['filters'] ?? []);
            $seg['count']   = $segModel->countMatching($filters ?: [], $tenantId);
            $seg['filters'] = $filters ?: [];
        }
        return $this->respond(['success' => true, 'data' => $segments]);
    }

    // GET /api/v1/segments/:id
    public function show($id = null): ResponseInterface
    {
        $seg = $this->model()->find((int) $id);
        if (! $seg) return $this->failNotFound("Segment #{$id} not found.");
        $seg['filters'] = is_string($seg['filters']) ? (json_decode($seg['filters'], true) ?: []) : ($seg['filters'] ?? []);
        $seg['count']   = (new SegmentModel())->countMatching($seg['filters'], CurrentUser::tenantId());
        return $this->respond(['success' => true, 'data' => $seg]);
    }

    // POST /api/v1/segments
    public function create(): ResponseInterface
    {
        $rules = ['name' => 'required|max_length[255]'];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        // Accept 'conditions' as an alias: a client that posts the wrong key must
        // not silently create a filter-less segment that matches every contact.
        $filters = $this->request->getJsonVar('filters')
            ?? $this->request->getJsonVar('conditions')
            ?? [];
        $id = $this->model()->insert([
            'name'        => $this->request->getJsonVar('name'),
            'description' => $this->request->getJsonVar('description'),
            'filters'     => json_encode($filters),
        ], true);

        $seg = $this->model()->find((int) $id);
        $seg['filters'] = $filters;
        $seg['count']   = (new SegmentModel())->countMatching($filters, CurrentUser::tenantId());
        return $this->respondCreated(['success' => true, 'data' => $seg]);
    }

    // PUT /api/v1/segments/:id
    public function update($id = null): ResponseInterface
    {
        $seg = $this->model()->find((int) $id);
        if (! $seg) return $this->failNotFound("Segment #{$id} not found.");

        $payload = array_filter([
            'name'        => $this->request->getJsonVar('name'),
            'description' => $this->request->getJsonVar('description'),
            'filters'     => ($f = ($this->request->getJsonVar('filters')
                ?? $this->request->getJsonVar('conditions'))) !== null ? json_encode($f) : null,
        ], static fn($v) => $v !== null);

        $this->model()->update((int) $id, $payload);
        return $this->show($id);
    }

    // DELETE /api/v1/segments/:id
    public function delete($id = null): ResponseInterface
    {
        $seg = $this->model()->find((int) $id);
        if (! $seg) return $this->failNotFound("Segment #{$id} not found.");
        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    // GET /api/v1/segments/:id/count — live preview of how many contacts match
    public function count($id = null): ResponseInterface
    {
        $seg     = $this->model()->find((int) $id);
        if (! $seg) return $this->failNotFound("Segment #{$id} not found.");
        $filters = is_string($seg['filters']) ? (json_decode($seg['filters'], true) ?: []) : ($seg['filters'] ?? []);
        $count   = (new SegmentModel())->countMatching($filters, CurrentUser::tenantId());
        return $this->respond(['success' => true, 'count' => $count]);
    }
}
