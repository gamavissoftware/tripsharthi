<?php

declare(strict_types=1);

namespace App\Models;

class GoogleLeadEventModel extends BaseModel
{
    protected $table      = 'google_lead_events';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id', 'integration_id', 'lead_id', 'payload', 'status', 'contact_id',
    ];
}
