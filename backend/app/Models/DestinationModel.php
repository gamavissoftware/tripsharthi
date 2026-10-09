<?php

declare(strict_types=1);

namespace App\Models;

class DestinationModel extends BaseModel
{
    protected $table      = 'destinations';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'name', 'country', 'region', 'is_domestic', 'best_months', 'tagline', 'description', 'cover_image', 'visa_info', 'is_active',
    ];

    protected $validationRules = [
        'name' => 'required|max_length[150]',
    ];
}
