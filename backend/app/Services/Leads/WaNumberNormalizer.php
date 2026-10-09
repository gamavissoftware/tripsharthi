<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * Lightweight WhatsApp number normaliser.
 *
 * Rules (no libphonenumber dependency):
 *  1. Strip spaces, dashes, dots, parentheses.
 *  2. If the result starts with '+': validate E.164 length (7–15 digits after '+'), return as-is.
 *  3. If no '+' and a $defaultCountryCode is supplied: strip leading zeros, prepend country code.
 *  4. If no '+' and no country code: store as-is (user must fix manually).
 *  5. Return '' for anything that cannot be a valid number (empty, too short, non-digit chars).
 */
class WaNumberNormalizer
{
    /**
     * Country codes whose national numbers never begin with 0, but whose users
     * habitually type the domestic trunk 0 ("07503 660006") even when a form
     * has already fixed the country code. Meta then delivers "+9107503660006",
     * which WhatsApp tolerates on delivery but which never matches the same
     * person typed correctly. Italy is deliberately absent: its 0 is real.
     */
    private const TRUNK_ZERO_CC = ['91', '44', '61', '49', '33', '27', '92', '94', '880', '977', '971', '966', '60', '234', '254'];

    private static function dropTrunkZero(string $digits): string
    {
        foreach (self::TRUNK_ZERO_CC as $cc) {
            if (str_starts_with($digits, $cc . '0') && strlen($digits) > strlen($cc) + 8) {
                return $cc . ltrim(substr($digits, strlen($cc)), '0');
            }
        }

        return $digits;
    }

    /**
     * @param  string $raw               Raw input from CSV / form field.
     * @param  string $defaultCountryCode  E.g. '+91'. Applied only when number has no leading '+'.
     * @return string Normalised number, or '' if unparseable.
     */
    public static function normalize(string $raw, string $defaultCountryCode = ''): string
    {
        // Strip whitespace, dashes, dots, parens, colons
        $cleaned = preg_replace('/[\s\-\.\(\)\:]+/', '', $raw);

        if ($cleaned === '' || $cleaned === null) {
            return '';
        }

        // --- Has explicit country-code prefix ---
        if (str_starts_with($cleaned, '+')) {
            $digits = substr($cleaned, 1);
            if (! ctype_digit($digits)) {
                return '';
            }
            $digits = self::dropTrunkZero($digits);
            $len    = strlen($digits);
            if ($len < 7 || $len > 15) {
                return '';
            }
            return '+' . $digits;
        }

        // --- No '+': apply default country code if provided ---
        if ($defaultCountryCode !== '') {
            // Strip leading zeros from local number (e.g. 09123… → 9123…)
            $local       = ltrim($cleaned, '0');
            $countryPart = ltrim($defaultCountryCode, '+');

            if (! ctype_digit($countryPart) || ! ctype_digit($local)) {
                return '';
            }

            $full = $countryPart . $local;
            $len  = strlen($full);
            if ($len < 7 || $len > 15) {
                return '';
            }
            return '+' . $full;
        }

        // --- No '+' and no country code: validate digits-only, store as-is ---
        if (! ctype_digit($cleaned)) {
            return '';
        }
        $len = strlen($cleaned);
        if ($len < 7 || $len > 15) {
            return '';
        }
        return $cleaned;
    }

    /**
     * Returns true when $a and $b refer to the same number (handles +/no-+ mismatch).
     */
    public static function isSame(string $a, string $b): bool
    {
        return $a === $b
            || ltrim($a, '+') === ltrim($b, '+');
    }

    /**
     * Every stored spelling that refers to this number.
     *
     * Numbers reach the DB in both forms — Meta's webhooks deliver bare digits
     * that we prefix with '+', while a CSV column that already carries the
     * country code is stored as-is. A lookup must therefore match both, or an
     * imported lead who replies on WhatsApp is deduped into a second contact
     * and the conversation attaches to the copy (losing tags, status, custom
     * fields and campaign attribution).
     *
     * @return list<string> Unique variants, most canonical first.
     */
    public static function variants(string $number): array
    {
        $number = trim($number);
        if ($number === '') {
            return [];
        }

        $bare = ltrim($number, '+');

        return array_values(array_unique([$number, '+' . $bare, $bare]));
    }
}
