<?php

declare(strict_types=1);

namespace App\Models;

class AdSettingModel extends BaseModel
{
    protected $table      = 'ad_settings';
    protected $primaryKey = 'id';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $allowedFields = ['tenant_id', 'daily_spend_cap', 'min_daily_budget', 'max_increase_pct', 'rules_enabled'];
}
