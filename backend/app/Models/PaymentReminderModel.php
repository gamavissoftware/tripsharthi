<?php

declare(strict_types=1);

namespace App\Models;

class PaymentReminderModel extends BaseModel
{
    protected $table      = 'payment_reminders';
    protected $primaryKey = 'id';
    protected $useSoftDeletes = false;
    protected $allowedFields = ['tenant_id', 'booking_payment_id', 'booking_id', 'step', 'channel', 'status', 'reason', 'message_id', 'attempts', 'sent_at'];
}
