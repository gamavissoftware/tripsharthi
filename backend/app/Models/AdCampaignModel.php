<?php

declare(strict_types=1);

namespace App\Models;

class AdCampaignModel extends BaseModel
{
    protected $table      = 'ad_campaigns';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'platform', 'origin', 'kind', 'external_id', 'account_ref', 'name', 'objective', 'status', 'effective_status', 'currency', 'daily_budget',
        'destination_id', 'start_date', 'end_date', 'spec', 'children', 'last_error', 'created_by', 'launched_at', 'paused_by_rule', 'last_synced_at',
    ];

    protected $validationRules = [
        'name' => 'required|max_length[255]',
    ];
}
