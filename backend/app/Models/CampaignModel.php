<?php

declare(strict_types=1);

namespace App\Models;

class CampaignModel extends BaseModel
{
    protected $table      = 'campaigns';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'template_id', 'variant_template_id', 'ab_split', 'name', 'segment',
        'variable_mapping', 'variable_defaults',
        'status', 'scheduled_at', 'schedule_timezone',
        'cursor', 'total_contacts', 'sent_count', 'failed_count', 'stats',
    ];

    public static function emptyStats(): array
    {
        return [
            'billable_sends'    => 0,
            'free_sends'        => 0,
            'failed'            => 0,
            'skipped_opt_out'   => 0,
            'missing_variables' => 0,
        ];
    }
}
