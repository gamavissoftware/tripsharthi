<?php

declare(strict_types=1);

namespace App\Services\Einvoice;

use App\Services\Billing\Docs\Gst;

/**
 * Pre-flight checks that mirror the IRP's schema and business rules, so a problem is reported in plain words BEFORE a number is consumed
 * and before anything is sent. PURE. Returns every problem at once (not just the first) so the user fixes them in one go.
 */
final class EinvoiceValidator
{
    private const TOL = 0.011;   // amounts are exact to the paisa; this only absorbs float noise

    /** @return list<string> */
    public static function check(array $p, ?string $today = null): array
    {
        $e = [];
        $today ??= date('Y-m-d');
        $s = (array) ($p['SellerDtls'] ?? []); $b = (array) ($p['BuyerDtls'] ?? []); $d = (array) ($p['DocDtls'] ?? []); $v = (array) ($p['ValDtls'] ?? []);

        // seller (your business profile)
        if (! Gst::validGstin((string) ($s['Gstin'] ?? ''))) { $e[] = 'Your GSTIN on the Business profile is missing or invalid.'; }
        if (trim((string) ($s['LglNm'] ?? '')) === '') { $e[] = 'Add your legal business name on the Business profile.'; }
        if (trim((string) ($s['Addr1'] ?? '')) === '') { $e[] = 'Add your address on the Business profile (e-invoice needs it).'; }
        if (mb_strlen((string) ($s['Loc'] ?? '')) < 3) { $e[] = 'Add your city on the Business profile (at least 3 letters).'; }
        if (! self::pin($s['Pin'] ?? 0)) { $e[] = 'Add a valid 6-digit PIN code on your Business profile.'; }
        if (! self::state($s['Stcd'] ?? '')) { $e[] = 'Your business state is missing; save your GSTIN on the Business profile to fill it in.'; }

        // buyer (the customer)
        $bg = (string) ($b['Gstin'] ?? '');
        if (! Gst::validGstin($bg)) { $e[] = 'The customer\'s GSTIN is missing or invalid — e-invoices are for GST-registered (B2B) customers.'; }
        if (trim((string) ($b['LglNm'] ?? '')) === '') { $e[] = 'Enter the customer\'s legal name.'; }
        if (trim((string) ($b['Addr1'] ?? '')) === '') { $e[] = 'Enter the customer\'s address — the IRP rejects an e-invoice without it.'; }
        if (mb_strlen((string) ($b['Loc'] ?? '')) < 3) { $e[] = 'Enter the customer\'s city (at least 3 letters).'; }
        if (! self::pin($b['Pin'] ?? 0)) { $e[] = 'Enter the customer\'s 6-digit PIN code.'; }
        if (! self::state($b['Stcd'] ?? '')) { $e[] = 'Choose the customer\'s state.'; }
        elseif (Gst::validGstin($bg) && substr($bg, 0, 2) !== (string) $b['Stcd']) { $e[] = 'The customer\'s state does not match their GSTIN.'; }
        if (! self::state($b['Pos'] ?? '')) { $e[] = 'The place of supply is missing.'; }

        // document
        $no = (string) ($d['No'] ?? '');
        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9/\-]{0,15}$#', $no)) { $e[] = 'The document number must be 1-16 letters, digits, "/" or "-" (e.g. INV/26-27/00001).'; }
        if (! in_array($d['Typ'] ?? '', ['INV', 'CRN', 'DBN'], true)) { $e[] = 'Unknown document type.'; }
        $dt = \DateTimeImmutable::createFromFormat('d/m/Y', (string) ($d['Dt'] ?? ''));
        if (! $dt) { $e[] = 'The document date is invalid.'; }
        elseif ($dt->format('Y-m-d') > $today) { $e[] = 'The document date cannot be in the future.'; }
        if (($d['Typ'] ?? '') === 'CRN' && empty($p['RefDtls']['PrecDocDtls'][0]['InvNo'])) { $e[] = 'A credit note must reference the original invoice.'; }

        // items & values
        $items = (array) ($p['ItemList'] ?? []);
        if (! $items) { $e[] = 'The invoice has no line items.'; }
        $sum = ['ass' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0];
        foreach ($items as $i => $it) {
            $n = 'Line ' . ($i + 1) . ': ';
            if (! preg_match('/^\d{4,8}$/', (string) ($it['HsnCd'] ?? ''))) { $e[] = $n . 'the SAC/HSN code must be 4-8 digits (set it on the Business profile).'; }
            if (! in_array((float) ($it['GstRt'] ?? -1), EinvoiceBuilder::RATES, true)) { $e[] = $n . 'GST rate ' . ($it['GstRt'] ?? '?') . '% is not an allowed slab.'; }
            if (abs((float) $it['TotAmt'] - (float) ($it['Discount'] ?? 0) - (float) $it['AssAmt']) > self::TOL) { $e[] = $n . 'taxable value does not equal amount minus discount.'; }
            $tax = (float) $it['IgstAmt'] + (float) $it['CgstAmt'] + (float) $it['SgstAmt'];
            if (abs((float) $it['AssAmt'] + $tax + (float) ($it['CesAmt'] ?? 0) + (float) ($it['OthChrg'] ?? 0) - (float) $it['TotItemVal']) > self::TOL) { $e[] = $n . 'item total does not equal taxable value plus tax.'; }
            $expected = (float) $it['AssAmt'] * (float) $it['GstRt'] / 100;
            if ($expected > 0 && abs($tax - $expected) > 1.0) { $e[] = $n . 'the tax amount does not match the GST rate on the taxable value.'; }
            $sum['ass'] += (float) $it['AssAmt']; $sum['cgst'] += (float) $it['CgstAmt']; $sum['sgst'] += (float) $it['SgstAmt']; $sum['igst'] += (float) $it['IgstAmt'];
        }
        $intra = ($s['Stcd'] ?? 'x') === ($b['Pos'] ?? 'y');
        if ($intra && $sum['igst'] > 0) { $e[] = 'Supply within the same state must use CGST + SGST, not IGST.'; }
        if (! $intra && ($sum['cgst'] > 0 || $sum['sgst'] > 0)) { $e[] = 'Inter-state supply must use IGST, not CGST/SGST.'; }
        foreach (['AssVal' => 'ass', 'CgstVal' => 'cgst', 'SgstVal' => 'sgst', 'IgstVal' => 'igst'] as $k => $x) {
            if (abs((float) ($v[$k] ?? 0) - $sum[$x]) > self::TOL) { $e[] = "Invoice total {$k} does not equal the sum of the lines."; }
        }
        $expectTotal = (float) ($v['AssVal'] ?? 0) + (float) ($v['CgstVal'] ?? 0) + (float) ($v['SgstVal'] ?? 0) + (float) ($v['IgstVal'] ?? 0) + (float) ($v['CesVal'] ?? 0) + (float) ($v['OthChrg'] ?? 0) - (float) ($v['Disc'] ?? 0) + (float) ($v['RndOffAmt'] ?? 0);
        if (abs($expectTotal - (float) ($v['TotInvVal'] ?? 0)) > self::TOL) { $e[] = 'The invoice total does not equal taxable value + tax + other charges (TCS).'; }
        return array_values(array_unique($e));
    }

    private static function pin(mixed $v): bool { $n = (int) $v; return $n >= 100000 && $n <= 999999; }
    private static function state(mixed $v): bool { return $v !== '' && $v !== null && isset(Gst::STATES[Gst::code((string) $v)]); }
}
