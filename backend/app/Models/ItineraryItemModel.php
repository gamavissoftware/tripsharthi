<?php

declare(strict_types=1);

namespace App\Models;

class ItineraryItemModel extends BaseModel
{
    protected $table      = 'itinerary_items';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'itinerary_id', 'day_id', 'type', 'title', 'details', 'supplier_id', 'rate_id', 'quantity', 'nights', 'unit_cost', 'cost_amount', 'cost_currency', 'unit_cost_fx', 'fx_rate', 'is_optional', 'position', 'meta',
    ];

    protected $validationRules = [
        'title' => 'required|max_length[255]',
    ];
}
