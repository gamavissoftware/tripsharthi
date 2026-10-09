<?php

declare(strict_types=1);

namespace App\Models;

class TravelerModel extends BaseModel
{
    protected $table      = 'travelers';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'booking_id', 'contact_id', 'full_name', 'pax_type', 'gender', 'dob', 'nationality', 'passport_no_enc', 'passport_expiry', 'visa_status', 'meal_pref', 'is_lead', 'docs',
    ];

    protected $validationRules = [
        'full_name' => 'required|max_length[200]',
    ];
}
