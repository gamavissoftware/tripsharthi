<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\DashboardModel;
use App\Models\DashboardWidgetModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Configurable dashboards + their widgets (Phase I1).
 */
class DashboardsController extends ResourceController
{
    protected $format = 'json';

    /** Default widgets seeded onto a tenant's first dashboard. */
    private const SEED = [
        ['type' => 'funnel', 'title' => 'Pipeline by stage', 'width' => 6, 'config' => ['entity' => 'deal', 'metric' => 'sum_value', 'dimension' => 'stage', 'preset' => 'all']],
        ['type' => 'kpi',    'title' => 'Open pipeline value', 'width' => 3, 'config' => ['entity' => 'deal', 'metric' => 'sum_value', 'dimension' => 'none', 'preset' => 'all']],
        ['type' => 'kpi',    'title' => 'Win rate',            'width' => 3, 'config' => ['entity' => 'deal', 'metric' => 'win_rate', 'dimension' => 'none', 'preset' => 'all']],
        ['type' => 'bar',    'title' => 'Contacts by lifecycle','width' => 6, 'config' => ['entity' => 'contact', 'metric' => 'count', 'dimension' => 'lifecycle', 'preset' => 'all']],
    ];

    private function dm(): DashboardModel
    {
        return (new DashboardModel())->setTenant(CurrentUser::tenantId());
    }
    private function wm(): DashboardWidgetModel
    {
        return (new DashboardWidgetModel())->setTenant(CurrentUser::tenantId());
    }

    public function index(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $existing = $this->dm()->orderBy('position', 'ASC')->findAll();
        $isNew    = empty($existing);
        $boards   = $this->dm()->listOrProvision($tenantId);

        if ($isNew) {
            $boardId = (int) $boards[0]['id'];
            foreach (self::SEED as $i => $w) {
                $this->wm()->insert(['dashboard_id' => $boardId, 'type' => $w['type'], 'title' => $w['title'], 'width' => $w['width'], 'position' => $i, 'config' => json_encode($w['config'])]);
            }
        }

        $data = array_map(function ($b) use ($tenantId) {
            $b['widgets'] = array_map([$this, 'present'], $this->wm()->forDashboard($tenantId, (int) $b['id']));
            return $b;
        }, $boards);

        return $this->respond(['success' => true, 'data' => $data]);
    }

    public function create(): ResponseInterface
    {
        $name = trim((string) $this->request->getJsonVar('name'));
        if ($name === '') {
            return $this->fail(['name' => 'A name is required.'], 422);
        }
        // Phase K2: gate on the plan's dashboard limit (SaaS only).
        if (\App\Services\Tenancy\FeatureGate::isSaas()) {
            $chk = (new \App\Services\Billing\PlanLimitChecker())->check(CurrentUser::tenantId(), 'dashboards');
            if (! $chk['allowed']) {
                return $this->fail((new \App\Services\Billing\PlanLimitChecker())->limitExceededResponse('dashboards', $chk['current'], $chk['limit']), 422);
            }
        }
        $id = $this->dm()->insert(['name' => $name, 'position' => 99], true);
        return $this->respondCreated(['success' => true, 'data' => $this->dm()->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        if (! $this->dm()->find((int) $id)) {
            return $this->failNotFound('Dashboard not found.');
        }
        $name = $this->request->getJsonVar('name');
        if ($name !== null) {
            $this->dm()->update((int) $id, ['name' => trim((string) $name)]);
        }
        return $this->respond(['success' => true, 'data' => $this->dm()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        if (! $this->dm()->find((int) $id)) {
            return $this->failNotFound('Dashboard not found.');
        }
        $this->dm()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    // ── Widgets ─────────────────────────────────────────────────────────

    public function addWidget($dashboardId = null): ResponseInterface
    {
        if (! $this->dm()->find((int) $dashboardId)) {
            return $this->failNotFound('Dashboard not found.');
        }
        $id = $this->wm()->insert($this->widgetPayload((int) $dashboardId), true);
        return $this->respondCreated(['success' => true, 'data' => $this->present($this->wm()->find((int) $id))]);
    }

    public function updateWidget($id = null): ResponseInterface
    {
        $w = $this->wm()->find((int) $id);
        if (! $w) {
            return $this->failNotFound('Widget not found.');
        }
        $this->wm()->update((int) $id, $this->widgetPayload((int) $w['dashboard_id'], false));
        return $this->respond(['success' => true, 'data' => $this->present($this->wm()->find((int) $id))]);
    }

    public function deleteWidget($id = null): ResponseInterface
    {
        if (! $this->wm()->find((int) $id)) {
            return $this->failNotFound('Widget not found.');
        }
        $this->wm()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    private function widgetPayload(int $dashboardId, bool $withDashboard = true): array
    {
        $p = [
            'type'     => $this->request->getJsonVar('type') ?? 'kpi',
            'title'    => trim((string) $this->request->getJsonVar('title')) ?: 'Untitled',
            'width'    => (int) ($this->request->getJsonVar('width') ?? 6),
            'position' => (int) ($this->request->getJsonVar('position') ?? 0),
            'config'   => json_encode($this->request->getJsonVar('config', true) ?: []),
        ];
        if ($withDashboard) {
            $p['dashboard_id'] = $dashboardId;
        }
        return $p;
    }

    private function present(array $w): array
    {
        $w['config'] = json_decode($w['config'] ?? '{}', true) ?: [];
        return $w;
    }
}
