<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Commerce\OrderParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests parsing of inbound WhatsApp catalog order messages.
 */
class OrderParserTest extends CIUnitTestCase
{
    public function testParsesItemsAndTotal(): void
    {
        $order = [
            'catalog_id' => 'cat_123',
            'text'       => 'Please deliver fast',
            'product_items' => [
                ['product_retailer_id' => 'SKU1', 'quantity' => 2, 'item_price' => 149.50, 'currency' => 'INR'],
                ['product_retailer_id' => 'SKU2', 'quantity' => 1, 'item_price' => 99,     'currency' => 'INR'],
            ],
        ];

        $parsed = OrderParser::parse($order);

        $this->assertSame('cat_123', $parsed['catalog_id']);
        $this->assertSame('INR', $parsed['currency']);
        $this->assertSame('Please deliver fast', $parsed['note']);
        $this->assertCount(2, $parsed['items']);

        // 2 × 149.50 = 299.00 → 29900 ; + 99.00 → 9900 ; total 39800 paise
        $this->assertSame(29900, $parsed['items'][0]['line_total_paise']);
        $this->assertSame(14950, $parsed['items'][0]['item_price_paise']);
        $this->assertSame(39800, $parsed['total_paise']);
    }

    public function testEmptyOrder(): void
    {
        $parsed = OrderParser::parse([]);
        $this->assertSame('', $parsed['catalog_id']);
        $this->assertSame([], $parsed['items']);
        $this->assertSame(0, $parsed['total_paise']);
    }

    public function testQuantityDefaultsToOne(): void
    {
        $parsed = OrderParser::parse([
            'product_items' => [['product_retailer_id' => 'SKU9', 'item_price' => 10]],
        ]);
        $this->assertSame(1, $parsed['items'][0]['quantity']);
        $this->assertSame(1000, $parsed['total_paise']);
    }
}
