<?php

declare(strict_types=1);

namespace App\Models;

class AccountModel extends BaseModel
{
    protected $table      = 'accounts';
    protected $primaryKey = 'id';
    protected bool $ownable    = true;
    protected string $entityType = 'account';

    protected $allowedFields = [
        'tenant_id', 'name', 'domain', 'industry', 'type', 'owner_id',
        'phone', 'website', 'address_line', 'city', 'state', 'country',
        'postal_code', 'annual_revenue', 'employee_count', 'parent_account_id',
    ];

    protected $validationRules = [
        'name' => 'required|max_length[255]',
        'type' => 'permit_empty|in_list[prospect,customer,partner,other]',
    ];

    /** Count of (non-deleted) contacts linked to an account, tenant-scoped. */
    public function contactCount(int $tenantId, int $accountId): int
    {
        // countAllResults() bypasses BaseModel's scopeTenant override, so the
        // tenant_id filter is applied explicitly here.
        return (new ContactModel())->setTenant($tenantId)
            ->where('tenant_id', $tenantId)
            ->where('account_id', $accountId)
            ->countAllResults();
    }
}
