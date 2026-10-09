<?php

declare(strict_types=1);

namespace App\Models;

class PushPreferenceModel extends BaseModel
{
    protected $table      = 'push_preferences';
    protected $primaryKey = 'id';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $allowedFields = ['tenant_id', 'user_id', 'enabled', 'categories', 'quiet_start', 'quiet_end', 'privacy'];
}
