<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\DealLineItemModel;

/**
 * Resolves a deal line item from raw input + an optional catalog product (Phase J2).
 * A product fills in name and unit price; explicit input always overrides. The
 * line total reuses the existing DealLineItemModel::lineTotal math.
 */
final class LineItemPricing
{
    /**
     * @param array      $input   {name?, quantity?, unit_price?, discount_pct?, tax_pct?}
     * @param array|null $product a products row (with price_paise, name), or null
     * @return array{name:string, quantity:int, unit_price:int, discount_pct:int, tax_pct:int, total:int}
     */
    public function resolve(array $input, ?array $product = null): array
    {
        $name = trim((string) ($input['name'] ?? '')) ?: (string) ($product['name'] ?? '');
        $unit = isset($input['unit_price']) && $input['unit_price'] !== '' && $input['unit_price'] !== null
            ? max(0, (int) $input['unit_price'])
            : (int) ($product['price_paise'] ?? 0);
        $qty      = max(1, (int) ($input['quantity'] ?? 1));
        $discount = max(0, min(100, (int) ($input['discount_pct'] ?? 0)));
        $tax      = max(0, (int) ($input['tax_pct'] ?? 0));

        return [
            'name'         => $name,
            'quantity'     => $qty,
            'unit_price'   => $unit,
            'discount_pct' => $discount,
            'tax_pct'      => $tax,
            'total'        => DealLineItemModel::lineTotal($qty, $unit, $discount, $tax),
        ];
    }
}
