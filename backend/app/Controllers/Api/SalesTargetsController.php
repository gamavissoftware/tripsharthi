<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\SalesTargetModel;
use App\Models\UserModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Per-user sales targets/quotas (Phase I3). Owner/admin only for writes.
 */
class SalesTargetsController extends ResourceController
{
    protected $format = 'json';

    private function model(): SalesTargetModel
    {
        return (new SalesTargetModel())->setTenant(CurrentUser::tenantId());
    }

    private function denyAgent(): ?ResponseInterface
    {
        $role = (string) (CurrentUser::get()['role'] ?? 'agent');
        return in_array($role, ['owner', 'admin'], true) ? null : $this->failForbidden('Only owners and admins can manage targets.');
    }

    public function index(): ResponseInterface
    {
        $rows  = $this->model()->orderBy('period_start', 'DESC')->findAll();
        $users = [];
        foreach ((new UserModel())->setTenant(CurrentUser::tenantId())->findAll() as $u) {
            $users[(int) $u['id']] = $u['name'];
        }
        $rows = array_map(static function ($r) use ($users) {
            $r['user_name'] = $users[(int) $r['user_id']] ?? ('User #' . $r['user_id']);
            return $r;
        }, $rows);
        return $this->respond(['success' => true, 'data' => $rows]);
    }

    public function create(): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        $payload = $this->payload();
        if ($payload['user_id'] <= 0 || ! $payload['period_start'] || ! $payload['period_end']) {
            return $this->fail(['target' => 'user_id, period_start and period_end are required.'], 422);
        }
        $id = $this->model()->insert($payload, true);
        return $this->respondCreated(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        if (! $this->model()->find((int) $id)) {
            return $this->failNotFound('Target not found.');
        }
        $this->model()->update((int) $id, $this->payload());
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        if (! $this->model()->find((int) $id)) {
            return $this->failNotFound('Target not found.');
        }
        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    private function payload(): array
    {
        $metric = $this->request->getJsonVar('metric') === 'won_count' ? 'won_count' : 'won_value';
        return [
            'user_id'       => (int) $this->request->getJsonVar('user_id'),
            'metric'        => $metric,
            'period_start'  => $this->request->getJsonVar('period_start'),
            'period_end'    => $this->request->getJsonVar('period_end'),
            'target_amount' => (int) $this->request->getJsonVar('target_amount'),
        ];
    }
}
