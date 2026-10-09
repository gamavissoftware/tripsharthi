<?php

declare(strict_types=1);

namespace App\Models;

class DashboardWidgetModel extends BaseModel
{
    protected $table      = 'dashboard_widgets';
    protected $primaryKey = 'id';

    protected $allowedFields = ['tenant_id', 'dashboard_id', 'type', 'title', 'config', 'position', 'width'];

    public function forDashboard(int $tenantId, int $dashboardId): array
    {
        return $this->setTenant($tenantId)
            ->where('dashboard_id', $dashboardId)
            ->orderBy('position', 'ASC')->orderBy('id', 'ASC')
            ->findAll();
    }
}
