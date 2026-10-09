<?php

declare(strict_types=1);

namespace App\Models;

class ConversionEventModel extends BaseModel
{
    protected $table      = 'conversion_events';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'contact_id', 'trip_id', 'platform', 'event_name', 'event_id', 'value_amount', 'currency', 'event_time', 'payload', 'status', 'attempts', 'response', 'sent_at',
    ];
}
