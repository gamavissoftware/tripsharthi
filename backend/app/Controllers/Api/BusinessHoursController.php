<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\BusinessHoursModel;
use App\Services\Auth\CurrentUser;
use App\Services\Crm\BusinessHoursService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Working-hours config used for business-hours SLA timers (Phase H5).
 *
 * GET /crm/business-hours    effective config
 * PUT /crm/business-hours    update (owner/admin)
 */
class BusinessHoursController extends ResourceController
{
    protected $format = 'json';

    public function index(): ResponseInterface
    {
        return $this->respond(['success' => true, 'data' => (new BusinessHoursService())->config(CurrentUser::tenantId())]);
    }

    public function save(): ResponseInterface
    {
        $role = (string) (CurrentUser::get()['role'] ?? 'agent');
        if (! in_array($role, ['owner', 'admin'], true)) {
            return $this->failForbidden('Only owners and admins can change working hours.');
        }

        $start = (int) $this->request->getJsonVar('start_hour');
        $end   = (int) $this->request->getJsonVar('end_hour');
        $days  = $this->request->getJsonVar('workdays', true);
        if ($start < 0 || $start > 23 || $end < 1 || $end > 24 || $end <= $start) {
            return $this->fail(['hours' => 'start_hour/end_hour must be a valid 0–24 window.'], 422);
        }
        $days = is_array($days) ? array_values(array_filter(array_map('intval', $days), static fn ($d) => $d >= 1 && $d <= 7)) : [1, 2, 3, 4, 5];

        $tenantId = CurrentUser::tenantId();
        $model    = new BusinessHoursModel();
        $existing = $model->setTenant($tenantId)->where('tenant_id', $tenantId)->first();
        $payload  = ['tenant_id' => $tenantId, 'start_hour' => $start, 'end_hour' => $end, 'workdays' => implode(',', $days ?: [1, 2, 3, 4, 5])];

        if ($existing) {
            $model->setTenant($tenantId)->update($existing['id'], $payload);
        } else {
            $model->setTenant($tenantId)->insert($payload);
        }
        return $this->respond(['success' => true, 'data' => (new BusinessHoursService())->config($tenantId)]);
    }
}
