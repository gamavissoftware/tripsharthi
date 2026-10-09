<?php

declare(strict_types=1);

namespace App\Models;

class LeadScoringRuleModel extends BaseModel
{
    protected $table      = 'lead_scoring_rules';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id', 'weights', 'hot_threshold', 'warm_threshold',
    ];
}
