<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\SavedViewModel;
use App\Services\Auth\CurrentUser;
use App\Services\Crm\CrmEntityRegistry;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Saved list views (CRM Phase G). Personal + shared, per entity_type.
 */
class SavedViewsController extends ResourceController
{
    protected $format = 'json';

    private function model(): SavedViewModel
    {
        return (new SavedViewModel())->setTenant(CurrentUser::tenantId());
    }

    /** GET /crm/views?entity_type=contact */
    public function index(): ResponseInterface
    {
        $entity = trim((string) $this->request->getGet('entity_type'));
        if (! CrmEntityRegistry::has($entity)) {
            return $this->fail('Unknown entity_type.', 422);
        }
        $rows = (new SavedViewModel())->forUser(CurrentUser::tenantId(), $entity, CurrentUser::id());
        return $this->respond(['success' => true, 'data' => array_map([$this, 'present'], $rows)]);
    }

    public function create(): ResponseInterface
    {
        $entity = trim((string) $this->request->getJsonVar('entity_type'));
        $name   = trim((string) $this->request->getJsonVar('name'));
        if (! CrmEntityRegistry::has($entity)) {
            return $this->fail(['entity_type' => 'Unknown entity_type.'], 422);
        }
        if ($name === '') {
            return $this->fail(['name' => 'A view name is required.'], 422);
        }

        $model = $this->model();
        $id    = $model->insert([
            'entity_type'  => $entity,
            'name'         => $name,
            'filters'      => json_encode($this->request->getJsonVar('filters', true) ?: ['match' => 'and', 'conditions' => []]),
            'view_columns' => json_encode($this->request->getJsonVar('columns', true) ?: []),
            'sort'         => $this->request->getJsonVar('sort'),
            'is_shared'    => (int) ($this->request->getJsonVar('is_shared') ?? 0),
            'owner_id'     => CurrentUser::id(),
        ], true);

        return $this->respondCreated(['success' => true, 'data' => $this->present($model->find((int) $id))]);
    }

    public function update($id = null): ResponseInterface
    {
        $model = $this->model();
        $view  = $model->find((int) $id);
        if (! $view) {
            return $this->failNotFound("View #{$id} not found.");
        }
        if (! $this->canEdit($view)) {
            return $this->failForbidden('You can only edit your own views.');
        }

        $payload = [];
        foreach (['name' => 'name', 'sort' => 'sort'] as $k => $col) {
            if (($v = $this->request->getJsonVar($k)) !== null) {
                $payload[$col] = $v;
            }
        }
        if (($f = $this->request->getJsonVar('filters', true)) !== null) {
            $payload['filters'] = json_encode($f);
        }
        if (($c = $this->request->getJsonVar('columns', true)) !== null) {
            $payload['view_columns'] = json_encode($c);
        }
        if (($s = $this->request->getJsonVar('is_shared')) !== null) {
            $payload['is_shared'] = (int) $s;
        }
        $model->update((int) $id, $payload);
        return $this->respond(['success' => true, 'data' => $this->present($this->model()->find((int) $id))]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model = $this->model();
        $view  = $model->find((int) $id);
        if (! $view) {
            return $this->failNotFound("View #{$id} not found.");
        }
        if (! $this->canEdit($view)) {
            return $this->failForbidden('You can only delete your own views.');
        }
        $model->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    private function canEdit(array $view): bool
    {
        $role = (string) (CurrentUser::get()['role'] ?? 'agent');
        return (int) ($view['owner_id'] ?? 0) === CurrentUser::id() || in_array($role, ['owner', 'admin'], true);
    }

    /** Shape a row for the API: decode JSON, expose view_columns as `columns`. */
    private function present(array $v): array
    {
        return [
            'id'          => (int) $v['id'],
            'entity_type' => $v['entity_type'],
            'name'        => $v['name'],
            'filters'     => json_decode($v['filters'] ?? '{}', true) ?: ['match' => 'and', 'conditions' => []],
            'columns'     => json_decode($v['view_columns'] ?? '[]', true) ?: [],
            'sort'        => $v['sort'],
            'is_shared'   => (int) $v['is_shared'],
            'owner_id'    => $v['owner_id'] !== null ? (int) $v['owner_id'] : null,
        ];
    }
}
