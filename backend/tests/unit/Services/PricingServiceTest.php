<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\PricingService;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase
{
    public function testDomesticPercentMarkupGstNoTcs(): void
    {
        $p = PricingService::price(10_000_000, 'percent', 15, 0, false); // cost ₹1,00,000
        $this->assertSame(11_500_000, $p['sell_subtotal']);
        $this->assertSame(575_000, $p['gst_amount']);          // 5%
        $this->assertSame(0, $p['tcs_amount']);
        $this->assertSame(12_075_000, $p['grand_total']);
        $this->assertSame(1_500_000, $p['margin_amount']);
    }

    public function testInternationalAddsTcsOnGstInclusiveAmount(): void
    {
        $p = PricingService::price(10_000_000, 'percent', 15, 0, true);
        $this->assertSame(241_500, $p['tcs_amount']);           // 2% of 1,20,75,000... of (11.5L+0.575L)
        $this->assertSame(12_316_500, $p['grand_total']);
    }

    public function testDiscountCannotExceedGrossAndFlatMarkup(): void
    {
        $p = PricingService::price(1_000_000, 'flat', 200_000, 99_999_999, false);
        $this->assertSame(0, $p['sell_subtotal']);
        $this->assertSame(1_200_000, $p['discount_amount']);
        $this->assertSame(0, $p['grand_total']);
    }

    public function testScheduleSumsExactlyToTotal(): void
    {
        $rows = PricingService::paymentSchedule(12_316_501, '2026-10-08', '2027-01-20', 30, 3);
        $this->assertCount(4, $rows);
        $this->assertSame(12_316_501, array_sum(array_column($rows, 'amount')));
        $this->assertSame('Balance', $rows[3]['label']);
        $this->assertLessThanOrEqual('2027-01-05', $rows[3]['due_date']);
    }
}
