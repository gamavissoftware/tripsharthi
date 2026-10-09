<?php

declare(strict_types=1);

namespace App\Models;

class EmailCampaignModel extends BaseModel
{
    protected $table      = 'email_campaigns';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'email_template_id', 'name', 'subject', 'preheader', 'from_name', 'reply_to',
        'html_body', 'segment', 'status', 'scheduled_at', 'schedule_timezone',
        'cursor', 'total_contacts', 'sent_count', 'failed_count', 'stats', 'last_error',
        'started_at', 'completed_at', 'created_by',
    ];

    /** Statuses a campaign can still be edited in. */
    public const EDITABLE = ['draft', 'scheduled'];

    public static function emptyStats(): array
    {
        return [
            'skipped_no_email'   => 0,
            'skipped_suppressed' => 0,
            'skipped_duplicate'  => 0,
        ];
    }
}
