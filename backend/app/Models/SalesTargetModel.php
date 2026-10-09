<?php

declare(strict_types=1);

namespace App\Models;

class SalesTargetModel extends BaseModel
{
    protected $table      = 'sales_targets';
    protected $primaryKey = 'id';

    protected $allowedFields = ['tenant_id', 'user_id', 'metric', 'period_start', 'period_end', 'target_amount'];
}
