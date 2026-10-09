<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * Recognises an inbound message as a request to stop receiving marketing.
 *
 * Marketing templates carry "Reply STOP to opt out" in the footer because
 * WhatsApp expects an easy exit. If that instruction does nothing, the reply
 * people reach for instead is the Report button — and reports drive the number's
 * quality rating down and eventually get it restricted. Honouring STOP is not
 * politeness, it is what keeps the sending number alive.
 *
 * Matching is EXACT on the normalised message, never a substring: a lead who
 * writes "stop by the factory next week" or "we need to stop using Tally" is
 * asking for a conversation, not an unsubscribe.
 */
final class OptOutDetector
{
    /**
     * Normalised forms that mean "stop messaging me".
     *
     * "stop promotions" is what WhatsApp's own built-in block button sends.
     */
    private const PHRASES = [
        'stop',
        'stop promotions',
        'stop all',
        'unsubscribe',
        'opt out',
        'optout',
        'remove me',
        'no more messages',
        'do not message me',
        'dont message me',
        // Hinglish, common in Indian B2B replies
        'band karo',
        'band kro',
        'mat bhejo',
    ];

    public function isOptOut(string $message): bool
    {
        return in_array($this->normalise($message), self::PHRASES, true);
    }

    /**
     * Lowercase, strip surrounding punctuation and emoji-ish noise, and collapse
     * inner whitespace, so "STOP." / "  stop  " / "Stop!" all match.
     */
    private function normalise(string $message): string
    {
        $text = mb_strtolower(trim($message));

        // Drop leading/trailing punctuation and symbols, keeping inner spaces.
        $text = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $text) ?? '';

        // Collapse runs of whitespace and normalise hyphens to spaces
        // ("opt-out" and "opt   out" both become "opt out").
        $text = str_replace(['-', '_'], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }
}
