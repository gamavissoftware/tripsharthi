<?php

declare(strict_types=1);

namespace App\Services\Einvoice;

use App\Services\Billing\Docs\Gst;

/**
 * Invoice -> the IRP's e-invoice JSON (schema INV-01, version 1.1). PURE.
 * One line per invoice (the tour package), service (IsServc = Y, HSN = the profile's SAC), quantity 1, unit OTH. TCS is not GST: it travels in
 * ValDtls.OthChrg so TotInvVal equals the invoice total to the paisa. B2B only (the buyer has a GSTIN). Credit notes are CRN with the original
 * invoice referenced in RefDtls.PrecDocDtls.
 */
final class EinvoiceBuilder
{
    public const RATES = [0.0, 0.1, 0.25, 1.0, 1.5, 3.0, 5.0, 6.0, 7.5, 12.0, 18.0, 28.0];

    private static function rs(int $paise): float { return round($paise / 100, 2); }
    public static function date(string $ymd): string { return date('d/m/Y', strtotime($ymd)); }
    private static function digits(?string $v): string { return preg_replace('/\D/', '', (string) $v) ?? ''; }

    /** 10 digits for an Indian number (the IRP accepts 6-12 digits, numbers only). */
    public static function phone(?string $raw): ?string
    {
        $d = self::digits($raw);
        if (strlen($d) === 12 && str_starts_with($d, '91')) { $d = substr($d, 2); }
        return strlen($d) >= 6 && strlen($d) <= 12 ? $d : null;
    }

    private static function clip(?string $v, int $max): string { return mb_substr(trim((string) $v), 0, $max); }

    /**
     * @param array $doc     invoice fields: doc_type, number, issue_date, buyer(array), place_of_supply, sac, taxable_value, gst_rate, cgst, sgst, igst, tcs, total, lines
     * @param array $profile business profile (raw columns: gstin, legal_name, trade_name, address_line1/2, city, pincode, state_code, phone, email)
     * @param array|null $original for a credit note: the invoice it is against (number, issue_date)
     */
    public static function build(array $doc, array $profile, ?array $original = null): array
    {
        $isCn = $doc['doc_type'] === 'credit_note';
        $b = (array) $doc['buyer'];
        $tax = (int) $doc['cgst'] + (int) $doc['sgst'] + (int) $doc['igst'];
        $taxable = (int) $doc['taxable_value'];
        $desc = self::clip((string) ($doc['lines']['items'][0]['title'] ?? 'Tour package'), 300) ?: 'Tour package';

        $party = static function (array $x, array $extra): array {
            $out = ['Gstin' => (string) ($x['gstin'] ?? ''), 'LglNm' => self::clip($x['legal_name'] ?? $x['name'] ?? '', 100)] + $extra
                + ['Addr1' => self::clip($x['address_line1'] ?? '', 100), 'Loc' => self::clip($x['city'] ?? '', 50), 'Pin' => (int) self::digits($x['pincode'] ?? ''), 'Stcd' => (string) ($x['state_code'] ?? '')];
            if (! empty($x['address_line2'])) { $out['Addr2'] = self::clip($x['address_line2'], 100); }
            if (($ph = self::phone($x['phone'] ?? null)) !== null) { $out['Ph'] = $ph; }
            $em = trim((string) ($x['email'] ?? ''));
            if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL) && mb_strlen($em) <= 100) { $out['Em'] = $em; }
            return $out;
        };
        $seller = $party(['gstin' => $profile['gstin'] ?? '', 'legal_name' => $profile['legal_name'] ?? '', 'address_line1' => $profile['address_line1'] ?? '', 'address_line2' => $profile['address_line2'] ?? '',
            'city' => $profile['city'] ?? '', 'pincode' => $profile['pincode'] ?? '', 'state_code' => $profile['state_code'] ?? '', 'phone' => $profile['phone'] ?? '', 'email' => $profile['email'] ?? ''],
            !empty($profile['trade_name']) && $profile['trade_name'] !== $profile['legal_name'] ? ['TrdNm' => self::clip($profile['trade_name'], 100)] : []);
        $buyer = $party($b, ['Pos' => (string) ($doc['place_of_supply'] ?? '')]);

        $payload = [
            'Version' => '1.1',
            'TranDtls' => ['TaxSch' => 'GST', 'SupTyp' => 'B2B', 'RegRev' => 'N', 'IgstOnIntra' => 'N'],
            'DocDtls' => ['Typ' => $isCn ? 'CRN' : 'INV', 'No' => (string) $doc['number'], 'Dt' => self::date((string) $doc['issue_date'])],
            'SellerDtls' => $seller,
            'BuyerDtls' => $buyer,
            'ItemList' => [[
                'SlNo' => '1', 'PrdDesc' => $desc, 'IsServc' => 'Y', 'HsnCd' => (string) ($doc['sac'] ?: ($profile['sac_code'] ?? '998554')), 'Qty' => 1, 'Unit' => 'OTH',
                'UnitPrice' => self::rs($taxable), 'TotAmt' => self::rs($taxable), 'Discount' => 0, 'AssAmt' => self::rs($taxable), 'GstRt' => (float) $doc['gst_rate'],
                'IgstAmt' => self::rs((int) $doc['igst']), 'CgstAmt' => self::rs((int) $doc['cgst']), 'SgstAmt' => self::rs((int) $doc['sgst']), 'CesRt' => 0, 'CesAmt' => 0, 'TotItemVal' => self::rs($taxable + $tax),
            ]],
            'ValDtls' => ['AssVal' => self::rs($taxable), 'CgstVal' => self::rs((int) $doc['cgst']), 'SgstVal' => self::rs((int) $doc['sgst']), 'IgstVal' => self::rs((int) $doc['igst']), 'CesVal' => 0,
                'OthChrg' => self::rs((int) $doc['tcs']), 'RndOffAmt' => 0, 'TotInvVal' => self::rs((int) $doc['total'])],
        ];
        if ($isCn && $original) { $payload['RefDtls'] = ['PrecDocDtls' => [['InvNo' => (string) $original['number'], 'InvDt' => self::date((string) $original['issue_date'])]]]; }
        return $payload;
    }

    /** What the signed QR code is expected to carry (the IRP signs it; this is only used by the demo simulator and for display). */
    public static function qrFields(array $payload, string $irn, string $irnDate): array
    {
        return ['SellerGstin' => $payload['SellerDtls']['Gstin'], 'BuyerGstin' => $payload['BuyerDtls']['Gstin'], 'DocNo' => $payload['DocDtls']['No'], 'DocTyp' => $payload['DocDtls']['Typ'], 'DocDt' => $payload['DocDtls']['Dt'],
            'TotInvVal' => $payload['ValDtls']['TotInvVal'], 'ItemCnt' => count($payload['ItemList']), 'MainHsnCode' => $payload['ItemList'][0]['HsnCd'], 'Irn' => $irn, 'IrnDt' => $irnDate];
    }

    /** Applicable only to B2B: the buyer must have a valid GSTIN. */
    public static function isB2b(array $buyer): bool { return ! empty($buyer['gstin']) && Gst::validGstin((string) $buyer['gstin']); }
}
