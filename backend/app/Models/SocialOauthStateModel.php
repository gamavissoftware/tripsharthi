<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\WhatsApp\TokenCipher;

/**
 * One in-flight "Connect with Facebook" attempt.
 *
 * The row is the only thing linking Meta's callback — a bare browser redirect
 * with no Authorization header — back to the user who started the flow, so it
 * doubles as the CSRF token. Single use, short lived, and the user token it
 * briefly holds is wiped the moment a page is chosen.
 */
class SocialOauthStateModel extends BaseModel
{
    protected $table      = 'social_oauth_states';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'user_id', 'state', 'user_token_enc', 'pages_json',
        'status', 'return_to', 'error', 'expires_at',
    ];

    public function getDecryptedUserToken(array $row): string
    {
        $enc = (string) ($row['user_token_enc'] ?? '');

        return $enc === '' ? '' : TokenCipher::decrypt($enc);
    }
}
