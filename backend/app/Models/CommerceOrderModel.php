<?php

declare(strict_types=1);

namespace App\Models;

class CommerceOrderModel extends BaseModel
{
    protected $table      = 'commerce_orders';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id', 'contact_id', 'conversation_id', 'catalog_id',
        'items', 'total_paise', 'currency', 'status', 'wa_message_id',
    ];
}
