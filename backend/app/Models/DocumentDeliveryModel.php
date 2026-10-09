<?php

declare(strict_types=1);

namespace App\Models;

class DocumentDeliveryModel extends BaseModel
{
    protected $table      = 'document_deliveries';
    protected $primaryKey = 'id';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $allowedFields = ['tenant_id', 'doc_kind', 'doc_id', 'doc_label', 'booking_id', 'contact_id', 'channel', 'mode', 'status', 'message_id', 'error', 'sent_by', 'created_at'];
}
