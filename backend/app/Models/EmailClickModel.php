<?php

declare(strict_types=1);

namespace App\Models;

class EmailClickModel extends BaseModel
{
    protected $table          = 'email_clicks';
    protected $primaryKey     = 'id';
    protected $useSoftDeletes = false;
    protected $updatedField   = '';

    protected $allowedFields = ['tenant_id', 'email_id', 'email_campaign_id', 'contact_id', 'url'];
}
