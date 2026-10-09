<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\AccountModel;

/**
 * Get-or-create an account (company) by name, within a tenant.
 *
 * Shared by the CSV/XLSX importer and the contact form so a company typed into
 * the form and one arriving in an import file resolve to the same row, by the
 * same rules. Two copies of this logic would drift and quietly produce
 * duplicate companies.
 *
 * Lookups are memoised per instance, so a bulk import resolving the same
 * company across thousands of rows queries once.
 */
class AccountResolver
{
    /** @var array<string,int> "tenantId:lowercased name" => account id */
    private array $cache = [];

    /**
     * @param  string $industry Written only when the account is created — an
     *                          import or a form save must not silently
     *                          re-categorise a company someone has curated.
     * @return int|null         null when the name is blank or the insert fails.
     */
    public function resolve(int $tenantId, string $name, string $industry = ''): ?int
    {
        $name = trim($name);
        if ($name === '' || $tenantId <= 0) {
            return null;
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        $key = $tenantId . ':' . mb_strtolower($name);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $model = new AccountModel();

        $row = $model->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->first();

        if ($row !== null) {
            $id = (int) (is_array($row) ? $row['id'] : $row->id);
            return $this->cache[$key] = $id;
        }

        $payload = ['tenant_id' => $tenantId, 'name' => $name, 'type' => 'prospect'];
        if (trim($industry) !== '') {
            $payload['industry'] = mb_substr(trim($industry), 0, 100);
        }

        $id = (int) $model->withoutTenantScope()->insert($payload, true);

        return $id > 0 ? ($this->cache[$key] = $id) : null;
    }
}
