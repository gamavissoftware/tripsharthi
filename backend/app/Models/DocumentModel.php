<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Document attachment metadata, tenant-scoped via BaseModel.
 */
class DocumentModel extends BaseModel
{
    protected $table         = 'documents';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id', 'related_type', 'related_id', 'filename', 'stored_path',
        'mime', 'size_bytes', 'uploaded_by',
    ];

    /** Allowed upload extensions (detected, not client-claimed) and the size cap. */
    public const ALLOWED_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
    public const MAX_BYTES   = 10 * 1024 * 1024; // 10 MB

    public function forRecord(int $tenantId, string $relatedType, int $relatedId): array
    {
        return $this->setTenant($tenantId)
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->orderBy('id', 'DESC')
            ->findAll();
    }
}
