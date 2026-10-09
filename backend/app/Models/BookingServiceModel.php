<?php

declare(strict_types=1);

namespace App\Models;

class BookingServiceModel extends BaseModel
{
    protected $table      = 'booking_services';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'booking_id', 'item_id', 'supplier_id', 'service_type', 'title', 'service_date', 'status', 'confirmation_no', 'cost_amount', 'cost_currency', 'cost_fx', 'paid_fx', 'fx_rate', 'cost_quoted_inr', 'fx_variance', 'paid_amount', 'pay_by', 'voucher_token', 'notes',
    ];

    protected $validationRules = [
        'title' => 'required|max_length[255]',
    ];
}
