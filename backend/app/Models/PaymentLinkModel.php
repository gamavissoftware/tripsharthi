<?php

declare(strict_types=1);

namespace App\Models;

class PaymentLinkModel extends BaseModel
{
    protected $table      = 'payment_links';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'contact_id', 'conversation_id',
        'amount_paise', 'currency', 'description',
        'reference_id', 'razorpay_link_id', 'razorpay_payment_id', 'short_url',
        'status', 'message_id', 'paid_at',
    ];

    /**
     * Resolve a payment link by the Razorpay link id (webhook attribution).
     * Cross-tenant — the webhook doesn't carry tenant context.
     */
    public function findByRazorpayLinkId(string $linkId): array|object|null
    {
        return $this->withoutTenantScope()
            ->where('razorpay_link_id', $linkId)
            ->first();
    }

    /**
     * Resolve by our own reference id (embedded in notes/reference_id).
     */
    public function findByReference(string $reference): array|object|null
    {
        return $this->withoutTenantScope()
            ->where('reference_id', $reference)
            ->first();
    }
}
