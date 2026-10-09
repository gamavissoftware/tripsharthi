<?php

declare(strict_types=1);

namespace App\Models;

class RoutingRuleModel extends BaseModel
{
    protected $table      = 'routing_rules';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'name', 'match_type', 'match_value',
        'strategy', 'assigned_user_id', 'sort_order', 'is_active',
    ];

    protected $validationRules = [
        'name'       => 'required|max_length[255]',
        'match_type' => 'in_list[any,tag,keyword]',
        'strategy'   => 'in_list[specific,least_loaded]',
    ];

    /**
     * Active rules for a tenant, in evaluation order.
     *
     * @return array<int, array>
     */
    public function activeOrdered(int $tenantId): array
    {
        return $this->setTenant($tenantId)
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }
}
