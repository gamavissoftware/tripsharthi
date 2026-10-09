<?php

declare(strict_types=1);

namespace App\Models;

class BookingPaymentModel extends BaseModel
{
    protected $table      = 'booking_payments';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'booking_id', 'label', 'due_date', 'amount', 'status', 'paid_at', 'mode', 'reference', 'payment_link_id', 'reminder_count', 'last_reminded_at',
    ];

    protected $validationRules = [
        'label' => 'required|max_length[100]',
    ];
}
