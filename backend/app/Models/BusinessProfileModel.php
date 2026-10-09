<?php

declare(strict_types=1);

namespace App\Models;

class BusinessProfileModel extends BaseModel
{
    protected $table      = 'business_profiles';
    protected $primaryKey = 'id';
    protected $allowedFields = ['tenant_id', 'legal_name', 'trade_name', 'gstin', 'pan', 'address_line1', 'address_line2', 'city', 'state_code', 'pincode', 'phone', 'email', 'website',
        'sac_code', 'bank_name', 'bank_account_name', 'bank_account_no', 'bank_ifsc', 'upi_id', 'invoice_prefix', 'credit_prefix', 'receipt_prefix', 'signatory', 'logo_url', 'brand_color',
        'quote_terms', 'invoice_terms', 'cancellation_policy'];
}
