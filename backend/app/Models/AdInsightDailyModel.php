<?php

declare(strict_types=1);

namespace App\Models;

class AdInsightDailyModel extends BaseModel
{
    protected $table      = 'ad_insights_daily';
    protected $primaryKey = 'id';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $allowedFields = ['tenant_id', 'platform', 'campaign_external_id', 'day', 'spend', 'impressions', 'clicks', 'leads', 'conversations', 'synced_at'];
}
