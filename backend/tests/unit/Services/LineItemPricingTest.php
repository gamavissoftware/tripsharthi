<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Crm\LineItemPricing;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CRM Phase J2: product-picker pricing — product fills name/price, explicit input
 * overrides, and the line total applies discount then tax.
 */
class LineItemPricingTest extends CIUnitTestCase
{
    private const PRODUCT = ['name' => 'Pro Plan', 'price_paise' => 100000]; // ₹1,000

    public function testFillsFromProduct(): void
    {
        $r = (new LineItemPricing())->resolve(['quantity' => 2], self::PRODUCT);
        $this->assertSame('Pro Plan', $r['name']);
        $this->assertSame(100000, $r['unit_price']);
        $this->assertSame(200000, $r['total'], '2 × ₹1,000, no discount/tax');
    }

    public function testExplicitInputOverridesProduct(): void
    {
        $r = (new LineItemPricing())->resolve(['name' => 'Custom', 'unit_price' => 50000, 'quantity' => 1], self::PRODUCT);
        $this->assertSame('Custom', $r['name']);
        $this->assertSame(50000, $r['unit_price']);
    }

    public function testDiscountThenTaxMath(): void
    {
        // 1 × ₹1,000, 10% off → ₹900, +18% tax → ₹1,062 = 106200 paise
        $r = (new LineItemPricing())->resolve(['quantity' => 1, 'discount_pct' => 10, 'tax_pct' => 18], self::PRODUCT);
        $this->assertSame(106200, $r['total']);
    }

    public function testNoProductNoNameYieldsEmpty(): void
    {
        $r = (new LineItemPricing())->resolve(['quantity' => 1], null);
        $this->assertSame('', $r['name']);
        $this->assertSame(0, $r['unit_price']);
    }
}
