<?php

declare(strict_types=1);

namespace App\Models;

class MobileDeviceModel extends BaseModel
{
    protected $table      = 'mobile_devices';
    protected $primaryKey = 'id';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $allowedFields = ['tenant_id', 'user_id', 'expo_token', 'platform', 'device_name', 'app_version', 'last_seen_at', 'disabled_at', 'disabled_reason'];
}
