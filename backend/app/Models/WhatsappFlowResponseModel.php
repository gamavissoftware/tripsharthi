<?php

declare(strict_types=1);

namespace App\Models;

class WhatsappFlowResponseModel extends BaseModel
{
    protected $table      = 'whatsapp_flow_responses';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id', 'contact_id', 'conversation_id',
        'flow_token', 'response', 'wa_message_id',
    ];
}
