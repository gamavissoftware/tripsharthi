<?php

declare(strict_types=1);

namespace App\Models;

class AdRuleModel extends BaseModel
{
    protected $table      = 'ad_rules';
    protected $primaryKey = 'id';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $allowedFields = ['tenant_id', 'name', 'platform', 'metric', 'threshold', 'window_days', 'min_spend', 'action', 'enabled', 'last_run_at'];
}
