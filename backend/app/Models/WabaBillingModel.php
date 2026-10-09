<?php

declare(strict_types=1);

namespace App\Models;

class WabaBillingModel extends BaseModel
{
    protected $table      = 'waba_billing';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'waba_id', 'month', 'category', 'pricing_type',
        'volume', 'cost', 'currency', 'source', 'fetched_at',
    ];
}
