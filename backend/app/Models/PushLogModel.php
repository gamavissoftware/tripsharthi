<?php

declare(strict_types=1);

namespace App\Models;

class PushLogModel extends BaseModel
{
    protected $table      = 'push_log';
    protected $primaryKey = 'id';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $allowedFields = ['tenant_id', 'user_id', 'device_id', 'category', 'event', 'dedupe_key', 'title', 'body', 'data', 'status', 'reason', 'ticket_id', 'attempts', 'sent_at', 'receipt_at', 'created_at'];
}
