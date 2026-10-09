<?php

declare(strict_types=1);

namespace App\Services\Email\Mailbox;

/**
 * Transport abstraction over a mailbox so the polling logic is testable without
 * a live IMAP server. The real adapter (ImapMailboxReader) wraps PHP's imap_*
 * functions; tests use a fake that returns canned messages.
 */
interface MailboxReader
{
    /**
     * Fetch unread messages (newest-first is fine; order is not relied upon).
     *
     * @return list<array{uid:string, from:string, to:string, subject:string, body:string}>
     */
    public function fetchUnseen(int $limit = 50): array;

    /** Mark a message processed so a later poll won't return it again. */
    public function markSeen(string $uid): void;
}
