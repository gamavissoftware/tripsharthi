<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\Currency;
use App\Services\Travel\FxService;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function testForeignCostBecomesInrPaiseWithTheBufferOnTop(): void
    {
        // USD 100.00 (10,000 cents) at ₹83.50, +2% buffer = ₹8,517.00 = 851,700 paise
        $this->assertSame(851_700, Currency::toInrPaise(10_000, 'USD', 83.5, 2.0));
        $this->assertSame(835_000, Currency::toInrPaise(10_000, 'USD', 83.5, 0.0));
        $this->assertSame(123_456, Currency::toInrPaise(123_456, 'INR', 99.0, 5.0));        // INR is never converted or buffered
    }

    public function testMinorUnitsDifferByCurrency(): void
    {
        $this->assertSame(125_050, Currency::toMinor(1250.50, 'USD'));
        $this->assertSame(125_000, Currency::toMinor(125000, 'JPY'));                       // yen have no minor unit
        $this->assertSame(1_250_500, Currency::toMinor(1250.5, 'KWD'));                     // dinars have three
        // JPY 10,000 at ₹0.56 = ₹5,600 = 560,000 paise (NOT /100: yen are whole units)
        $this->assertSame(560_000, Currency::toInrPaise(10_000, 'JPY', 0.56, 0));
        // KWD 100.000 (100,000 fils) at ₹270 = ₹27,000
        $this->assertSame(2_700_000, Currency::toInrPaise(100_000, 'KWD', 270.0, 0));
    }

    public function testTinyRatesKeepTheirPrecision(): void
    {
        // IDR 1,500,000 at ₹0.00529 = ₹7,935 — an 8-decimal rate is needed (6 decimals would already be off by 0.2%)
        $this->assertSame(793_500, Currency::toInrPaise(150_000_000, 'IDR', 0.00529, 0));
        $this->assertSame(150_000_000, Currency::fromInrPaise(793_500, 'IDR', 0.00529));
    }

    public function testIndicativeCustomerAmountRoundTrips(): void
    {
        $inr = 5_172_930;                                                                   // ₹51,729.30
        $usd = Currency::fromInrPaise($inr, 'USD', 83.5);
        $this->assertSame(61_951, $usd);                                                    // $619.51
        $this->assertEqualsWithDelta($inr, Currency::toInrPaise($usd, 'USD', 83.5, 0), 100);
        $this->assertSame('$619.51', Currency::format($usd, 'USD'));
        $this->assertSame('¥125,000', Currency::format(125_000, 'JPY'));
        $this->assertSame('AED 1,200.00', Currency::format(120_000, 'AED'));
        $this->expectException(\InvalidArgumentException::class);
        Currency::fromInrPaise(100, 'USD', 0.0);
    }

    public function testRealisedRateAndTypoGuard(): void
    {
        $this->assertSame(84.0, Currency::realisedRate(840_000, 10_000, 'USD'));            // ₹8,400 paid for $100
        $this->assertSame(0.0, Currency::realisedRate(100, 0, 'USD'));
        $this->assertTrue(Currency::plausibleChange(83.5, 84.9));
        $this->assertFalse(Currency::plausibleChange(83.5, 8.35));                          // the classic dropped digit
        $this->assertFalse(Currency::plausibleChange(83.5, 120.0));
        $this->assertTrue(Currency::plausibleChange(0.0, 5.0));                             // no previous rate to compare with
    }

    public function testEveryListedCurrencyIsWellFormed(): void
    {
        foreach (Currency::LIST as $code => [$name, $symbol, $exp]) {
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $code);
            $this->assertNotSame('', $name . $symbol);
            $this->assertContains($exp, [0, 2, 3], $code);
        }
        $this->assertFalse(Currency::valid('XYZ'));
    }

    public function testFeedIsInvertedAndPeggedCurrenciesFollowTheDollar(): void
    {
        $f = FxService::parseFeed(['rates' => ['USD' => 0.012, 'EUR' => 0.0105, 'IDR' => 189.0, 'ZZZ' => 5.0, 'JPY' => 1.8, 'BAD' => 'x']]);
        $this->assertEqualsWithDelta(83.33333333, $f['USD'], 1e-6);
        $this->assertEqualsWithDelta(1 / 189.0, $f['IDR'], 1e-8);
        $this->assertArrayNotHasKey('ZZZ', $f);                                             // unsupported currency ignored
        $this->assertEqualsWithDelta($f['USD'] / 3.6725, $f['AED'], 1e-6);                  // AED is pegged to the dollar
        $this->assertEqualsWithDelta($f['USD'] / 3.75, $f['SAR'], 1e-6);
        $this->assertSame(1.0, $f['BTN']);
        $this->assertSame([], array_diff(array_keys(FxService::parseFeed(['rates' => []])), ['BTN']));
    }
}
