<?php

declare(strict_types=1);

namespace App\Models;

class TripModel extends BaseModel
{
    protected $table      = 'trips';
    protected $primaryKey = 'id';
    protected bool $ownable    = true;
    protected string $entityType = 'trip';

    protected $allowedFields = [
        'tenant_id', 'deal_id', 'contact_id', 'owner_id', 'title', 'trip_type', 'destination_id', 'destination_text', 'is_international', 'origin_city', 'start_date', 'end_date', 'flexible_dates', 'travel_month', 'nights', 'adults', 'children', 'infants', 'child_ages', 'budget_min', 'budget_max', 'budget_basis', 'hotel_category', 'meal_plan', 'interests', 'requirements', 'passport_status', 'visa_status', 'travel_intent', 'ai_summary', 'status',
    ];

    protected $validationRules = [
        'title' => 'required|max_length[255]',
    ];
}
