<?php

declare(strict_types=1);

namespace App\Models;

class ItineraryDayModel extends BaseModel
{
    protected $table      = 'itinerary_days';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'itinerary_id', 'day_no', 'title', 'city', 'description', 'image',
    ];

    protected $validationRules = [
        'title' => 'required|max_length[255]',
    ];
}
