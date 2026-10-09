<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when a free-form (non-template) outbound send is attempted
 * outside a contact's open 24-hour customer service window.
 *
 * Callers MUST catch this and:
 *   1. Return a 422 JSON error to the agent/flow.
 *   2. Log the blocked attempt via MessageModel::logBlocked().
 *
 * Never swallow this silently — every block must be auditable.
 */
class WindowClosedException extends \RuntimeException
{
    public function __construct(
        string $message = 'The 24-hour customer service window is closed. Only approved template messages may be sent.',
        private readonly int $conversationId = 0,
        private readonly ?string $windowExpiresAt = null,
    ) {
        parent::__construct($message);
    }

    public function getConversationId(): int
    {
        return $this->conversationId;
    }

    public function getWindowExpiresAt(): ?string
    {
        return $this->windowExpiresAt;
    }
}
