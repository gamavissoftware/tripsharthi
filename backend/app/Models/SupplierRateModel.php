<?php

declare(strict_types=1);

namespace App\Models;

class SupplierRateModel extends BaseModel
{
    protected $table      = 'supplier_rates';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'supplier_id', 'destination_id', 'service_name', 'service_type', 'unit', 'currency', 'cost_amount', 'valid_from', 'valid_to', 'meta',
    ];

    protected $validationRules = [
        'service_name' => 'required|max_length[200]',
        'supplier_id' => 'required|is_natural_no_zero',
    ];
}
