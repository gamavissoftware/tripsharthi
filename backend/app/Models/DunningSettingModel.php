<?php

declare(strict_types=1);

namespace App\Models;

class DunningSettingModel extends BaseModel
{
    protected $table      = 'dunning_settings';
    protected $primaryKey = 'id';
    protected $allowedFields = ['tenant_id', 'enabled', 'send_from_hour', 'send_to_hour', 'steps'];
}
