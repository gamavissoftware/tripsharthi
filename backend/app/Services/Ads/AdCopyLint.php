<?php

declare(strict_types=1);

namespace App\Services\Ads;

/**
 * Deterministic ad-copy policy checks (pure). AI-written copy passes through the SAME lint as human copy —
 * the model is never trusted to follow policy. Errors block creation; warnings are shown to the reviewer.
 *
 * Covers what travel ads in India get rejected or flagged for: absolute/unverifiable claims, price-guarantee
 * wording, shouting, and platform character rules (Google headlines forbid "!" and cap at 30 chars).
 */
final class AdCopyLint
{
    private const ABSOLUTE = ['guaranteed', 'guarantee', '100%', 'no.1', '#1', 'number one', 'best price', 'lowest price', 'cheapest', 'risk-free', 'risk free', 'free trip'];

    /** @return array{errors:list<string>,warnings:list<string>} */
    public static function check(string $text, string $field = 'text', string $platform = 'meta', ?int $maxLen = null): array
    {
        $errors = []; $warnings = [];
        $t = trim($text);
        $lower = mb_strtolower($t);

        if ($maxLen !== null && mb_strlen($t) > $maxLen) { $errors[] = "{$field} is " . mb_strlen($t) . " characters (max {$maxLen})."; }
        if (preg_match('/[!]{2,}|[?]{2,}/', $t)) { $errors[] = "{$field}: avoid repeated punctuation (!!, ??)."; }
        if ($platform === 'google' && str_contains($t, '!') && str_starts_with($field, 'headline')) { $errors[] = "{$field}: Google does not allow “!” in headlines."; }
        foreach (self::ABSOLUTE as $w) {
            if (str_contains($lower, $w)) { $warnings[] = "{$field}: “{$w}” is an unverifiable claim and often gets ads rejected."; }
        }
        // Shouting: a long run of capitals (allow short acronyms like GST, UAE, 5N/6D).
        if (preg_match('/\b[A-Z]{6,}\b/', $t)) { $errors[] = "{$field}: avoid ALL-CAPS words."; }
        if (preg_match('/\p{So}{4,}/u', $t)) { $warnings[] = "{$field}: too many emojis can reduce approval and trust."; }
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** Lint many fields; keys are field labels. */
    public static function checkAll(array $fields, string $platform, array $limits = []): array
    {
        $errors = []; $warnings = [];
        foreach ($fields as $label => $text) {
            $r = self::check((string) $text, (string) $label, $platform, $limits[$label] ?? null);
            $errors = array_merge($errors, $r['errors']);
            $warnings = array_merge($warnings, $r['warnings']);
        }
        return ['errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
    }
}
