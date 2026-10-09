<?php

declare(strict_types=1);

namespace App\Models;

class LeadImportModel extends BaseModel
{
    protected $table      = 'lead_imports';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id', 'entity_type', 'custom_object_id', 'original_filename', 'stored_filename',
        'default_country_code', 'headers', 'mapping',
        'total', 'imported', 'updated_count', 'failed',
        'errors', 'cursor', 'status',
    ];
}
