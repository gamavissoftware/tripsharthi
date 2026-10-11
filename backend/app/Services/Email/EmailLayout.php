<?php

declare(strict_types=1);

namespace App\Services\Email;

/**
 * The one branded e-mail design used for EVERY message TripSarthi sends (invites, reminders, support, password resets, campaigns).
 * Table-based with inline styles so it renders the same in Gmail, Outlook, Apple Mail and phones; single column, 600px, readable
 * on a small screen; a plain-text twin is produced from the same content. No remote images (nothing to block, nothing that hurts
 * deliverability): the logo is a styled wordmark and the brand colour stripe is pure CSS.
 *
 * Content is described as blocks, never raw HTML, and everything is escaped here:
 *   ['p', 'text']                       paragraph (web addresses become links)
 *   ['h2', 'text']                      small headline inside the card
 *   ['button', 'Label', 'https://…']    big call-to-action button (+ a copy-paste fallback link under it)
 *   ['facts', [['Label', 'Value'], …]]  neat key/value card
 *   ['steps', [['Title', 'text'], …]]   numbered steps
 *   ['list', ['item', …]]               bullet list
 *   ['callout', 'text', 'info|warn|ok'] highlighted note
 *   ['divider']
 * Options: subject, preheader (the grey preview line in the inbox), eyebrow (small caps above the title), title, footer_note,
 * unsubscribe_url (marketing only), cta_fallback (show the raw link under buttons, default true).
 */
final class EmailLayout
{
    private const NAVY = '#0a1f44';
    private const BLUE = '#0a6cc4';
    private const TEAL = '#12a89e';
    private const ORANGE = '#f98f26';
    private const INK = '#1f2d46';
    private const MUTED = '#5b6b86';
    private const FONT = "'Inter','Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,Helvetica,Arial,sans-serif";

    public static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** Escape, then turn bare web addresses into links. */
    public static function rich(string $s): string
    {
        $e = self::e($s);
        $e = preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)])~i', '<a href="$1" style="color:' . self::BLUE . ';text-decoration:underline">$1</a>', $e) ?? $e;
        return nl2br($e, false);
    }

    /** @return array{html:string,text:string} */
    public static function build(array $o): array
    {
        $e = [self::class, 'e'];
        $title = (string) ($o['title'] ?? '');
        $subject = (string) ($o['subject'] ?? $title);
        $pre = (string) ($o['preheader'] ?? '');
        $fallback = ($o['cta_fallback'] ?? true) !== false;

        $body = ''; $text = '';
        foreach ((array) ($o['blocks'] ?? []) as $b) {
            $type = (string) ($b[0] ?? '');
            switch ($type) {
                case 'p':
                    $body .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.65;color:' . self::INK . '">' . self::rich((string) $b[1]) . '</p>';
                    $text .= (string) $b[1] . "\n\n"; break;
                case 'h2':
                    $body .= '<h2 style="margin:26px 0 10px;font-size:18px;line-height:1.3;color:' . self::NAVY . ';font-weight:700">' . $e((string) $b[1]) . '</h2>';
                    $text .= strtoupper((string) $b[1]) . "\n"; break;
                case 'button':
                    [$label, $url] = [(string) $b[1], (string) $b[2]];
                    $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 18px"><tr><td align="center" bgcolor="' . self::BLUE . '" style="border-radius:10px;background:' . self::BLUE . '">'
                        . '<a href="' . $e($url) . '" target="_blank" style="display:inline-block;padding:15px 30px;font-family:' . self::FONT . ';font-size:16px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:10px">' . $e($label) . ' &rarr;</a></td></tr></table>';
                    if ($fallback) {
                        $body .= '<p style="margin:0 0 18px;font-size:12.5px;line-height:1.5;color:' . self::MUTED . '">Button not working? Copy this address into your browser:<br><a href="' . $e($url) . '" style="color:' . self::BLUE . ';word-break:break-all">' . $e($url) . '</a></p>';
                    }
                    $text .= $label . ': ' . $url . "\n\n"; break;
                case 'facts':
                    $rows = '';
                    foreach ((array) $b[1] as $r) {
                        $rows .= '<tr><td style="padding:7px 16px 7px 0;font-size:14px;color:' . self::MUTED . ';vertical-align:top;overflow-wrap:normal;word-break:normal">' . $e((string) $r[0]) . '</td>'
                            . '<td style="padding:7px 0;font-size:15px;color:' . self::NAVY . ';font-weight:600;vertical-align:top">' . $e((string) $r[1]) . '</td></tr>';
                        $text .= $r[0] . ': ' . $r[1] . "\n";
                    }
                    $text .= "\n";
                    $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:6px 0 20px"><tr><td style="background:#f3f8fd;border-left:4px solid ' . self::TEAL . ';border-radius:8px;padding:12px 18px">'
                        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table></td></tr></table>';
                    break;
                case 'steps':
                    $n = 0; $rows = '';
                    foreach ((array) $b[1] as $s) {
                        $n++;
                        $rows .= '<tr><td width="38" valign="top" style="padding:0 0 14px"><div style="width:28px;height:28px;line-height:28px;border-radius:14px;background:' . self::TEAL . ';color:#ffffff;font-weight:700;font-size:14px;text-align:center">' . $n . '</div></td>'
                            . '<td valign="top" style="padding:0 0 14px"><div style="font-size:15.5px;font-weight:700;color:' . self::NAVY . ';line-height:1.35">' . $e((string) $s[0]) . '</div>'
                            . '<div style="font-size:14.5px;line-height:1.55;color:' . self::MUTED . '">' . self::rich((string) $s[1]) . '</div></td></tr>';
                        $text .= $n . '. ' . $s[0] . ' - ' . $s[1] . "\n";
                    }
                    $text .= "\n";
                    $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:6px 0 10px">' . $rows . '</table>'; break;
                case 'list':
                    $li = '';
                    foreach ((array) $b[1] as $it) { $li .= '<li style="margin:0 0 7px">' . self::rich((string) $it) . '</li>'; $text .= '- ' . $it . "\n"; }
                    $text .= "\n";
                    $body .= '<ul style="margin:0 0 16px;padding-left:22px;font-size:16px;line-height:1.6;color:' . self::INK . '">' . $li . '</ul>'; break;
                case 'callout':
                    $tone = (string) ($b[2] ?? 'info');
                    [$bg, $bd] = match ($tone) { 'warn' => ['#fff6e8', self::ORANGE], 'ok' => ['#eaf8f4', self::TEAL], default => ['#eef5fd', self::BLUE] };
                    $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:6px 0 18px"><tr><td style="background:' . $bg . ';border-left:4px solid ' . $bd . ';border-radius:8px;padding:13px 18px;font-size:14.5px;line-height:1.6;color:' . self::INK . '">' . self::rich((string) $b[1]) . '</td></tr></table>';
                    $text .= (string) $b[1] . "\n\n"; break;
                case 'divider':
                    $body .= '<hr style="border:none;border-top:1px solid #e3eaf3;margin:22px 0">'; $text .= "----\n\n"; break;
            }
        }

        $eyebrow = (string) ($o['eyebrow'] ?? '');
        $head = ($eyebrow !== '' ? '<div style="margin:0 0 8px;font-size:12px;letter-spacing:1.6px;text-transform:uppercase;font-weight:700;color:' . self::TEAL . '">' . $e($eyebrow) . '</div>' : '')
            . ($title !== '' ? '<h1 class="h1" style="margin:0 0 18px;font-size:27px;line-height:1.25;font-weight:800;color:' . self::NAVY . ';letter-spacing:-.3px">' . $e($title) . '</h1>' : '');

        $unsub = (string) ($o['unsubscribe_url'] ?? '');
        $foot = (string) ($o['footer_note'] ?? '');
        $footHtml = ($foot !== '' ? '<p style="margin:0 0 10px">' . self::rich($foot) . '</p>' : '')
            . ($unsub !== '' ? '<p style="margin:0 0 10px">You are getting this because you chose to hear from TripSarthi. <a href="' . $e($unsub) . '" style="color:#8ea2c0;text-decoration:underline">Unsubscribe</a> in one click, any time.</p>' : '');

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
            . '<title>' . $e($subject) . '</title><style>@media only screen and (max-width:480px){.px{padding-left:20px!important;padding-right:20px!important}.h1{font-size:23px!important}.card{padding-top:26px!important}}.wrap,.wrap *{overflow-wrap:anywhere;word-break:break-word}</style></head>'
            . '<body style="margin:0;padding:0;background:#eef3f9;-webkit-text-size-adjust:100%">'
            . ($pre !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#eef3f9">' . $e($pre) . str_repeat('&nbsp;&zwnj;', 40) . '</div>' : '')
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#eef3f9"><tr><td align="center" style="padding:28px 14px 36px">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px">'
            // brand stripe + header
            . '<tr><td style="height:5px;line-height:5px;font-size:0;background:' . self::BLUE . ';background-image:linear-gradient(90deg,' . self::TEAL . ',' . self::BLUE . ',' . self::ORANGE . ');border-radius:14px 14px 0 0">&nbsp;</td></tr>'
            . '<tr><td class="px" style="background:' . self::NAVY . ';padding:24px 36px 22px;font-family:' . self::FONT . '">'
            . '<span style="font-size:26px;font-weight:800;letter-spacing:-.4px;color:#ffffff">Trip</span><span style="font-size:26px;font-weight:800;letter-spacing:-.4px;color:#2fd0c2">Sarthi</span>'
            . '<div style="margin-top:3px;font-size:12.5px;color:#9fb3d1;letter-spacing:.2px">Your travel business, simplified</div></td></tr>'
            // card
            . '<tr><td class="px card wrap" style="background:#ffffff;padding:34px 36px 30px;font-family:' . self::FONT . ';border-left:1px solid #e3eaf3;border-right:1px solid #e3eaf3">' . $head . $body . '</td></tr>'
            // footer
            . '<tr><td class="px wrap" style="background:#f7fafd;border:1px solid #e3eaf3;border-top:none;border-radius:0 0 14px 14px;padding:22px 36px;font-family:' . self::FONT . ';font-size:12.5px;line-height:1.6;color:#8393ad">'
            . $footHtml
            . '<p style="margin:0">TripSarthi &middot; Gamavis Software Solutions<br><a href="https://tripsarthi.com" style="color:#8393ad">tripsarthi.com</a></p></td></tr>'
            . '</table></td></tr></table></body></html>';

        $plain = ($title !== '' ? $title . "\n" . str_repeat('=', min(60, max(8, mb_strlen($title)))) . "\n\n" : '') . trim($text) . "\n\n--\nTripSarthi - Gamavis Software Solutions\nhttps://tripsarthi.com"
            . ($foot !== '' ? "\n\n" . $foot : '')
            . ($unsub !== '' ? "\n\nYou are getting this because you chose to hear from TripSarthi. Unsubscribe: " . $unsub : '');
        return ['html' => $html, 'text' => $plain];
    }
}
