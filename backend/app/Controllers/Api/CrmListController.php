<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\SavedViewModel;
use App\Services\Auth\CurrentUser;
use App\Services\Crm\CrmEntityRegistry;
use App\Services\Crm\CrmExporter;
use App\Services\Crm\FilterEngine;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Unified, filterable, paginated list endpoint for CRM entities (Phase G).
 * Drives the saved-view list experience without touching the existing per-entity
 * endpoints or the kanban board.
 *
 * GET /crm/list/:entity        ?view_id= | ?filters=<json> &sort=field:dir &q= &page= &per_page= [&custom_object_id=]
 * GET /crm/list/:entity/meta   field metadata for the filter bar
 */
class CrmListController extends ResourceController
{
    protected $format = 'json';

    public function list($entity = null): ResponseInterface
    {
        if (! CrmEntityRegistry::has((string) $entity)) {
            return $this->failNotFound("Unknown entity '{$entity}'.");
        }
        $cfg      = CrmEntityRegistry::get((string) $entity);
        $tenantId = CurrentUser::tenantId();
        $model    = CrmEntityRegistry::model((string) $entity, $tenantId);

        // Records must be scoped to one custom object.
        if (in_array('custom_object_id', $cfg['requires'] ?? [], true)) {
            $objectId = (int) $this->request->getGet('custom_object_id');
            if ($objectId <= 0) {
                return $this->fail(['custom_object_id' => 'Required for record lists.'], 422);
            }
            $model->where('custom_object_id', $objectId);
        }

        // Resolve filters + sort + columns from a saved view, or from query params.
        [$filters, $sort, $columns] = $this->resolveView($entity, $tenantId, $cfg);

        // Free-text search across the entity's searchable fields.
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '' && ! empty($cfg['searchable'])) {
            $model->groupStart();
            foreach ($cfg['searchable'] as $i => $f) {
                $i === 0 ? $model->like($f, $q) : $model->orLike($f, $q);
            }
            $model->groupEnd();
        }

        (new FilterEngine())->apply($model, $filters, $cfg['filterable']);

        // Opt-in team visibility scope (Phase K3) — default 'all' (unchanged).
        $scope = (string) $this->request->getGet('scope');
        if ($scope === 'mine') {
            $model->where('owner_id', CurrentUser::id());
        } elseif ($scope === 'team') {
            $model->whereIn('owner_id', (new \App\Services\Crm\TeamVisibilityService())->visibleUserIds($tenantId, CurrentUser::id()));
        }

        // Sort (whitelisted) — default newest first.
        [$sortField, $sortDir] = $this->parseSort($sort, $cfg['sortable']);
        $model->orderBy($sortField, $sortDir);

        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?: 25)));
        $data    = $model->paginate($perPage);
        $pager   = $model->pager?->getDetails() ?? ['total' => count($data), 'currentPage' => 1, 'pageCount' => 1];

        return $this->respond([
            'success' => true,
            'data'    => $data,
            'pager'   => ['total' => $pager['total'] ?? count($data), 'page' => $pager['currentPage'] ?? 1, 'pageCount' => $pager['pageCount'] ?? 1],
            'columns' => $columns,
        ]);
    }

    /** GET /crm/export/:entity — current (filtered) view as a CSV download. */
    public function export($entity = null): ResponseInterface
    {
        if (! CrmEntityRegistry::has((string) $entity)) {
            return $this->failNotFound("Unknown entity '{$entity}'.");
        }

        $filters = json_decode((string) ($this->request->getGet('filters') ?: '{}'), true) ?: [];
        $q       = (string) $this->request->getGet('q');
        $objId   = (int) $this->request->getGet('custom_object_id') ?: null;

        $csv      = (new CrmExporter())->export((string) $entity, CurrentUser::tenantId(), $filters, $q, $objId);
        $filename = $entity . '-export-' . date('Ymd-His') . '.csv';

        \App\Services\Crm\AuditLogger::log('exported', (string) $entity, null, null, ['filters' => $filters, 'q' => $q]);

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    public function meta($entity = null): ResponseInterface
    {
        if (! CrmEntityRegistry::has((string) $entity)) {
            return $this->failNotFound("Unknown entity '{$entity}'.");
        }
        $cfg = CrmEntityRegistry::get((string) $entity);
        return $this->respond(['success' => true, 'data' => [
            'label'         => $cfg['label'],
            'searchable'    => $cfg['searchable'],
            'filterable'    => $cfg['filterable'],
            'sortable'      => $cfg['sortable'],
            'columns'       => $cfg['columns'],
            'bulk_set'      => $cfg['bulk_set'],
            'supports_tags' => $cfg['supports_tags'] ?? false,
            'requires'      => $cfg['requires'] ?? [],
        ]]);
    }

    /** @return array{0: array, 1: ?string, 2: array} [filters, sort, columns] */
    private function resolveView(string $entity, int $tenantId, array $cfg): array
    {
        if ($viewId = (int) $this->request->getGet('view_id')) {
            $view = (new SavedViewModel())->setTenant($tenantId)->find($viewId);
            if ($view && $view['entity_type'] === $entity) {
                return [
                    json_decode($view['filters'] ?? '{}', true) ?: [],
                    $view['sort'],
                    json_decode($view['view_columns'] ?? '[]', true) ?: $cfg['columns'],
                ];
            }
        }
        $filters = json_decode((string) ($this->request->getGet('filters') ?: '{}'), true) ?: [];
        return [$filters, $this->request->getGet('sort'), $cfg['columns']];
    }

    /** @return array{0:string,1:string} [field, dir] */
    private function parseSort(?string $sort, array $sortable): array
    {
        $field = 'updated_at';
        $dir   = 'DESC';
        if ($sort && str_contains($sort, ':')) {
            [$f, $d] = explode(':', $sort, 2);
            if (in_array($f, $sortable, true)) {
                $field = $f;
                $dir   = strtoupper($d) === 'ASC' ? 'ASC' : 'DESC';
            }
        } elseif ($sort && in_array($sort, $sortable, true)) {
            $field = $sort;
        }
        // Fall back to a guaranteed-present column.
        if (! in_array($field, $sortable, true)) {
            $field = in_array('created_at', $sortable, true) ? 'created_at' : ($sortable[0] ?? 'id');
        }
        return [$field, $dir];
    }
}
