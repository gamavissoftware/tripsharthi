<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

use App\Models\BusinessProfileModel;

/** The agency's legal identity. Validated hard on save: a wrong GSTIN printed on a tax invoice is a compliance problem, not a typo. */
final class BusinessProfileService
{
    public const FIELDS = ['legal_name', 'trade_name', 'gstin', 'pan', 'address_line1', 'address_line2', 'city', 'state_code', 'pincode', 'phone', 'email', 'website', 'sac_code',
        'bank_name', 'bank_account_name', 'bank_account_no', 'bank_ifsc', 'upi_id', 'invoice_prefix', 'credit_prefix', 'receipt_prefix', 'signatory', 'logo_url', 'brand_color',
        'quote_terms', 'invoice_terms', 'cancellation_policy'];

    public function get(int $tenantId): array
    {
        $r = (new BusinessProfileModel())->setTenant($tenantId)->first();
        if ($r) { return $r; }
        $tenant = db_connect()->table('tenants')->select('name')->where('id', $tenantId)->get()->getRowArray();
        return ['id' => null, 'tenant_id' => $tenantId, 'legal_name' => null, 'trade_name' => $tenant['name'] ?? null, 'gstin' => null, 'pan' => null, 'address_line1' => null, 'address_line2' => null,
            'city' => null, 'state_code' => null, 'pincode' => null, 'phone' => null, 'email' => null, 'website' => null, 'sac_code' => '998554', 'bank_name' => null, 'bank_account_name' => null,
            'bank_account_no' => null, 'bank_ifsc' => null, 'upi_id' => null, 'invoice_prefix' => 'INV', 'credit_prefix' => 'CN', 'receipt_prefix' => 'RC', 'signatory' => null, 'logo_url' => null,
            'brand_color' => '#0a6cc4', 'quote_terms' => null, 'invoice_terms' => null, 'cancellation_policy' => null];
    }

    /** @return list<string> problems (empty = valid) */
    public static function validate(array $d): array
    {
        $e = [];
        $gstin = strtoupper(trim((string) ($d['gstin'] ?? '')));
        if ($gstin !== '') {
            if (! Gst::validGstin($gstin)) { $e[] = 'GSTIN is not valid — check every character (the last one is a check character).'; }
            elseif (! empty($d['state_code']) && Gst::stateOfGstin($gstin) !== $d['state_code']) { $e[] = 'State does not match the first two digits of your GSTIN (' . Gst::stateOfGstin($gstin) . ').'; }
            if (! empty($d['pan']) && strtoupper(trim((string) $d['pan'])) !== Gst::panOfGstin($gstin)) { $e[] = 'PAN does not match the PAN inside your GSTIN (' . Gst::panOfGstin($gstin) . ').'; }
        }
        if (! empty($d['pan']) && ! Gst::validPan((string) $d['pan'])) { $e[] = 'PAN is not valid (format AAAAA9999A).'; }
        if (! empty($d['state_code']) && ! isset(Gst::STATES[$d['state_code']])) { $e[] = 'Choose a valid state.'; }
        if (! empty($d['pincode']) && ! preg_match('/^\d{6}$/', (string) $d['pincode'])) { $e[] = 'PIN code must be 6 digits.'; }
        if (! empty($d['bank_ifsc']) && ! Gst::validIfsc((string) $d['bank_ifsc'])) { $e[] = 'IFSC is not valid (format HDFC0001234).'; }
        if (! empty($d['upi_id']) && ! Gst::validUpi((string) $d['upi_id'])) { $e[] = 'UPI ID is not valid (format name@bank).'; }
        if (! empty($d['email']) && ! filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { $e[] = 'Email is not valid.'; }
        if (! empty($d['brand_color']) && ! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $d['brand_color'])) { $e[] = 'Brand colour must look like #0a6cc4.'; }
        if (! empty($d['sac_code']) && ! preg_match('/^\d{4,8}$/', (string) $d['sac_code'])) { $e[] = 'SAC code must be 4–8 digits.'; }
        foreach (['invoice_prefix', 'credit_prefix', 'receipt_prefix'] as $k) {
            if (isset($d[$k]) && $d[$k] !== '' && ! preg_match('/^[A-Za-z0-9]{1,4}$/', (string) $d[$k])) { $e[] = 'Number prefixes are 1–4 letters/digits (GST limits invoice numbers to 16 characters).'; break; }
        }
        if (! empty($d['logo_url']) && ! SafeImage::isPublicHttpUrl((string) $d['logo_url'])) { $e[] = 'Logo must be a public https:// image link.'; }
        return $e;
    }

    public function save(int $tenantId, array $in): array
    {
        $cur = $this->get($tenantId);
        $d = [];
        foreach (self::FIELDS as $f) {
            if (! array_key_exists($f, $in)) { continue; }
            $v = is_string($in[$f]) ? trim($in[$f]) : $in[$f];
            $d[$f] = $v === '' ? null : $v;
        }
        foreach (['gstin', 'pan', 'bank_ifsc'] as $f) { if (! empty($d[$f])) { $d[$f] = strtoupper($d[$f]); } }
        $merged = $d + $cur;
        // A GSTIN fixes the state and PAN; fill them in rather than make the user type the same thing three times.
        if (! empty($merged['gstin']) && Gst::validGstin($merged['gstin'])) {
            if (empty($d['state_code'])) { $d['state_code'] = $merged['state_code'] = Gst::stateOfGstin($merged['gstin']); }
            if (empty($d['pan'])) { $d['pan'] = $merged['pan'] = Gst::panOfGstin($merged['gstin']); }
        }
        foreach (['invoice_prefix' => 'INV', 'credit_prefix' => 'CN', 'receipt_prefix' => 'RC', 'sac_code' => '998554', 'brand_color' => '#0a6cc4'] as $k => $def) {
            if (array_key_exists($k, $d) && ($d[$k] === null || $d[$k] === '')) { $d[$k] = $def; $merged[$k] = $def; }
        }
        if ($errors = self::validate($merged)) { throw new \InvalidArgumentException(implode(' ', $errors)); }

        $m = (new BusinessProfileModel())->setTenant($tenantId);
        $cur['id'] ? (new BusinessProfileModel())->setTenant($tenantId)->update((int) $cur['id'], $d) : $m->insert($d + ['tenant_id' => $tenantId]);
        return $this->get($tenantId);
    }

    /**
     * What must be filled in before a document type can be issued. A tenant without a GSTIN may issue a Bill of Supply
     * (no GST), never a tax invoice.
     * @return list<string> missing items
     */
    public function missingFor(array $p, string $docType): array
    {
        $need = ['legal_name' => 'Legal business name', 'address_line1' => 'Address', 'city' => 'City', 'state_code' => 'State', 'pincode' => 'PIN code'];
        $missing = [];
        foreach ($need as $k => $label) { if (empty($p[$k])) { $missing[] = $label; } }
        if ($docType === 'tax_invoice' && empty($p['gstin'])) { $missing[] = 'GSTIN (without one, a Bill of Supply is issued instead)'; }
        return $missing;
    }
}
