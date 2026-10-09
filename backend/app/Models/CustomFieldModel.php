<?php

declare(strict_types=1);

namespace App\Models;

class CustomFieldModel extends BaseModel
{
    protected $table      = 'custom_fields';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'label', 'field_key', 'type',
        'options', 'is_required', 'sort_order',
    ];

    protected $validationRules = [
        'label'     => 'required|max_length[100]',
        'field_key' => 'required|max_length[50]|regex_match[/^[a-z][a-z0-9_]*$/]',
        'type'      => 'in_list[text,number,date,url,select]',
    ];

    /**
     * Return all custom field definitions for the current tenant, ordered.
     */
    public function allForTenant(): array
    {
        $this->scopeTenant();
        return $this->orderBy('sort_order', 'ASC')
                    ->orderBy('id', 'ASC')
                    ->findAll();
    }
}
