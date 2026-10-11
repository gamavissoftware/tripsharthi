<?php

declare(strict_types=1);

namespace App\Services\Marketing;

/**
 * Turns the plain text a team member writes into a safe, simple HTML e-mail + a text alternative.
 * Everything is escaped (nothing the author types can inject markup); blank lines make paragraphs, single line breaks stay, web addresses become links.
 * {{first_name}} is replaced per recipient ("there" when unknown). The footer carries the reason, the sender and the unsubscribe link.
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
    public static function render(string $body, string $firstName, string $unsubscribeUrl): array
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $plain = self::personalise(str_replace(["\r\n", "\r"], "\n", $body), $firstName);
        $paras = preg_split("/\n{2,}/", trim($plain)) ?: [];
        $html = '';
        foreach ($paras as $p) {
            $e = $h(trim($p));
            $e = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" style="color:#0a6cc4">$1</a>', $e) ?? $e;
            $html .= '<p style="margin:0 0 14px">' . nl2br($e, false) . '</p>';
        }
        $page = '<!doctype html><html><body style="margin:0;background:#f3f7fc"><div style="max-width:600px;margin:0 auto;padding:24px 16px;font-family:Arial,sans-serif;font-size:15px;line-height:1.55;color:#0f1f3a">'
            . '<div style="font-weight:800;font-size:20px;color:#0a1f44;margin-bottom:14px">Trip<span style="color:#12a89e">Sarthi</span></div>'
            . '<div style="background:#fff;border-radius:12px;padding:22px 24px">' . $html . '</div>'
            . '<p style="color:#667;font-size:12.5px;margin:16px 4px 0">You are getting this because you chose to hear from TripSarthi (Gamavis Software Solutions). '
            . '<a href="' . $h($unsubscribeUrl) . '" style="color:#667">Unsubscribe</a> any time with one click.</p></div></body></html>';
        $text = trim($plain) . "\n\n--\nYou are getting this because you chose to hear from TripSarthi (Gamavis Software Solutions).\nUnsubscribe: " . $unsubscribeUrl;
        return ['html' => $page, 'text' => $text];
    }
}
