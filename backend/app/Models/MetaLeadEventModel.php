<?php

declare(strict_types=1);

namespace App\Models;

class MetaLeadEventModel extends BaseModel
{
    protected $table      = 'meta_lead_events';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id', 'integration_id', 'leadgen_id', 'status', 'contact_id', 'error',
    ];
}
