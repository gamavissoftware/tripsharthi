<?php

declare(strict_types=1);

namespace App\Models;

class PhoneNumberModel extends BaseModel
{
    protected $table      = 'phone_numbers';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'waba_account_id', 'tenant_id', 'phone_number_id',
        'display_number', 'quality_rating', 'is_default',
    ];

    /**
     * Resolve the tenant's phone_numbers row from Meta's phone_number_id string.
     * Used in webhook receive — no tenant context known yet.
     */
    public function findByMetaPhoneNumberId(string $metaPhoneNumberId): array|object|null
    {
        return $this->withoutTenantScope()
                    ->where('phone_number_id', $metaPhoneNumberId)
                    ->first();
    }

    public function defaultForTenant(int $tenantId): array|object|null
    {
        return $this->setTenant($tenantId)
                    ->where('is_default', 1)
                    ->first();
    }
}
