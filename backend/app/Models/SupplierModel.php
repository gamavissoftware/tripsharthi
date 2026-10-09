<?php

declare(strict_types=1);

namespace App\Models;

class SupplierModel extends BaseModel
{
    protected $table      = 'suppliers';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'name', 'type', 'city', 'country', 'contact_name', 'phone', 'whatsapp', 'email', 'gstin', 'pan', 'payment_terms', 'commission_pct', 'rating', 'notes', 'is_active',
    ];

    protected $validationRules = [
        'name' => 'required|max_length[200]',
        'type' => 'permit_empty|in_list[hotel,transport,activity,dmc,airline,visa,insurance,guide,cruise,other]',
    ];
}
