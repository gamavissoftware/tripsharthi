<?php

declare(strict_types=1);

namespace App\Models;

class QuoteModel extends BaseModel
{
    protected $table      = 'quotes';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'deal_id', 'number', 'status', 'valid_until',
        'currency', 'subtotal', 'total', 'items', 'notes',
    ];

    /** Next per-tenant quote number, e.g. Q-0007. */
    public function nextNumber(int $tenantId): string
    {
        // countAllResults() bypasses BaseModel's scopeTenant override, so the
        // tenant filter is applied explicitly (else numbering leaks across tenants).
        $count = $this->setTenant($tenantId)->where('tenant_id', $tenantId)->withDeleted()->countAllResults();
        return 'Q-' . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    public function forDeal(int $tenantId, int $dealId): array
    {
        return $this->setTenant($tenantId)
            ->where('deal_id', $dealId)
            ->orderBy('id', 'DESC')
            ->findAll();
    }

    /** Quotes for every deal this contact is the primary contact on, newest first. */
    public function forContact(int $tenantId, int $contactId): array
    {
        return $this->setTenant($tenantId)
            ->select('quotes.*, deals.title AS deal_title')
            ->join('deals', 'deals.id = quotes.deal_id')
            ->where('deals.primary_contact_id', $contactId)
            ->where('deals.tenant_id', $tenantId)
            ->orderBy('quotes.id', 'DESC')
            ->findAll();
    }
}
