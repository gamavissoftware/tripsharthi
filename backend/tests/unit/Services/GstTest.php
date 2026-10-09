<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\Docs\AmountInWords;
use App\Services\Billing\Docs\Gst;
use PHPUnit\Framework\TestCase;

final class GstTest extends TestCase
{
    public function testGstinChecksumAcceptsRealStructureAndRejectsTypos(): void
    {
        $this->assertSame('V', Gst::checkChar('27AAPFU0939F1Z'));          // published sample GSTIN 27AAPFU0939F1ZV
        $this->assertTrue(Gst::validGstin('27AAPFU0939F1ZV'));
        $this->assertTrue(Gst::validGstin(' 27aapfu0939f1zv '));            // trims + case-insensitive
        $this->assertFalse(Gst::validGstin('27AAPFU0939F1ZW'));             // wrong check character
        $this->assertFalse(Gst::validGstin('27AAPFU0939F1AV'));             // 14th char must be Z
        $this->assertFalse(Gst::validGstin('99ZZZZZ0000Z1ZV'));
        $this->assertFalse(Gst::validGstin('40AAPFU0939F1ZV'));             // no such state code
        $this->assertFalse(Gst::validGstin('27AAPFU0939F1Z'));              // too short
        $this->assertSame('27', Gst::stateOfGstin('27AAPFU0939F1ZV'));
        $this->assertSame('AAPFU0939F', Gst::panOfGstin('27AAPFU0939F1ZV'));
    }

    public function testEveryGeneratedGstinWithAValidPrefixPasses(): void
    {
        foreach (['07', '29', '33', '36'] as $st) {                         // the checksum must hold for ANY prefix, not one lucky case
            $first = $st . 'ABCDE1234F1Z';
            $this->assertTrue(Gst::validGstin($first . Gst::checkChar($first)), $first);
        }
    }

    public function testOtherIdentifiers(): void
    {
        $this->assertTrue(Gst::validPan('AAPFU0939F'));
        $this->assertFalse(Gst::validPan('AAPFU09399'));
        $this->assertTrue(Gst::validIfsc('HDFC0001234'));
        $this->assertFalse(Gst::validIfsc('HDFC1001234'));
        $this->assertTrue(Gst::validUpi('agency@okhdfcbank'));
        $this->assertFalse(Gst::validUpi('not a upi'));
    }

    public function testStateNamesMapToCodes(): void
    {
        foreach ([['Maharashtra', '27'], ['tamil nadu', '33'], ['TN', '33'], ['Delhi', '07'], ['New Delhi', '07'], ['Jammu and Kashmir', '01'], ['Odisha', '21'], ['Orissa', '21'], ['29', '29'],
                  [29, '29'], [7, '07'], ['Andaman & Nicobar Islands', '35'], ['Telangana', '36'], ['Andhra Pradesh', '37']] as [$in, $code]) {
            $this->assertSame($code, Gst::stateCode($in), (string) $in);
        }
        $karnataka = array_values(array_filter(Gst::states(), static fn ($s) => $s['code'] === '29'));
        $this->assertSame('Karnataka', $karnataka[0]['name']);               // codes are strings in the list form
        $this->assertNull(Gst::stateCode('Atlantis'));
        $this->assertNull(Gst::stateCode(''));
    }

    public function testIntraStateSplitsEvenlyAndAlwaysSumsToTheTax(): void
    {
        $s = Gst::split(241_500, '27', '27');
        $this->assertSame(['supply' => 'intra', 'cgst' => 120_750, 'sgst' => 120_750, 'igst' => 0], $s);
        $odd = Gst::split(241_501, '27', '27');
        $this->assertSame(241_501, $odd['cgst'] + $odd['sgst']);            // the odd paisa is not lost
        $this->assertSame(['supply' => 'inter', 'cgst' => 0, 'sgst' => 0, 'igst' => 241_500], Gst::split(241_500, '27', '29'));
    }

    public function testPlaceOfSupplyFallsBackToSupplierLocation(): void
    {
        $this->assertSame('29', Gst::placeOfSupply('29', '27'));
        $this->assertSame('27', Gst::placeOfSupply(null, '27'));
        $this->assertSame('27', Gst::placeOfSupply('88', '27'));             // invalid code ignored
    }

    public function testFinancialYearAndNumberFormat(): void
    {
        $this->assertSame('2026-27', Gst::fy('2026-04-01'));
        $this->assertSame('2025-26', Gst::fy('2026-03-31'));
        $this->assertSame('2026-27', Gst::fy('2027-03-31'));
        $this->assertSame('INV/26-27/00001', Gst::invoiceNumber('inv', '2026-27', 1));
        $this->assertLessThanOrEqual(16, strlen(Gst::invoiceNumber('ABCD', '2026-27', 99999)));
        $this->assertSame('INV/26-27/00007', Gst::invoiceNumber('', '2026-27', 7));
        $this->assertSame('ABCD/26-27/00001', Gst::invoiceNumber('abcdefgh', '2026-27', 1));   // prefix capped so the number stays <= 16 chars
    }

    public function testUpiUri(): void
    {
        $u = Gst::upiUri('agency@okhdfcbank', 'Demo Travels', 1_810_525, 'INV/26-27/00001');
        $this->assertStringStartsWith('upi://pay?pa=agency%40okhdfcbank', $u);
        $this->assertStringContainsString('am=18105.25', $u);
        $this->assertStringContainsString('cu=INR', $u);
    }

    public function testAmountInWordsUsesTheIndianSystem(): void
    {
        $this->assertSame('Rupees Fifty One Thousand Seven Hundred Twenty Nine and Thirty Paise Only', AmountInWords::paise(5_172_930));
        $this->assertSame('Rupees One Lakh Only', AmountInWords::paise(10_000_000));
        $this->assertSame('Rupees Twelve Crore Thirty Four Lakh Fifty Six Thousand Seven Hundred Eighty Nine Only', AmountInWords::paise(12_345_678_900));
        $this->assertSame('Rupees One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight Only', AmountInWords::paise(1_234_567_800));
        $this->assertSame('Rupees Zero Only', AmountInWords::paise(0));
        $this->assertSame('Rupees Zero and Five Paise Only', AmountInWords::paise(5));
        $this->assertSame('Rupees Eleven Only', AmountInWords::paise(1100));
        $this->assertSame('Rupees One Hundred Only', AmountInWords::paise(10_000));
        $this->assertSame('Rupees One Thousand Only', AmountInWords::paise(100_000));
    }
}
