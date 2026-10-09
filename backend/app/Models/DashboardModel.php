<?php

declare(strict_types=1);

namespace App\Models;

class DashboardModel extends BaseModel
{
    protected $table      = 'dashboards';
    protected $primaryKey = 'id';

    protected $allowedFields = ['tenant_id', 'name', 'is_default', 'position'];

    /** Return the tenant's dashboards, provisioning a default one if none exist. */
    public function listOrProvision(int $tenantId): array
    {
        $rows = $this->setTenant($tenantId)->orderBy('position', 'ASC')->orderBy('id', 'ASC')->findAll();
        if ($rows) {
            return $rows;
        }
        $id = $this->setTenant($tenantId)->insert(['name' => 'Overview', 'is_default' => 1, 'position' => 0], true);
        return [$this->setTenant($tenantId)->find((int) $id)];
    }
}
