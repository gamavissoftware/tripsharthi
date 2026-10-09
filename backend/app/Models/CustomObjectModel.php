<?php

declare(strict_types=1);

namespace App\Models;

class CustomObjectModel extends BaseModel
{
    protected $table      = 'custom_objects';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'label_singular', 'label_plural', 'api_name', 'icon', 'color',
    ];

    protected $validationRules = [
        'label_singular' => 'required|max_length[80]',
        'label_plural'   => 'required|max_length[80]',
        'api_name'       => 'required|max_length[60]',
    ];

    public function findByApiName(int $tenantId, string $apiName): array|object|null
    {
        return $this->setTenant($tenantId)->where('api_name', $apiName)->first();
    }
}
