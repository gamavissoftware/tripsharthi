<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\AssignmentRuleModel;
use App\Models\UserModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * CRUD for deal/ticket auto-assignment rules (Phase H2). Owner/admin only.
 *
 * GET    /crm/assignment-rules?entity_type=deal
 * POST   /crm/assignment-rules
 * PUT    /crm/assignment-rules/:id
 * DELETE /crm/assignment-rules/:id
 */
class AssignmentRulesController extends ResourceController
{
    protected $format = 'json';

    private function model(): AssignmentRuleModel
    {
        return (new AssignmentRuleModel())->setTenant(CurrentUser::tenantId());
    }

    private function denyAgent(): ?ResponseInterface
    {
        $role = (string) (CurrentUser::get()['role'] ?? 'agent');
        return in_array($role, ['owner', 'admin'], true) ? null : $this->failForbidden('Only owners and admins can manage assignment rules.');
    }

    public function index(): ResponseInterface
    {
        $entity = trim((string) $this->request->getGet('entity_type'));
        $q      = $this->model()->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC');
        if (in_array($entity, ['deal', 'ticket'], true)) {
            $q->where('entity_type', $entity);
        }
        return $this->respond(['success' => true, 'data' => array_map([$this, 'present'], $q->findAll())]);
    }

    public function create(): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        $payload = $this->payload();
        if (! in_array($payload['entity_type'], ['deal', 'ticket'], true)) {
            return $this->fail(['entity_type' => 'Must be deal or ticket.'], 422);
        }
        $id = $this->model()->insert($payload, true);
        return $this->respondCreated(['success' => true, 'data' => $this->present($this->model()->find((int) $id))]);
    }

    public function update($id = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        if (! $this->model()->find((int) $id)) {
            return $this->failNotFound("Rule #{$id} not found.");
        }
        $this->model()->update((int) $id, $this->payload());
        return $this->respond(['success' => true, 'data' => $this->present($this->model()->find((int) $id))]);
    }

    public function delete($id = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        if (! $this->model()->find((int) $id)) {
            return $this->failNotFound("Rule #{$id} not found.");
        }
        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    private function payload(): array
    {
        $strategy = $this->request->getJsonVar('strategy') ?? 'round_robin';
        $tenantId = CurrentUser::tenantId();

        $assignee = null;
        if ($strategy === 'specific') {
            $uid = (int) $this->request->getJsonVar('assigned_user_id');
            if ($uid > 0 && (new UserModel())->setTenant($tenantId)->find($uid)) {
                $assignee = $uid;
            }
        }
        $pool = $this->request->getJsonVar('pool', true);

        return [
            'entity_type'      => $this->request->getJsonVar('entity_type') ?? 'deal',
            'name'             => $this->request->getJsonVar('name'),
            'match_type'       => $this->request->getJsonVar('match_type') ?? 'any',
            'match_field'      => $this->request->getJsonVar('match_field'),
            'match_value'      => $this->request->getJsonVar('match_value'),
            'strategy'         => $strategy,
            'assigned_user_id' => $assignee,
            'pool'             => is_array($pool) && $pool ? json_encode(array_map('intval', $pool)) : null,
            'capacity'         => (int) ($this->request->getJsonVar('capacity') ?? 50),
            'sort_order'       => (int) ($this->request->getJsonVar('sort_order') ?? 0),
            'is_active'        => $this->request->getJsonVar('is_active') === false ? 0 : 1,
        ];
    }

    private function present(array $r): array
    {
        $r['pool']      = json_decode($r['pool'] ?? '[]', true) ?: [];
        $r['is_active'] = (int) $r['is_active'];
        $r['capacity']  = (int) $r['capacity'];
        return $r;
    }
}
