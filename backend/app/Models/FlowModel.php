<?php

declare(strict_types=1);

namespace App\Models;

class FlowModel extends BaseModel
{
    protected $table      = 'flows';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'name', 'status', 'trigger_type', 'trigger_config',
        'graph', 'reentry_policy', 'version', 'stats',
    ];

    public function findActive(int $tenantId): array
    {
        return $this->setTenant($tenantId)
                    ->where('status', 'active')
                    ->findAll();
    }

    public function findActiveByTrigger(int $tenantId, string $triggerType): array
    {
        return $this->setTenant($tenantId)
                    ->where('status', 'active')
                    ->where('trigger_type', $triggerType)
                    ->findAll();
    }
}
