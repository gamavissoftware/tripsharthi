<?php

declare(strict_types=1);

namespace App\Services\Email\Mailbox;

/**
 * Real mailbox transport over PHP's imap_* extension. Kept deliberately thin —
 * the testable logic lives in ImapPollingService; this only opens a connection,
 * lists UNSEEN messages, and toggles the \Seen flag.
 *
 * Requires ext-imap. Construction throws a clear error if it is missing so the
 * cron command can skip the tenant with a useful log line rather than fatally.
 */
final class ImapMailboxReader implements MailboxReader
{
    /** @var \IMAP\Connection|resource */
    private $stream;

    /**
     * @param array{host:string, port?:int, username:string, password:string, folder?:string, ssl?:bool} $config
     */
    public function __construct(array $config)
    {
        if (! function_exists('imap_open')) {
            throw new \RuntimeException('The PHP imap extension is not installed; cannot poll IMAP mailboxes.');
        }
        $host   = $config['host'] ?? '';
        $port   = (int) ($config['port'] ?? 993);
        $folder = $config['folder'] ?? 'INBOX';
        $flags  = ($config['ssl'] ?? true) ? '/imap/ssl' : '/imap/notls';
        if ($host === '') {
            throw new \InvalidArgumentException('IMAP host is required.');
        }

        $mailbox = '{' . $host . ':' . $port . $flags . '}' . $folder;
        $stream  = @imap_open($mailbox, $config['username'] ?? '', $config['password'] ?? '', 0, 1);
        if ($stream === false) {
            throw new \RuntimeException('IMAP connection failed: ' . imap_last_error());
        }
        $this->stream = $stream;
    }

    public function fetchUnseen(int $limit = 50): array
    {
        $ids = imap_search($this->stream, 'UNSEEN', SE_UID) ?: [];
        $out = [];
        foreach (array_slice($ids, 0, $limit) as $uid) {
            $header = imap_rfc822_parse_headers(imap_fetchheader($this->stream, (int) $uid, FT_UID));
            $from   = isset($header->from[0])
                ? ($header->from[0]->mailbox . '@' . ($header->from[0]->host ?? ''))
                : '';
            $name   = $header->from[0]->personal ?? '';
            $to     = isset($header->to[0]) ? ($header->to[0]->mailbox . '@' . ($header->to[0]->host ?? '')) : '';
            $subject = isset($header->subject) ? imap_utf8($header->subject) : '';
            $body    = imap_fetchbody($this->stream, (int) $uid, '1', FT_UID) ?: imap_body($this->stream, (int) $uid, FT_UID);

            $out[] = [
                'uid'     => (string) $uid,
                'from'    => $name !== '' ? "{$name} <{$from}>" : $from,
                'to'      => $to,
                'subject' => $subject,
                'body'    => (string) $body,
            ];
        }
        return $out;
    }

    public function markSeen(string $uid): void
    {
        imap_setflag_full($this->stream, $uid, '\\Seen', ST_UID);
    }

    public function __destruct()
    {
        if (isset($this->stream) && $this->stream) {
            @imap_close($this->stream);
        }
    }
}
