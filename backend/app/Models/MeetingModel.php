<?php

declare(strict_types=1);

namespace App\Models;

class MeetingModel extends BaseModel
{
    protected $table      = 'meetings';
    protected $primaryKey = 'id';
    protected bool $ownable    = true;
    protected string $entityType = 'meeting';

    protected $allowedFields = [
        'tenant_id', 'title', 'contact_id', 'deal_id', 'owner_id',
        'start_at', 'end_at', 'location', 'notes', 'status', 'reminder_sent',
    ];

    protected $validationRules = [
        'title'    => 'required|max_length[255]',
        'start_at' => 'required',
        'status'   => 'permit_empty|in_list[scheduled,completed,canceled]',
    ];

    /** Meetings whose start_at falls within [from, to], chronological. */
    public function agenda(int $tenantId, string $from, string $to, int $limit = 500): array
    {
        return $this->setTenant($tenantId)
            ->where('start_at >=', $from)
            ->where('start_at <=', $to)
            ->orderBy('start_at', 'ASC')
            ->findAll($limit);
    }

    /** Meetings attached to a contact, soonest first. */
    public function forContact(int $tenantId, int $contactId): array
    {
        return $this->setTenant($tenantId)
            ->where('contact_id', $contactId)
            ->orderBy('start_at', 'ASC')
            ->findAll();
    }
}
