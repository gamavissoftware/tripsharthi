<?php

declare(strict_types=1);

namespace App\Models;

class AssociationModel extends BaseModel
{
    protected $table          = 'associations';
    protected $primaryKey     = 'id';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['tenant_id', 'from_type', 'from_id', 'to_type', 'to_id', 'label'];

    /**
     * Link two records (idempotent on the unique tuple). Returns the row id, or
     * 0 if it already existed.
     */
    public function link(int $tenantId, string $fromType, int $fromId, string $toType, int $toId, ?string $label = null): int
    {
        try {
            return (int) $this->setTenant($tenantId)->insert([
                'from_type' => $fromType, 'from_id' => $fromId,
                'to_type'   => $toType,   'to_id'   => $toId,
                'label'     => $label,
            ], true);
        } catch (\Throwable $e) {
            return 0; // already linked
        }
    }

    /**
     * All associations involving a record, on either side. The caller resolves
     * the "other" endpoint.
     */
    public function forRecord(int $tenantId, string $type, int $id): array
    {
        // Wrap the OR in an outer group so the tenant scope (added by findAll's
        // scopeTenant as a trailing AND) can't be escaped by operator precedence.
        return $this->setTenant($tenantId)
            ->groupStart()
                ->groupStart()->where('from_type', $type)->where('from_id', $id)->groupEnd()
                ->orGroupStart()->where('to_type', $type)->where('to_id', $id)->groupEnd()
            ->groupEnd()
            ->findAll();
    }
}
