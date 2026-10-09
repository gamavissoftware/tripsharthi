<?php

declare(strict_types=1);

namespace App\Models;

class BusinessHoursModel extends BaseModel
{
    protected $table      = 'business_hours';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = ['tenant_id', 'start_hour', 'end_hour', 'workdays'];
}
