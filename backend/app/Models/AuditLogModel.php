<?php

declare(strict_types=1);

namespace App\Models;

class AuditLogModel extends BaseModel
{
    protected $table      = 'audit_logs';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;
    protected $useTimestamps  = false; // we set created_at explicitly

    protected $allowedFields = [
        'tenant_id', 'actor_user_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'ip', 'created_at',
    ];
}
