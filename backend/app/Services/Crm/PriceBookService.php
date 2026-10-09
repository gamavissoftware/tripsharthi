<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\PriceBookEntryModel;
use App\Models\ProductModel;

/**
 * Resolves a product's price for a deal (Phase J3). A price-book entry wins; with
 * no book or no entry, the product's base price applies. Tenant-scoped.
 */
final class PriceBookService
{
    /** Effective unit price (paise) for a product, optionally via a price book. */
    public function priceFor(int $tenantId, int $productId, ?int $priceBookId = null): int
    {
        if ($priceBookId) {
            $entry = (new PriceBookEntryModel())->setTenant($tenantId)
                ->where('price_book_id', $priceBookId)->where('product_id', $productId)->first();
            if ($entry) {
                return (int) $entry['price_paise'];
            }
        }
        $product = (new ProductModel())->setTenant($tenantId)->find($productId);
        $product = is_array($product) ? $product : (array) ($product ?? []);
        return (int) ($product['price_paise'] ?? 0);
    }
}
