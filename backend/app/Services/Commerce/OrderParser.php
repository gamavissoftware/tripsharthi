<?php

declare(strict_types=1);

namespace App\Services\Commerce;

/**
 * Parses an inbound WhatsApp 'order' message (a cart the customer sent from the
 * catalog) into a normalised structure with paise amounts.
 *
 * Meta payload shape:
 *   order: {
 *     catalog_id, text?,
 *     product_items: [{ product_retailer_id, quantity, item_price, currency }]
 *   }
 * item_price is in major units (e.g. 149.50). We convert to paise.
 */
class OrderParser
{
    /**
     * @return array{catalog_id:string, currency:string, items:array<int,array>, total_paise:int, note:string}
     */
    public static function parse(array $order): array
    {
        $catalogId = (string) ($order['catalog_id'] ?? '');
        $items     = [];
        $total     = 0;
        $currency  = 'INR';

        foreach ($order['product_items'] ?? [] as $item) {
            $qty       = max(1, (int) ($item['quantity'] ?? 1));
            $unitPaise = (int) round(((float) ($item['item_price'] ?? 0)) * 100);
            $currency  = $item['currency'] ?? $currency;
            $lineTotal = $qty * $unitPaise;
            $total    += $lineTotal;

            $items[] = [
                'retailer_id'      => (string) ($item['product_retailer_id'] ?? ''),
                'quantity'         => $qty,
                'item_price_paise' => $unitPaise,
                'line_total_paise' => $lineTotal,
                'currency'         => $currency,
            ];
        }

        return [
            'catalog_id'  => $catalogId,
            'currency'    => $currency,
            'items'       => $items,
            'total_paise' => $total,
            'note'        => (string) ($order['text'] ?? ''),
        ];
    }
}
