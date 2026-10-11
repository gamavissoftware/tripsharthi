<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Services\Email\EmailLayout;

/**
 * Turns the plain text a team member writes in Admin -> Email marketing into a branded, safe HTML e-mail (via EmailLayout) + a text twin.
 * Nothing the author types can inject markup (everything is escaped by the layout). Simple writing rules:
 *   blank line            = new paragraph            web addresses become links
 *   # Heading             = the e-mail's big title (first line) or a small headline
 *   - item (every line)   = bullet list              > text   = highlighted note
 *   [[button: Label | https://link]]  = a call-to-action button
 *   {{first_name}}        = the recipient's first name ("there" when unknown)
 * The footer carries the reason, the sender and the one-click unsubscribe link.
 */
final class EmailBody
{
    public static function firstName(?string $name): string
    {
        $n = trim((string) $name);
        $f = $n === '' ? '' : (preg_split('/\s+/u', $n)[0] ?? '');
        return mb_strlen($f) >= 2 && mb_strlen($f) <= 30 ? $f : 'there';
    }

    public static function personalise(string $text, string $firstName): string { return str_replace('{{first_name}}', $firstName, $text); }

    /** @return array{html:string,text:string} */
    public static function render(string $body, string $firstName, string $unsubscribeUrl, string $subject = ''): array
    {
        $plain = self::personalise(str_replace(["\r\n", "\r"], "\n", $body), $firstName);
        $paras = array_values(array_filter(array_map('trim', preg_split("/\n{2,}/", trim($plain)) ?: []), static fn ($p) => $p !== ''));
        $title = ''; $blocks = [];
        foreach ($paras as $i => $p) {
            if (preg_match('/^#\s+(.+)$/s', $p, $m)) {
                if ($i === 0 && $title === '') { $title = trim($m[1]); } else { $blocks[] = ['h2', trim($m[1])]; }
                continue;
            }
            if (preg_match('/^\[\[button:\s*(.+?)\s*\|\s*(https?:\/\/[^\]\s]+)\s*\]\]$/i', $p, $m)) { $blocks[] = ['button', $m[1], $m[2]]; continue; }
            $lines = array_map('trim', explode("\n", $p));
            if (count(array_filter($lines, static fn ($l) => str_starts_with($l, '- '))) === count($lines)) { $blocks[] = ['list', array_map(static fn ($l) => trim(substr($l, 2)), $lines)]; continue; }
            if (str_starts_with($p, '> ')) { $blocks[] = ['callout', trim(preg_replace('/^>\s?/m', '', $p) ?? ''), 'info']; continue; }
            $blocks[] = ['p', $p];
        }
        $first = '';
        foreach ($blocks as $b) { if ($b[0] === 'p') { $first = (string) $b[1]; break; } }
        return EmailLayout::build(['subject' => $subject !== '' ? $subject : $title, 'title' => $title, 'preheader' => mb_substr(preg_replace('/\s+/', ' ', $first) ?? '', 0, 110), 'blocks' => $blocks, 'unsubscribe_url' => $unsubscribeUrl]);
    }
}
