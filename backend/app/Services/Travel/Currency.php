<?php

declare(strict_types=1);

namespace App\Services\Travel;

/**
 * Pure currency maths. Foreign amounts are integer MINOR units (cents, fils, yen…), INR amounts are integer PAISE, and a rate is
 * INR per ONE major unit. Minor-unit sizes differ (JPY has none, KWD has three), so every conversion goes through exp().
 */
final class Currency
{
    /** code => [name, symbol, minor-unit exponent] — the currencies Indian travel agencies actually pay suppliers in. */
    public const LIST = [
        'INR' => ['Indian Rupee', '₹', 2], 'USD' => ['US Dollar', '$', 2], 'EUR' => ['Euro', '€', 2], 'GBP' => ['Pound Sterling', '£', 2], 'AED' => ['UAE Dirham', 'AED ', 2],
        'SGD' => ['Singapore Dollar', 'S$', 2], 'THB' => ['Thai Baht', '฿', 2], 'MYR' => ['Malaysian Ringgit', 'RM', 2], 'IDR' => ['Indonesian Rupiah', 'Rp', 2], 'JPY' => ['Japanese Yen', '¥', 0],
        'AUD' => ['Australian Dollar', 'A$', 2], 'NZD' => ['New Zealand Dollar', 'NZ$', 2], 'CAD' => ['Canadian Dollar', 'C$', 2], 'CHF' => ['Swiss Franc', 'CHF ', 2], 'HKD' => ['Hong Kong Dollar', 'HK$', 2],
        'CNY' => ['Chinese Yuan', 'CN¥', 2], 'KRW' => ['South Korean Won', '₩', 0], 'VND' => ['Vietnamese Dong', '₫', 0], 'LKR' => ['Sri Lankan Rupee', 'Rs ', 2], 'NPR' => ['Nepalese Rupee', 'NRs ', 2],
        'BDT' => ['Bangladeshi Taka', '৳', 2], 'MVR' => ['Maldivian Rufiyaa', 'MVR ', 2], 'MUR' => ['Mauritian Rupee', 'MUR ', 2], 'SAR' => ['Saudi Riyal', 'SAR ', 2], 'QAR' => ['Qatari Riyal', 'QAR ', 2],
        'OMR' => ['Omani Rial', 'OMR ', 3], 'KWD' => ['Kuwaiti Dinar', 'KWD ', 3], 'BHD' => ['Bahraini Dinar', 'BHD ', 3], 'TRY' => ['Turkish Lira', '₺', 2], 'EGP' => ['Egyptian Pound', 'E£', 2],
        'ZAR' => ['South African Rand', 'R', 2], 'KES' => ['Kenyan Shilling', 'KSh ', 2], 'BTN' => ['Bhutanese Ngultrum', 'Nu ', 2],
    ];

    public static function valid(string $c): bool { return isset(self::LIST[$c]); }
    public static function exp(string $c): int { return self::LIST[$c][2] ?? 2; }
    public static function name(string $c): string { return self::LIST[$c][0] ?? $c; }

    /** Whole currency units -> minor units ("1250.50" USD -> 125050). */
    public static function toMinor(float $major, string $c): int { return (int) round($major * 10 ** self::exp($c)); }

    public static function toMajor(int $minor, string $c): float { return $minor / 10 ** self::exp($c); }

    /**
     * A foreign COST in INR paise. The buffer protects the agency: it is added on top of the market rate because costs are paid later.
     * paise = major x rate x (1 + buffer%) x 100
     */
    public static function toInrPaise(int $minor, string $c, float $rate, float $bufferPct = 0.0): int
    {
        if ($c === 'INR') { return $minor; }
        return (int) round(self::toMajor($minor, $c) * $rate * (1 + $bufferPct / 100) * 100);
    }

    /** An INR amount expressed in a foreign currency (minor units) at the plain market rate — for indicative display. */
    public static function fromInrPaise(int $paise, string $c, float $rate): int
    {
        if ($c === 'INR') { return $paise; }
        if ($rate <= 0) { throw new \InvalidArgumentException('Exchange rate must be positive.'); }
        return (int) round(($paise / 100) / $rate * 10 ** self::exp($c));
    }

    /** Realised INR-per-unit from what was actually debited and what was paid abroad. */
    public static function realisedRate(int $inrPaise, int $fxMinor, string $c): float
    {
        return $fxMinor > 0 ? round(($inrPaise / 100) / self::toMajor($fxMinor, $c), 8) : 0.0;
    }

    /** "$1,250.00", "AED 1,200.00", "¥125,000". */
    public static function format(int $minor, string $c): string
    {
        $e = self::exp($c);
        return (self::LIST[$c][1] ?? $c . ' ') . number_format(self::toMajor($minor, $c), $e, '.', ',');
    }

    /** Safety net on a typed or fetched rate: strictly positive, and not an order-of-magnitude typo (e.g. 8.35 instead of 83.5). */
    public static function plausibleChange(float $old, float $new, float $maxPct = 25.0): bool
    {
        return $old <= 0 || abs($new - $old) / $old * 100 <= $maxPct;
    }
}
