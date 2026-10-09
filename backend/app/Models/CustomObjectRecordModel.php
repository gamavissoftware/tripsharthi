<?php

declare(strict_types=1);

namespace App\Models;

class CustomObjectRecordModel extends BaseModel
{
    protected $table      = 'custom_object_records';
    protected $primaryKey = 'id';
    protected bool $ownable    = true;
    protected string $entityType = 'custom_record';

    protected $allowedFields = [
        'tenant_id', 'custom_object_id', 'name', 'owner_id', 'data',
    ];

    protected $validationRules = [
        'name' => 'required|max_length[255]',
    ];

    /** Records of a custom object, newest first. */
    public function forObject(int $tenantId, int $objectId, int $limit = 500): array
    {
        return $this->setTenant($tenantId)
            ->where('custom_object_id', $objectId)
            ->orderBy('updated_at', 'DESC')
            ->findAll($limit);
    }
}
