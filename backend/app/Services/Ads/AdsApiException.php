<?php

declare(strict_types=1);

namespace App\Services\Ads;

/** A platform API refused or failed. `kind` tells callers how to react; `getMessage()` is safe to show a user. */
final class AdsApiException extends \RuntimeException
{
    public const AUTH = 'auth';          // token invalid/expired/revoked -> reconnect
    public const PERMISSION = 'permission'; // missing scope / access level / app review
    public const RATE_LIMIT = 'rate_limit';
    public const INVALID = 'invalid';    // the request was rejected (validation / policy)
    public const TRANSIENT = 'transient'; // 5xx / network

    public function __construct(string $message, public readonly string $kind = self::INVALID, public readonly ?string $platformCode = null, public readonly ?array $raw = null)
    {
        parent::__construct($message);
    }
}
