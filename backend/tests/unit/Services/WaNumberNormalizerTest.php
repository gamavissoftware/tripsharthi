<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Leads\WaNumberNormalizer;
use CodeIgniter\Test\CIUnitTestCase;

class WaNumberNormalizerTest extends CIUnitTestCase
{
    // ------------------------------------------------------------------
    // Already has a country code prefix (+)
    // ------------------------------------------------------------------

    /**
     * Seen on the first live Meta lead: the person typed "07503660006" into a
     * WhatsApp-number field whose country selector already said IN +91, so Meta
     * delivered "+9107503660006". WhatsApp still delivered to it, but as stored
     * it would never dedupe against "+917503660006".
     */
    public function testTrunkZeroAfterAnIndianCountryCodeIsDropped(): void
    {
        $this->assertSame('+917503660006', WaNumberNormalizer::normalize('+9107503660006'));
        $this->assertSame('+917503660006', WaNumberNormalizer::normalize('+91 07503 660006'));
        $this->assertSame('+447700900123', WaNumberNormalizer::normalize('+4407700900123'));
        // A genuine number is untouched, and Italy keeps its leading 0.
        $this->assertSame('+917503660006', WaNumberNormalizer::normalize('+917503660006'));
        $this->assertSame('+390612345678', WaNumberNormalizer::normalize('+390612345678'));
    }

    public function testValidE164PassedThrough(): void
    {
        $this->assertSame('+919999900000', WaNumberNormalizer::normalize('+919999900000'));
    }

    public function testStripsSpacesAndDashes(): void
    {
        $this->assertSame('+91 99999 00000', '+91 99999 00000'); // raw
        $this->assertSame('+919999900000', WaNumberNormalizer::normalize('+91 99999 00000'));
        $this->assertSame('+919999900000', WaNumberNormalizer::normalize('+91-99999-00000'));
        $this->assertSame('+919999900000', WaNumberNormalizer::normalize('+91.99999.00000'));
        $this->assertSame('+919999900000', WaNumberNormalizer::normalize('(+91) 99999 00000'));
    }

    public function testTooShortWithPlusReturnsEmpty(): void
    {
        $this->assertSame('', WaNumberNormalizer::normalize('+12345'));
    }

    public function testTooLongWithPlusReturnsEmpty(): void
    {
        $this->assertSame('', WaNumberNormalizer::normalize('+1234567890123456'));
    }

    public function testNonDigitAfterPlusReturnsEmpty(): void
    {
        $this->assertSame('', WaNumberNormalizer::normalize('+91abc1234567'));
    }

    // ------------------------------------------------------------------
    // No '+', with default country code
    // ------------------------------------------------------------------

    public function testLocalNumberPrefixedWithCountryCode(): void
    {
        // 09123456789 → strip leading 0 → 9123456789 → +919123456789
        $this->assertSame('+919123456789', WaNumberNormalizer::normalize('09123456789', '+91'));
    }

    public function testCountryCodeWithoutPlusAlsoWorks(): void
    {
        $this->assertSame('+919123456789', WaNumberNormalizer::normalize('09123456789', '91'));
    }

    public function testLocalNumberNoLeadingZero(): void
    {
        $this->assertSame('+919999900000', WaNumberNormalizer::normalize('9999900000', '+91'));
    }

    public function testInvalidLocalNumberWithCountryCode(): void
    {
        // '1234' → strip leading zeros → '1234' → +91 + 1234 = '911234' = 6 digits < 7 → invalid
        $this->assertSame('', WaNumberNormalizer::normalize('1234', '+91'));
    }

    // ------------------------------------------------------------------
    // No '+', no country code
    // ------------------------------------------------------------------

    public function testDigitsOnlyNoCountryCode(): void
    {
        $this->assertSame('919999900000', WaNumberNormalizer::normalize('919999900000'));
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $this->assertSame('', WaNumberNormalizer::normalize(''));
        $this->assertSame('', WaNumberNormalizer::normalize('   '));
    }

    public function testAlphaInputReturnsEmpty(): void
    {
        $this->assertSame('', WaNumberNormalizer::normalize('notanumber'));
    }

    // ------------------------------------------------------------------
    // isSame helper
    // ------------------------------------------------------------------

    public function testIsSameIgnoresPlusPrefix(): void
    {
        $this->assertTrue(WaNumberNormalizer::isSame('+919999900000', '919999900000'));
        $this->assertFalse(WaNumberNormalizer::isSame('+919999900000', '+918888800000'));
    }
}
