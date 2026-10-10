<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Services\Leads\WaNumberNormalizer;

/**
 * Consent for TripSarthi's OWN WhatsApp marketing (to workspace owners, website leads and partners). WhatsApp policy and the DPDP Act
 * need an explicit yes for marketing, so: the box is never pre-ticked, an opt-in is only valid together with a usable WhatsApp number,
 * and it can be withdrawn at any time (the audience sync then removes the person from marketing).
 * India (+91) is assumed for numbers typed without a country code.
 */
final class PlatformConsent
{
    public const DEFAULT_COUNTRY = '+91';

    /** A WhatsApp-usable number in international form (digits, no +), or null. */
    public static function normalizePhone(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') { return null; }
        // Idempotent: a number we already stored ("919876543210", no +) must come back unchanged. Without a "+", a number longer than a
        // 10-digit Indian one (after dropping the 0 / 00 trunk prefix) already carries its country code; only a short one gets +91.
        if (! str_starts_with($raw, '+')) {
            $digits = ltrim((string) preg_replace('/\D+/', '', $raw), '0');
            if (strlen($digits) >= 11) { $raw = '+' . $digits; }
        }
        $n = WaNumberNormalizer::normalize($raw, self::DEFAULT_COUNTRY);
        return $n === '' ? null : ltrim($n, '+');
    }

    /**
     * The columns to store for a phone + opt-in choice.
     * @return array{phone:?string,wa_marketing_opt_in:int,wa_opt_in_at:?string}
     * @throws \InvalidArgumentException opting in without a usable number, or a number that is not one
     */
    public static function fields(?string $rawPhone, bool $optIn, ?int $now = null): array
    {
        $phone = self::normalizePhone($rawPhone);
        if (trim((string) $rawPhone) !== '' && $phone === null) { throw new \InvalidArgumentException('That WhatsApp number does not look right. Include the country code, like +91 98765 43210.'); }
        if ($optIn && $phone === null) { throw new \InvalidArgumentException('Add your WhatsApp number to receive updates on WhatsApp, or untick the box.'); }
        return ['phone' => $phone, 'wa_marketing_opt_in' => $optIn ? 1 : 0, 'wa_opt_in_at' => $optIn ? date('Y-m-d H:i:s', $now ?? time()) : null];
    }
}
