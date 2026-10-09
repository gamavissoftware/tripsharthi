<?php

declare(strict_types=1);

namespace App\Models;

class LeadAttributionModel extends BaseModel
{
    protected $table      = 'lead_attributions';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'contact_id', 'trip_id', 'touch', 'platform', 'channel', 'campaign_id', 'campaign_name', 'adset_id', 'ad_id', 'form_id', 'lead_id', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'fbp', 'fbc', 'ctwa_clid', 'utm', 'landing_url', 'touched_at',
    ];
}
