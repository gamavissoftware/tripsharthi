<?php

declare(strict_types=1);

namespace App\Models;

class DealLineItemModel extends BaseModel
{
    protected $table          = 'deal_line_items';
    protected $primaryKey     = 'id';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id', 'deal_id', 'product_id', 'name', 'quantity',
        'unit_price', 'discount_pct', 'tax_pct', 'total',
    ];

    /** Compute a line's total (paise) from qty, unit price, discount and tax. */
    public static function lineTotal(int $quantity, int $unitPrice, int $discountPct, int $taxPct): int
    {
        $gross = $quantity * $unitPrice;
        $afterDiscount = $gross - (int) round($gross * $discountPct / 100);
        return $afterDiscount + (int) round($afterDiscount * $taxPct / 100);
    }

    public function forDeal(int $tenantId, int $dealId): array
    {
        return $this->setTenant($tenantId)->where('deal_id', $dealId)->orderBy('id', 'ASC')->findAll();
    }
}
