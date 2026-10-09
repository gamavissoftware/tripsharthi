<?php

declare(strict_types=1);

namespace App\Models;

class NotificationModel extends BaseModel
{
    protected $table      = 'notifications';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = ['tenant_id', 'user_id', 'type', 'body', 'link', 'read_at', 'created_at'];

    /** Recent notifications for a user (newest first). */
    public function feed(int $tenantId, int $userId, int $limit = 20): array
    {
        return $this->setTenant($tenantId)->where('user_id', $userId)
            ->orderBy('id', 'DESC')->findAll($limit);
    }

    public function unreadCount(int $tenantId, int $userId): int
    {
        return $this->setTenant($tenantId)->where('user_id', $userId)
            ->where('read_at IS NULL', null, false)->countAllResults();
    }
}
