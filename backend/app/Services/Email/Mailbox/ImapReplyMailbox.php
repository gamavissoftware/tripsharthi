<?php

declare(strict_types=1);

namespace App\Services\Email\Mailbox;

/**
 * ReplyMailbox over PHP's imap extension. The mailbox is opened READ-ONLY
 * (OP_READONLY) and bodies are fetched with FT_PEEK, so nothing is ever
 * marked read, moved or deleted.
 */
final class ImapReplyMailbox implements ReplyMailbox
{
    /** @var \IMAP\Connection|resource */
    private $stream;

    /** @param array{host:string, port?:int, username:string, password:string, folder?:string, ssl?:bool} $config */
    public function __construct(array $config)
    {
        if (! function_exists('imap_open')) {
            throw new \RuntimeException('The PHP imap extension is not installed (apt-get install php8.3-imap).');
        }
        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '' || str_ends_with($host, 'example.com')) {
            throw new \InvalidArgumentException('No real IMAP mailbox is configured.');
        }
        $flags   = ($config['ssl'] ?? true) ? '/imap/ssl' : '/imap/notls';
        $mailbox = '{' . $host . ':' . (int) ($config['port'] ?? 993) . $flags . '}' . ($config['folder'] ?? 'INBOX');

        $stream = @imap_open($mailbox, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), OP_READONLY, 1);
        if ($stream === false) {
            $err = imap_last_error() ?: 'unknown error';
            imap_errors(); // clear the queue so PHP does not emit notices at shutdown
            throw new \RuntimeException('IMAP login failed: ' . $err);
        }
        $this->stream = $stream;
    }

    public function listSince(int $sinceTs, int $limit = 500): array
    {
        $uids = imap_search($this->stream, 'SINCE "' . gmdate('j-M-Y', $sinceTs) . '"', SE_UID) ?: [];
        $uids = array_slice($uids, -$limit);
        if ($uids === []) {
            return [];
        }

        $out = [];
        foreach (imap_fetch_overview($this->stream, implode(',', $uids), FT_UID) ?: [] as $o) {
            $from = imap_rfc822_parse_adrlist((string) ($o->from ?? ''), 'invalid');
            $addr = isset($from[0]->mailbox, $from[0]->host) ? strtolower($from[0]->mailbox . '@' . $from[0]->host) : '';
            $out[] = [
                'uid'        => (string) $o->uid,
                'message_id' => trim((string) ($o->message_id ?? '')),
                'from'       => $addr,
                'from_name'  => isset($from[0]->personal) ? self::decode((string) $from[0]->personal) : '',
                'subject'    => self::decode((string) ($o->subject ?? '')),
                'date_ts'    => (int) (strtotime((string) ($o->date ?? '')) ?: time()),
            ];
        }

        return $out;
    }

    public function read(string $uid): array
    {
        $headers = (string) imap_fetchheader($this->stream, (int) $uid, FT_UID);
        $auto    = (bool) preg_match('/^(Auto-Submitted:\s*(?!no\b)\S+|X-Autoreply:|X-Autorespond:|Precedence:\s*(auto_reply|bulk|junk))/im', $headers);

        $structure = imap_fetchstructure($this->stream, (int) $uid, FT_UID);
        $text      = $structure ? $this->findPart($uid, $structure, '', 'PLAIN') : null;
        if ($text === null && $structure) {
            $html = $this->findPart($uid, $structure, '', 'HTML');
            $text = $html !== null ? html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", $html)), ENT_QUOTES, 'UTF-8') : '';
        }

        return ['text' => trim((string) $text), 'auto' => $auto];
    }

    /** Depth-first search for the first text/<subtype> part, decoded to UTF-8. */
    private function findPart(string $uid, object $part, string $number, string $subtype): ?string
    {
        if (($part->type ?? 0) === TYPETEXT && strtoupper((string) ($part->subtype ?? '')) === $subtype) {
            $raw = (string) imap_fetchbody($this->stream, (int) $uid, $number === '' ? '1' : $number, FT_UID | FT_PEEK);
            if ($number === '' && empty($part->parts)) {
                $raw = (string) imap_body($this->stream, (int) $uid, FT_UID | FT_PEEK);
            }
            $raw = match ((int) ($part->encoding ?? 0)) {
                ENCBASE64          => (string) base64_decode($raw),
                ENCQUOTEDPRINTABLE => quoted_printable_decode($raw),
                default            => $raw,
            };
            $charset = 'UTF-8';
            foreach (array_merge($part->parameters ?? [], $part->dparameters ?? []) as $p) {
                if (strtolower((string) $p->attribute) === 'charset') {
                    $charset = (string) $p->value;
                }
            }

            return strtoupper($charset) === 'UTF-8' ? $raw : (string) @mb_convert_encoding($raw, 'UTF-8', $charset);
        }

        foreach ($part->parts ?? [] as $i => $child) {
            $found = $this->findPart($uid, $child, ($number === '' ? '' : $number . '.') . ($i + 1), $subtype);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function decode(string $mime): string
    {
        $out = '';
        foreach (imap_mime_header_decode($mime) ?: [] as $el) {
            $cs   = strtolower((string) $el->charset);
            $out .= in_array($cs, ['default', 'utf-8', 'us-ascii'], true) ? $el->text : (string) @mb_convert_encoding($el->text, 'UTF-8', $el->charset);
        }

        return $out;
    }

    public function __destruct()
    {
        if (isset($this->stream) && $this->stream) {
            @imap_close($this->stream);
            imap_errors();
        }
    }
}
