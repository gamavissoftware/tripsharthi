<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

/** Indian-system amount in words, as GST invoices require ("Rupees Fifty One Thousand ... and Thirty Paise Only"). */
final class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function paise(int $paise): string
    {
        $paise = max(0, $paise);
        $rupees = intdiv($paise, 100);
        $p = $paise % 100;
        $out = 'Rupees ' . ($rupees === 0 ? 'Zero' : self::number($rupees));
        if ($p > 0) { $out .= ' and ' . self::number($p) . ' Paise'; }
        return $out . ' Only';
    }

    private static function number(int $n): string
    {
        $parts = [];
        foreach ([[10_000_000, 'Crore'], [100_000, 'Lakh'], [1000, 'Thousand']] as [$div, $name]) {
            if ($n >= $div) { $parts[] = self::below1000(intdiv($n, $div)) . ' ' . $name; $n %= $div; }
        }
        if ($n > 0) { $parts[] = self::below1000($n); }
        return implode(' ', $parts);
    }

    private static function below1000(int $n): string
    {
        if ($n >= 1000) { return self::number($n); }       // e.g. 1,000+ crore: recurse (crore part can exceed 999)
        $s = '';
        if ($n >= 100) { $s = self::ONES[intdiv($n, 100)] . ' Hundred'; $n %= 100; if ($n > 0) { $s .= ' '; } }
        if ($n >= 20) { $s .= self::TENS[intdiv($n, 10)] . ($n % 10 ? ' ' . self::ONES[$n % 10] : ''); }
        elseif ($n > 0) { $s .= self::ONES[$n]; }
        return $s;
    }
}
