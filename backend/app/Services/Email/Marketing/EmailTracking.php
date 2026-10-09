<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

use App\Services\Email\EmailService;

/**
 * Turns a personalised campaign body into the tracked HTML that is sent.
 *
 *  - every http(s) link is routed through a signed click redirect
 *  - a 1×1 open pixel is appended
 *  - {{unsubscribe_url}} is filled, and a footer with an unsubscribe link is
 *    added when the author did not place one themselves (every bulk email must
 *    carry one — Gmail and Yahoo reject bulk mail without it)
 *  - {{footer_text}} is filled with the tenant's company/address footer
 *  - the preheader becomes the hidden inbox-preview line
 *
 * Public URLs live under /webhooks/ and /forms/ because production nginx only
 * hands those prefixes (plus /api/, /meet, /embed/) to PHP.
 *
 * The click redirect is signed (HMAC over token + url) so the endpoint cannot
 * be used as an open redirect by anyone who learns a token.
 */
final class EmailTracking
{
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function baseUrl(): string
    {
        $override = trim((string) env('EMAIL_TRACKING_URL', ''));

        return $override !== '' ? rtrim($override, '/') : EmailService::appUrl();
    }

    public static function openUrl(string $token): string
    {
        return self::baseUrl() . '/webhooks/email/open/' . $token . '.gif';
    }

    public static function clickUrl(string $token, string $url): string
    {
        return self::baseUrl() . '/webhooks/email/click/' . $token
            . '?u=' . rawurlencode($url) . '&s=' . self::sign($token, $url);
    }

    public static function unsubscribeUrl(string $token): string
    {
        return self::baseUrl() . '/forms/unsubscribe/' . $token;
    }

    public static function sign(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', $token . '|' . $url, self::key()), 0, 20);
    }

    public static function verify(string $token, string $url, string $sig): bool
    {
        return $sig !== '' && hash_equals(self::sign($token, $url), $sig);
    }

    /** RFC 2369 + RFC 8058 one-click unsubscribe headers. */
    public static function listUnsubscribeHeaders(string $token): array
    {
        return [
            'List-Unsubscribe'      => '<' . self::unsubscribeUrl($token) . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /**
     * @param string|null $token null renders an untracked preview/test (links left
     *                           alone, no pixel, unsubscribe link inert)
     */
    public static function prepare(string $html, ?string $token, string $preheader = '', string $footerText = ''): string
    {
        $unsubUrl = $token !== null ? self::unsubscribeUrl($token) : '#unsubscribe';
        $hasUnsub = str_contains($html, '{{unsubscribe_url}}');
        // A designed footer places the sender's company/address itself.
        $html = str_replace('{{footer_text}}', nl2br(htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8'), false), $html);
        $html     = str_replace('{{unsubscribe_url}}', htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8'), $html);

        if ($token !== null) {
            $html = self::rewriteLinks($html, $token, $unsubUrl);
        }

        if (! $hasUnsub) {
            $footer = '<div style="font-family:Arial,sans-serif;font-size:12px;color:#8a8f98;text-align:center;padding:24px 16px;">'
                . ($footerText !== '' ? htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8') . '<br>' : '')
                . 'Don\'t want these emails? <a href="' . htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8')
                . '" style="color:#8a8f98;">Unsubscribe</a></div>';
            $html = self::beforeBodyEnd($html, $footer);
        }

        if ($token !== null) {
            $pixel = '<img src="' . htmlspecialchars(self::openUrl($token), ENT_QUOTES, 'UTF-8')
                . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">';
            $html = self::beforeBodyEnd($html, $pixel);
        }

        if (trim($preheader) !== '') {
            $pre = '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'
                . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . '</div>';
            $html = preg_match('/<body\b[^>]*>/i', $html)
                ? (string) preg_replace('/(<body\b[^>]*>)/i', '$1' . str_replace('$', '\$', $pre), $html, 1)
                : $pre . $html;
        }

        return $html;
    }

    /** Plain-text alternative, for clients (and spam filters) that want one. */
    public static function toText(string $html): string
    {
        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = (string) preg_replace('/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', '$3 ($2)', $html);
        $html = (string) preg_replace('/<(br|\/p|\/div|\/h[1-6]|\/li|\/tr)\b[^>]*>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+/", ' ', $text);

        return trim((string) preg_replace("/\n\s*\n+/", "\n\n", $text));
    }

    private static function rewriteLinks(string $html, string $token, string $unsubUrl): string
    {
        return (string) preg_replace_callback(
            '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is',
            static function (array $m) use ($token, $unsubUrl): string {
                $url = html_entity_decode(trim($m[3]), ENT_QUOTES, 'UTF-8');
                if (! preg_match('#^https?://#i', $url) || $url === $unsubUrl) {
                    return $m[0];
                }

                return $m[1] . $m[2] . htmlspecialchars(self::clickUrl($token, $url), ENT_QUOTES, 'UTF-8') . $m[2];
            },
            $html,
        );
    }

    private static function beforeBodyEnd(string $html, string $insert): string
    {
        $pos = strripos($html, '</body>');

        return $pos === false ? $html . $insert : substr($html, 0, $pos) . $insert . substr($html, $pos);
    }

    private static function key(): string
    {
        $key = (string) (env('encryption.key') ?: env('EMAIL_TRACKING_SECRET') ?: '');

        return $key !== '' ? $key : 'travelpilot-email-tracking';
    }
}
