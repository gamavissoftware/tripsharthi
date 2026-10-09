<?php

declare(strict_types=1);

namespace App\Models;

/**
 * A scheduled social post — one row per platform per composed post.
 */
class SocialPostModel extends BaseModel
{
    protected $table      = 'social_posts';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'social_account_id', 'platform', 'message', 'image_url',
        'scheduled_at', 'status', 'platform_post_id', 'error', 'published_at',
    ];
}
