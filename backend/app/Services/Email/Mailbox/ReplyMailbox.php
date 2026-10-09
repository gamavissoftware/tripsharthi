<?php

declare(strict_types=1);

namespace App\Services\Email\Mailbox;

/**
 * Read-only view of a mailbox for reply tracking. Unlike MailboxReader it
 * never changes the mailbox (no \Seen flag): it is pointed at a person's own
 * inbox, which must look exactly the same after TravelPilot has read it.
 *
 * Bodies are fetched lazily — only for messages whose sender turns out to be
 * a campaign recipient — so scanning a busy inbox stays cheap.
 */
interface ReplyMailbox
{
    /**
     * Envelope data for messages that arrived on or after $sinceTs.
     *
     * @return list<array{uid:string, message_id:string, from:string, from_name:string, subject:string, date_ts:int}>
     */
    public function listSince(int $sinceTs, int $limit = 500): array;

    /**
     * Full detail for one message: plain-text body and whether it declares
     * itself automatic (Auto-Submitted, X-Autoreply, Precedence: auto_reply…).
     *
     * @return array{text:string, auto:bool}
     */
    public function read(string $uid): array;
}
