<?php

declare(strict_types=1);

namespace App\Models;

class EmailModel extends BaseModel
{
    protected $table      = 'emails';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'contact_id', 'deal_id', 'email_campaign_id', 'tracking_token', 'direction',
        'from_email', 'to_email', 'subject', 'body', 'status', 'error', 'sent_by',
        'sent_at', 'opened_at', 'open_count', 'clicked_at', 'click_count', 'unsubscribed_at',
        'replied_at', 'message_id', 'is_auto_reply',
    ];

    protected $validationRules = [
        'to_email' => 'required|valid_email',
        'subject'  => 'required|max_length[255]',
    ];

    /** Logged emails for a contact, newest first. */
    public function forContact(int $tenantId, int $contactId, int $limit = 100): array
    {
        return $this->setTenant($tenantId)
            ->where('contact_id', $contactId)
            ->orderBy('id', 'DESC')
            ->findAll($limit);
    }
}
