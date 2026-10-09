<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

/** Display formatting shared by every PDF. Money arrives as integer paise. */
final class DocFormat
{
    /** Indian digit grouping: 1234567.8 -> "12,34,567.80". */
    public static function num(int $paise, int $decimals = 2): string
    {
        $neg = $paise < 0;
        $paise = abs($paise);
        $rupees = (string) intdiv($paise, 100);
        $last3 = substr($rupees, -3);
        $rest  = substr($rupees, 0, -3);
        $grouped = $rest === '' ? $last3 : preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last3;
        $out = $grouped . ($decimals > 0 ? '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT) : '');
        return ($neg ? '-' : '') . $out;
    }

    public static function inr(int $paise, int $decimals = 2): string
    {
        return '₹' . self::num($paise, $decimals);
    }

    public static function date(?string $d, string $fmt = 'd M Y'): string
    {
        return $d ? date($fmt, strtotime($d)) : '';
    }

    /** "+919876543210" / "919876543210" / "9876543210" -> "+91 98765 43210"; anything else is shown as typed. */
    public static function phone(?string $p): string
    {
        $d = preg_replace('/\D/', '', (string) $p);
        if (strlen($d) === 12 && str_starts_with($d, '91')) { return '+91 ' . substr($d, 2, 5) . ' ' . substr($d, 7); }
        if (strlen($d) === 10) { return '+91 ' . substr($d, 0, 5) . ' ' . substr($d, 5); }
        return trim((string) $p);
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Multi-line text -> safe HTML with <br>. */
    public static function nl(?string $s): string
    {
        return nl2br(self::e(trim((string) $s)));
    }

    /** One address block as lines (skips blanks). @return list<string> */
    public static function addressLines(array $p): array
    {
        $state = ! empty($p['state_code']) ? (Gst::STATES[$p['state_code']] ?? '') : ($p['state'] ?? '');
        $cityLine = trim(implode(', ', array_filter([$p['city'] ?? '', $state])) . (! empty($p['pincode']) ? ' - ' . $p['pincode'] : ''));
        return array_values(array_filter([$p['address_line1'] ?? '', $p['address_line2'] ?? '', $cityLine]));
    }
}
