<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Explicit record shares (Phase M). Grants a user or team access to a single
 * record that record-level visibility would otherwise hide. v1 treats any share
 * as full (edit) access; the `access` column is stored for forward compatibility.
 */
class RecordShareModel extends BaseModel
{
    protected $table         = 'record_shares';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'tenant_id', 'entity_type', 'entity_id', 'grantee_type', 'grantee_id', 'access', 'created_by',
    ];

    public const ENTITY_TYPES = ['deal', 'contact', 'account', 'ticket', 'custom_record', 'meeting'];
}
