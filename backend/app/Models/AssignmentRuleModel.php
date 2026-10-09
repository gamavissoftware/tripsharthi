<?php

declare(strict_types=1);

namespace App\Models;

class AssignmentRuleModel extends BaseModel
{
    protected $table      = 'assignment_rules';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'entity_type', 'name', 'match_type', 'match_field', 'match_value',
        'strategy', 'assigned_user_id', 'pool', 'capacity', 'last_assigned_user_id',
        'sort_order', 'is_active',
    ];

    /** Active rules for an entity type, in evaluation order. */
    public function activeFor(int $tenantId, string $entityType): array
    {
        return $this->setTenant($tenantId)
            ->where('entity_type', $entityType)
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')
            ->findAll();
    }
}
