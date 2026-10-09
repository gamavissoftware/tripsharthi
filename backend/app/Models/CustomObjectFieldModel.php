<?php

declare(strict_types=1);

namespace App\Models;

class CustomObjectFieldModel extends BaseModel
{
    protected $table      = 'custom_object_fields';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'custom_object_id', 'field_key', 'label', 'type', 'options', 'required', 'position',
    ];

    protected $validationRules = [
        'field_key' => 'required|max_length[60]',
        'label'     => 'required|max_length[100]',
        'type'      => 'permit_empty|in_list[text,textarea,number,date,select,boolean,email,phone]',
    ];

    /** Ordered fields of a custom object. */
    public function forObject(int $tenantId, int $objectId): array
    {
        return $this->setTenant($tenantId)
            ->where('custom_object_id', $objectId)
            ->orderBy('position', 'ASC')
            ->findAll();
    }
}
