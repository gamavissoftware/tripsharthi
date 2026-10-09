<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Makes model output safe to send as a WhatsApp message.
 *
 * WhatsApp is not Markdown. It renders *bold*, _italic_, ~strike~ and ```mono```
 * — single delimiters — and nothing else. A model writing normal Markdown sends
 * a customer "**What do you manufacture?**" with the asterisks visible, which
 * reads as broken software at exactly the moment you are trying to look
 * credible. Headings, bullets and [links](url) have the same problem.
 *
 * Two layers, because neither alone is enough:
 *   - promptRule() tells the model the target format. Cheap, usually works.
 *   - normalise() repairs the output anyway. Models drift back to Markdown,
 *     particularly on long replies, and a prompt is a request rather than a
 *     guarantee.
 */
final class WhatsAppText
{
    /**
     * Appended to every system prompt whose output is sent over WhatsApp.
     */
    public static function promptRule(): string
    {
        return "Formatting: this message is sent on WhatsApp, which is NOT Markdown. "
            . "Use *single asterisks* for bold and _single underscores_ for italic. "
            . "Never use **double asterisks**, # headings, or [text](links) — they appear "
            . "literally to the customer. Write a URL on its own rather than linking it.";
    }

    /**
     * Repair Markdown that slipped through into WhatsApp's own syntax.
     */
    public static function normalise(string $text): string
    {
        // **bold** -> *bold*  (before any single-asterisk handling)
        $text = preg_replace('/\*\*(?!\s)(.+?)(?<!\s)\*\*/su', '*$1*', $text) ?? $text;

        // __bold__ -> _italic_ (WhatsApp has no second bold form)
        $text = preg_replace('/__(?!\s)(.+?)(?<!\s)__/su', '_$1_', $text) ?? $text;

        // [label](https://url) -> label: https://url, or the bare URL when the
        // label adds nothing.
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((\S+?)\)/u',
            static fn (array $m): string => trim($m[1]) === trim($m[2]) ? $m[2] : $m[1] . ': ' . $m[2],
            $text
        ) ?? $text;

        // Headings: drop the hashes, keep the words bold — that is what the
        // model meant by them.
        $text = preg_replace('/^\s{0,3}#{1,6}\s+(.+?)\s*$/mu', '*$1*', $text) ?? $text;

        // Markdown bullets -> a real bullet character. Requires the trailing
        // space, so *bold* at the start of a line is left alone.
        $text = preg_replace('/^(\s*)[-*]\s+/mu', '$1• ', $text) ?? $text;

        return trim($text);
    }
}
