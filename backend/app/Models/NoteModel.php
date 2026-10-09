<?php

declare(strict_types=1);

namespace App\Models;

class NoteModel extends BaseModel
{
    protected $table      = 'notes';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'body', 'related_type', 'related_id', 'is_pinned', 'created_by',
    ];

    protected $validationRules = [
        'body'         => 'required',
        'related_type' => 'required|max_length[40]',
        'related_id'   => 'required|is_natural_no_zero',
    ];

    /** Notes for a record — pinned first, then newest. */
    public function forRecord(int $tenantId, string $relatedType, int $relatedId): array
    {
        return $this->setTenant($tenantId)
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->orderBy('is_pinned', 'DESC')
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }
}
