<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Services\Email\Mailbox\MailboxReader;

/**
 * Inbound-email polling (Phase M) — the fallback for tenants without an
 * inbound-parse webhook provider. Pulls unread messages from a mailbox and feeds
 * each through InboundEmailService (match/auto-create contact + thread + flows).
 *
 * The mailbox is injected as a MailboxReader so this logic is fully unit-testable
 * without a live IMAP server; the real transport is ImapMailboxReader.
 *
 * Idempotency: a message is marked seen only AFTER it is ingested, so a crash
 * mid-poll re-delivers it next run (at-least-once) rather than losing it.
 */
final class ImapPollingService
{
    public function __construct(private readonly InboundEmailService $inbound = new InboundEmailService()) {}

    /**
     * @return array{fetched:int, ingested:int, created:int}
     */
    public function poll(int $tenantId, MailboxReader $reader, int $limit = 50): array
    {
        $messages = $reader->fetchUnseen($limit);
        $ingested = 0;
        $created  = 0;

        foreach ($messages as $m) {
            try {
                $res = $this->inbound->ingest(
                    $tenantId,
                    $m['from'] ?? '',
                    $m['to'] ?? '',
                    $m['subject'] ?? '',
                    $m['body'] ?? ''
                );
                if (! empty($res['matched'])) {
                    $ingested++;
                    if (! empty($res['created'])) {
                        $created++;
                    }
                }
                // Mark seen only on success → a thrown message is retried next poll.
                $reader->markSeen((string) ($m['uid'] ?? ''));
            } catch (\Throwable $e) {
                log_message('error', "ImapPolling: failed to ingest message {$m['uid']} (tenant {$tenantId}): {$e->getMessage()}");
                // leave unseen for the next run
            }
        }

        return ['fetched' => count($messages), 'ingested' => $ingested, 'created' => $created];
    }
}
