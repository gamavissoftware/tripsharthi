<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

/**
 * GST compliance helpers (pure — no I/O). The rules here are mechanical and checkable; anything that is a tax
 * JUDGEMENT (which rate applies, whether ITC may be claimed, SAC choice) is a tenant setting reviewed by their CA.
 */
final class Gst
{
    /** GST state / UT codes. 25 is legacy Daman & Diu (merged into 26); 28 is pre-bifurcation Andhra Pradesh. */
    public const STATES = [
        '01' => 'Jammu & Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi',
        '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
        '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha',
        '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat', '25' => 'Daman & Diu', '26' => 'Dadra & Nagar Haveli and Daman & Diu', '27' => 'Maharashtra',
        '28' => 'Andhra Pradesh (Old)', '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
        '35' => 'Andaman & Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh', '97' => 'Other Territory', '99' => 'Centre Jurisdiction',
    ];

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Structure + state code + embedded PAN shape + check character. */
    public static function validGstin(string $g): bool
    {
        $g = strtoupper(trim($g));
        if (! preg_match('/^(\d{2})([A-Z]{5}\d{4}[A-Z])(\d)([A-Z0-9])([A-Z0-9])$/', $g, $m)) { return false; }
        if (! isset(self::STATES[$m[1]]) || $m[4] !== 'Z') { return false; }   // 14th char is 'Z' by default
        return self::checkChar(substr($g, 0, 14)) === $g[14];
    }

    /** The 15th character for a 14-character GSTIN prefix (mod-36 checksum). */
    public static function checkChar(string $first14): string
    {
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $v = (int) strpos(self::ALPHABET, $first14[$i]) * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($v, 36) + ($v % 36);
        }
        return self::ALPHABET[(36 - ($sum % 36)) % 36];
    }

    public static function stateOfGstin(string $g): ?string
    {
        $c = substr(trim($g), 0, 2);
        return isset(self::STATES[$c]) ? self::code($c) : null;
    }

    public static function panOfGstin(string $g): string
    {
        return strtoupper(substr(trim($g), 2, 10));
    }

    public static function validPan(string $p): bool
    {
        return (bool) preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', strtoupper(trim($p)));
    }

    public static function validIfsc(string $i): bool
    {
        return (bool) preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper(trim($i)));
    }

    public static function validUpi(string $u): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z]{2,64}$/', trim($u));
    }

    /** Normalise an array-key code ('7' / 29 / '07') to the 2-character string form used everywhere else. */
    public static function code(int|string $c): string
    {
        return str_pad((string) $c, 2, '0', STR_PAD_LEFT);
    }

    /** @return list<array{code:string,name:string}> — a LIST, because PHP cannot keep '29' as a string array key */
    public static function states(): array
    {
        $out = [];
        foreach (self::STATES as $c => $n) { $out[] = ['code' => self::code($c), 'name' => $n]; }
        return $out;
    }

    /** Free text ("tamil nadu", "TN", "Maharashtra ") -> 2-digit code, or null. */
    public static function stateCode(int|string|null $name): ?string
    {
        if (is_int($name)) { $name = self::code($name); }
        if (preg_match('/^\d{1,2}$/', trim((string) $name))) {                      // already a code
            $c = self::code(trim((string) $name));
            return isset(self::STATES[$c]) ? $c : null;
        }
        $n = preg_replace('/[^a-z]/', '', strtolower((string) $name));
        if ($n === '') { return null; }
        static $alias = null;
        $alias ??= [
            'jk' => '01', 'hp' => '02', 'pb' => '03', 'ch' => '04', 'uk' => '05', 'uttaranchal' => '05', 'hr' => '06', 'dl' => '07', 'newdelhi' => '07', 'rj' => '08', 'up' => '09',
            'br' => '10', 'sk' => '11', 'ar' => '12', 'nl' => '13', 'mn' => '14', 'mz' => '15', 'tr' => '16', 'ml' => '17', 'as' => '18', 'wb' => '19', 'jh' => '20', 'od' => '21',
            'orissa' => '21', 'cg' => '22', 'mp' => '23', 'gj' => '24', 'dd' => '25', 'dnh' => '26', 'mh' => '27', 'ap' => '37', 'ka' => '29', 'goa' => '30', 'ld' => '31', 'kl' => '32',
            'tn' => '33', 'py' => '34', 'pondicherry' => '34', 'an' => '35', 'ts' => '36', 'tg' => '36', 'la' => '38',
        ];
        if (isset($alias[$n])) { return $alias[$n]; }
        // NB: PHP turns numeric-string array keys like '29' into ints — always cast codes back to 2-char strings.
        foreach (self::STATES as $code => $label) {
            if (preg_replace('/[^a-z]/', '', strtolower($label)) === $n) { return self::code($code); }
        }
        // "Jammu and Kashmir" / "Andaman and Nicobar" spelled with "and".
        $n2 = str_replace('and', '', $n);
        foreach (self::STATES as $code => $label) {
            if (str_replace('and', '', preg_replace('/[^a-z]/', '', strtolower($label))) === $n2) { return self::code($code); }
        }
        return null;
    }

    /**
     * Split GST into CGST/SGST (intra-state) or IGST (inter-state). Amounts in paise; the odd paisa of an
     * uneven split goes to SGST so cgst + sgst always equals the tax exactly.
     *
     * @return array{supply:string,cgst:int,sgst:int,igst:int}
     */
    public static function split(int $gstPaise, string $sellerState, string $placeOfSupply): array
    {
        if ($sellerState === $placeOfSupply) {
            $c = intdiv($gstPaise, 2);
            return ['supply' => 'intra', 'cgst' => $c, 'sgst' => $gstPaise - $c, 'igst' => 0];
        }
        return ['supply' => 'inter', 'cgst' => 0, 'sgst' => 0, 'igst' => $gstPaise];
    }

    /**
     * Place of supply for tour-operator services = the recipient's location. When the recipient is unregistered
     * and gave no address, it is the supplier's location.
     */
    public static function placeOfSupply(?string $buyerStateCode, string $sellerState): string
    {
        return ($buyerStateCode && isset(self::STATES[$buyerStateCode])) ? $buyerStateCode : $sellerState;
    }

    /** Indian financial year (1 Apr – 31 Mar) of a date, as "2026-27". */
    public static function fy(string $date): string
    {
        $t = strtotime($date);
        $y = (int) date('Y', $t);
        $start = (int) date('n', $t) >= 4 ? $y : $y - 1;
        return $start . '-' . substr((string) ($start + 1), 2);
    }

    /**
     * Invoice number: "{PREFIX}/{26-27}/{00001}". GST caps an invoice number at 16 characters of A–Z, 0–9, "/" and "-",
     * so the prefix is limited to 4 characters and the sequence is 5 digits.
     */
    public static function invoiceNumber(string $prefix, string $fy, int $seq): string
    {
        $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix));
        $p = substr($p === '' ? 'INV' : $p, 0, 4);
        $n = sprintf('%s/%s/%05d', $p, substr($fy, 2, 2) . '-' . substr($fy, 5, 2), $seq);
        if (strlen($n) > 16) { throw new \LogicException('Invoice number exceeds the 16-character GST limit: ' . $n); }
        return $n;
    }

    /** UPI deep link for a QR code (NPCI spec). Amount in paise. */
    public static function upiUri(string $vpa, string $payee, int $amountPaise, string $note): string
    {
        return 'upi://pay?' . http_build_query([
            'pa' => trim($vpa), 'pn' => mb_substr($payee, 0, 50), 'am' => number_format($amountPaise / 100, 2, '.', ''), 'cu' => 'INR', 'tn' => mb_substr($note, 0, 40),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
