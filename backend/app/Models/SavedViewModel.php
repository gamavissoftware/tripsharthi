<?php

declare(strict_types=1);

namespace App\Models;

class SavedViewModel extends BaseModel
{
    protected $table      = 'saved_views';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'entity_type', 'name', 'filters', 'view_columns', 'sort', 'is_shared', 'owner_id',
    ];

    protected $validationRules = [
        'entity_type' => 'required|max_length[40]',
        'name'        => 'required|max_length[120]',
    ];

    /**
     * Views visible to a user for an entity: their own + anything shared.
     */
    public function forUser(int $tenantId, string $entityType, int $userId): array
    {
        return $this->setTenant($tenantId)
            ->where('entity_type', $entityType)
            ->groupStart()
                ->groupStart()->where('is_shared', 1)->groupEnd()
                ->orGroupStart()->where('owner_id', $userId)->groupEnd()
            ->groupEnd()
            ->orderBy('name', 'ASC')
            ->findAll();
    }
}
