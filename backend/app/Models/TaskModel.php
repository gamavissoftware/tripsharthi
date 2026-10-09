<?php

declare(strict_types=1);

namespace App\Models;

class TaskModel extends BaseModel
{
    protected $table      = 'tasks';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'title', 'description', 'type', 'status', 'priority',
        'due_at', 'reminder_at', 'reminder_sent', 'assigned_user_id', 'related_type', 'related_id',
        'created_by', 'completed_at',
    ];

    protected $validationRules = [
        'title'    => 'required|max_length[255]',
        'type'     => 'permit_empty|in_list[call,whatsapp,email,meeting,todo]',
        'status'   => 'permit_empty|in_list[open,done]',
        'priority' => 'permit_empty|in_list[low,medium,high]',
    ];

    /** Open tasks assigned to a user, soonest due first (nulls last). */
    public function openForUser(int $tenantId, int $userId, int $limit = 200): array
    {
        return $this->setTenant($tenantId)
            ->where('assigned_user_id', $userId)
            ->where('status', 'open')
            ->orderBy('due_at IS NULL, due_at', 'ASC', false) // escape=false: raw expr, nulls last
            ->findAll($limit);
    }

    /** Tasks attached to a CRM record. */
    public function forRecord(int $tenantId, string $relatedType, int $relatedId): array
    {
        return $this->setTenant($tenantId)
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->orderBy('status', 'ASC')   // open before done
            ->orderBy('due_at', 'ASC')
            ->findAll();
    }

    /** Mark a task done (sets completed_at); returns true on success. */
    public function markDone(int $tenantId, int $taskId): bool
    {
        return $this->setTenant($tenantId)->update($taskId, [
            'status'       => 'done',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
