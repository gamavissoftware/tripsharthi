<?php

declare(strict_types=1);

namespace App\Models;

class BookingModel extends BaseModel
{
    protected $table      = 'bookings';
    protected $primaryKey = 'id';
    protected bool $ownable    = true;
    protected string $entityType = 'booking';

    protected $allowedFields = [
        'tenant_id', 'booking_ref', 'trip_id', 'deal_id', 'itinerary_id', 'contact_id', 'owner_id', 'title', 'status', 'travel_start', 'travel_end', 'is_international', 'subtotal', 'gst_amount', 'tcs_amount', 'total_amount', 'paid_amount', 'cost_total', 'supplier_paid', 'cancelled_at', 'cancel_reason', 'notes',
    ];

    protected $validationRules = [
        'title' => 'required|max_length[255]',
    ];
}
