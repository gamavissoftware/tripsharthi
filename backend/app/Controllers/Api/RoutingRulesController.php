<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\RoutingRuleModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Inbox auto-assignment rule management.
 *
 * GET    /api/v1/routing-rules        — list (evaluation order)
 * POST   /api/v1/routing-rules        — create
 * PUT    /api/v1/routing-rules/:id    — update
 * DELETE /api/v1/routing-rules/:id    — soft-delete
 */
class RoutingRulesController extends ResourceController
{
    protected $format = 'json';

    private function model(): RoutingRuleModel
    {
        return (new RoutingRuleModel())->setTenant(CurrentUser::tenantId());
    }

    public function index(): ResponseInterface
    {
        $rules = $this->model()
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        return $this->respond(['success' => true, 'data' => $rules]);
    }

    public function create(): ResponseInterface
    {
        $payload = $this->payload();
        if (! $this->validate($this->rules())) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $id = $this->model()->insert($payload, true);
        return $this->respondCreated(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        if (! $this->model()->find((int) $id)) {
            return $this->failNotFound("Routing rule #{$id} not found.");
        }
        if (! $this->validate($this->rules())) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $this->model()->update((int) $id, $this->payload());
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        if (! $this->model()->find((int) $id)) {
            return $this->failNotFound("Routing rule #{$id} not found.");
        }
        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    private function rules(): array
    {
        return [
            'name'       => 'required|max_length[255]',
            'match_type' => 'permit_empty|in_list[any,tag,keyword]',
            'strategy'   => 'permit_empty|in_list[specific,least_loaded]',
        ];
    }

    private function payload(): array
    {
        $strategy = $this->request->getJsonVar('strategy') ?? 'least_loaded';

        // For a 'specific' rule, only keep an assignee that belongs to this tenant.
        $assignee = null;
        if ($strategy === 'specific') {
            $uid = (int) $this->request->getJsonVar('assigned_user_id');
            if ($uid > 0 && (new \App\Models\UserModel())->setTenant(CurrentUser::tenantId())->find($uid)) {
                $assignee = $uid;
            }
        }

        return [
            'name'             => $this->request->getJsonVar('name'),
            'match_type'       => $this->request->getJsonVar('match_type') ?? 'any',
            'match_value'      => $this->request->getJsonVar('match_value'),
            'strategy'         => $strategy,
            'assigned_user_id' => $assignee,
            'sort_order'       => (int) ($this->request->getJsonVar('sort_order') ?? 0),
            'is_active'        => $this->request->getJsonVar('is_active') === false ? 0 : 1,
        ];
    }
}
