<?php

declare(strict_types=1);

namespace App\Models;

class TicketModel extends BaseModel
{
    protected $table      = 'tickets';
    protected $primaryKey = 'id';
    protected bool $ownable    = true;
    protected string $entityType = 'ticket';

    protected $allowedFields = [
        'tenant_id', 'subject', 'description', 'contact_id', 'account_id',
        'status', 'priority', 'owner_id', 'source', 'category', 'conversation_id',
        'sla_due_at', 'escalated_at', 'resolved_at',
    ];

    protected $validationRules = [
        'subject'  => 'required|max_length[255]',
        'status'   => 'permit_empty|in_list[open,pending,resolved,closed]',
        'priority' => 'permit_empty|in_list[low,medium,high,urgent]',
        'source'   => 'permit_empty|in_list[whatsapp,email,web,manual]',
    ];

    /** First-response/resolution SLA hours by priority. */
    private const SLA_HOURS = ['urgent' => 2, 'high' => 4, 'medium' => 24, 'low' => 72];

    /** SLA hours for a priority (Phase H5 business-hours SLA reads this). */
    public static function slaHours(string $priority): int
    {
        return self::SLA_HOURS[$priority] ?? self::SLA_HOURS['medium'];
    }

    /** Compute an SLA due timestamp from a priority, relative to now (or $from). */
    public static function slaDueAt(string $priority, ?int $from = null): string
    {
        $hours = self::SLA_HOURS[$priority] ?? self::SLA_HOURS['medium'];
        $base  = $from ?? time();
        return date('Y-m-d H:i:s', $base + $hours * 3600);
    }

    /** Statuses that count as still-open (SLA clock running). */
    public static function isOpenStatus(string $status): bool
    {
        return in_array($status, ['open', 'pending'], true);
    }

    public function forContact(int $tenantId, int $contactId): array
    {
        return $this->setTenant($tenantId)
            ->where('contact_id', $contactId)
            ->orderBy('updated_at', 'DESC')
            ->findAll(200);
    }
}
