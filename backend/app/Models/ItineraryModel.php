<?php

declare(strict_types=1);

namespace App\Models;

class ItineraryModel extends BaseModel
{
    protected $table      = 'itineraries';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'trip_id', 'parent_id', 'version', 'is_template', 'title', 'subtitle', 'destination_id', 'nights', 'adults', 'children', 'is_international', 'status', 'currency', 'markup_type', 'markup_value', 'discount_amount', 'cost_total', 'sell_subtotal', 'gst_rate', 'gst_amount', 'tcs_rate', 'tcs_amount', 'grand_total', 'margin_amount', 'inclusions', 'exclusions', 'terms', 'cover_image', 'share_token', 'valid_until', 'sent_at', 'viewed_at', 'view_count', 'accepted_at', 'created_by', 'ai_generated', 'display_currency',
    ];

    protected $validationRules = [
        'title' => 'required|max_length[255]',
    ];
}
