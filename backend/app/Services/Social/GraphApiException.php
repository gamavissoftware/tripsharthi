<?php

declare(strict_types=1);

namespace App\Services\Social;

use RuntimeException;

/**
 * A Graph API error payload, with the codes kept intact.
 *
 * Extends RuntimeException so existing catch blocks are unaffected; the added
 * value is that a caller can tell "this token is dead, ask the user to
 * reconnect" apart from "this one post was malformed". Before this, every
 * failure was an identical string and a dead connection looked exactly like a
 * bad image URL.
 *
 * @see https://developers.facebook.com/docs/graph-api/guides/error-handling
 */
class GraphApiException extends RuntimeException
{
    /**
     * Error codes that mean the credential itself is no longer usable, so no
     * amount of retrying will help — only a fresh consent will.
     *
     *   190 — access token expired, revoked, or otherwise invalid
     *   102 — session invalid (user logged out / changed password)
     */
    private const AUTH_ERROR_CODES = [102, 190];

    /**
     * Subcodes carried under code 190, naming why the token died:
     * 458 app de-authorised, 459/464 user checkpointed, 460 password changed,
     * 463 expired, 467 invalidated.
     */
    private const AUTH_ERROR_SUBCODES = [458, 459, 460, 463, 464, 467];

    /**
     * Permission codes: the token is alive but the app was never granted (or
     * has lost) the scope this call needs. Also fixed by reconnecting, because
     * that is when scopes are granted.
     */
    private const PERMISSION_ERROR_CODES = [10, 200, 294, 299];

    public function __construct(
        string $message,
        public readonly int $graphCode = 0,
        public readonly int $graphSubcode = 0,
        public readonly string $graphType = ''
    ) {
        parent::__construct($message);
    }

    /**
     * Does this failure mean "the user must connect the page again"?
     *
     * Deliberately code-driven rather than keyed on type === 'OAuthException':
     * Meta labels ordinary validation failures (code 100, e.g. an unreachable
     * image URL) as OAuthException too, and marking a healthy page as dead
     * because one post had a bad image would be worse than not flagging at all.
     */
    public function requiresReauth(): bool
    {
        return in_array($this->graphCode, self::AUTH_ERROR_CODES, true)
            || in_array($this->graphSubcode, self::AUTH_ERROR_SUBCODES, true)
            || in_array($this->graphCode, self::PERMISSION_ERROR_CODES, true);
    }
}
