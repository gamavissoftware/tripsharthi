<?php

declare(strict_types=1);

namespace App\Models;

class DealContactModel extends BaseModel
{
    protected $table          = 'deal_contacts';
    protected $primaryKey     = 'id';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['tenant_id', 'deal_id', 'contact_id', 'role'];
}
