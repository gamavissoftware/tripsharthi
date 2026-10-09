<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\RateCardRows;
use CodeIgniter\Test\CIUnitTestCase;

final class RateCardRowsTest extends CIUnitTestCase
{
    public function testHeaderDetectionFindsTheTableEvenBelowATitle(): void
    {
        $grid = [['Taj Resort rate card 2026'], [], ['Room Type', 'Season', 'Net Rate', 'Per', 'Valid From', 'Valid To'], ['Deluxe', 'Peak', '12,500', 'night', '01/12/2026', '31/12/2026']];
        [$at, $map] = RateCardRows::findHeader($grid);
        $this->assertSame(2, $at);
        $this->assertSame(['service_name', 'notes', 'amount', 'unit', 'valid_from', 'valid_to'], array_values($map));
        $raws = RateCardRows::fromTable($grid, $at, $map);
        $this->assertSame('12,500', $raws[0]['amount']);
        $this->assertNull(RateCardRows::findHeader([['foo', 'bar'], ['1', '2']]));
    }

    public function testAmountsParseRupeeSymbolsCommasKAndLakh(): void
    {
        $this->assertSame([12500.0, 'INR'], RateCardRows::parseAmount('₹12,500'));
        $this->assertSame([12500.0, 'INR'], RateCardRows::parseAmount('Rs. 12500/-'));
        $this->assertSame([1250.5, 'USD'], RateCardRows::parseAmount('USD 1,250.50'));
        $this->assertSame([12000.0, null], RateCardRows::parseAmount('12k'));
        $this->assertSame([150000.0, null], RateCardRows::parseAmount('1.5 lakh'));
        $this->assertSame([null, null], RateCardRows::parseAmount('on request'));
    }

    public function testTypeAndUnitInference(): void
    {
        $this->assertSame('hotel', RateCardRows::type('', 'Deluxe Pool Villa'));
        $this->assertSame('transfer', RateCardRows::type('', 'Innova airport pickup'));
        $this->assertSame('other', RateCardRows::type('', 'Miscellaneous'));
        $this->assertSame('per_night', RateCardRows::unit('Per Night', 'hotel'));
        $this->assertSame('per_pax', RateCardRows::unit('pp', 'other'));
        $this->assertSame('per_vehicle', RateCardRows::unit('', 'transfer'));
        $this->assertSame('per_night', RateCardRows::unit('per room/night', 'hotel'));     // night is checked before room, as written
        $this->assertSame('per_room', RateCardRows::unit('per room', 'hotel'));
    }

    public function testDatesAreDayFirstAndImpossibleOnesRejected(): void
    {
        $this->assertSame('2026-12-01', RateCardRows::date('01/12/2026'));
        $this->assertSame('2026-12-31', RateCardRows::date('31-12-26'));
        $this->assertSame('2026-11-05', RateCardRows::date('2026-11-05'));
        $this->assertNull(RateCardRows::date('31/02/2026'));
        $this->assertNull(RateCardRows::date(''));
    }

    public function testNormaliseConvertsToMinorUnitsPerCurrency(): void
    {
        $inr = RateCardRows::normalise(['service_name' => 'Deluxe room', 'amount' => '12,500', 'unit' => 'per night']);
        $this->assertSame([], $inr['errors']);
        $this->assertSame(1_250_000, $inr['row']['cost_amount']);                       // paise
        $usd = RateCardRows::normalise(['service_name' => 'Villa', 'amount' => '$100']);
        $this->assertSame(['USD', 10_000], [$usd['row']['currency'], $usd['row']['cost_amount']]);   // cents
        $jpy = RateCardRows::normalise(['service_name' => 'Ryokan', 'amount' => '15000', 'currency' => 'JPY']);
        $this->assertSame(15000, $jpy['row']['cost_amount']);                            // no minor unit
        $kwd = RateCardRows::normalise(['service_name' => 'X', 'amount' => '1.5', 'currency' => 'KWD']);
        $this->assertSame(1500, $kwd['row']['cost_amount']);
    }

    public function testBadRowsReportEverythingAtOnce(): void
    {
        $n = RateCardRows::normalise(['service_name' => '', 'amount' => 'on request', 'currency' => 'XXX', 'valid_from' => '31/02/2026', 'valid_to' => '01/01/2020']);
        $e = implode(' | ', $n['errors']);
        foreach (['Service name', 'Price is missing', 'Currency XXX', 'Valid-from'] as $needle) { $this->assertStringContainsString($needle, $e); }
        $this->assertStringContainsString('before valid-from', implode(' ', RateCardRows::normalise(['service_name' => 'a', 'amount' => 5, 'valid_from' => '2026-12-31', 'valid_to' => '2026-12-01'])['errors']));
        $this->assertNotEmpty(RateCardRows::normalise(['service_name' => 'a', 'amount' => 999999999999])['errors']);
    }

    public function testAnAiPriceThatIsNotInTheSourceIsFlagged(): void
    {
        $src = "Deluxe room - Rs 12,500 per night\nSuite 1.5 lakh for the villa\nTransfer 2k";
        $this->assertSame([], RateCardRows::normalise(['service_name' => 'Deluxe', 'amount' => 12500], 'INR', $src, true)['errors']);
        $this->assertSame([], RateCardRows::normalise(['service_name' => 'Villa', 'amount' => 150000], 'INR', $src, true)['errors']);       // "1.5 lakh"
        $this->assertSame([], RateCardRows::normalise(['service_name' => 'Cab', 'amount' => 2000], 'INR', $src, true)['errors']);            // "2k"
        $bad = RateCardRows::normalise(['service_name' => 'Deluxe 3 nights', 'amount' => 37500], 'INR', $src, true);                           // computed 3 x 12,500
        $this->assertStringContainsString('not found in your text', implode(' ', $bad['errors']));
        $this->assertSame([], RateCardRows::normalise(['service_name' => 'Deluxe 3 nights', 'amount' => 37500], 'INR', $src, false)['errors']);   // a table cell is trusted
    }

    public function testClassifyNewSameChangeInvalidAndInFileDuplicates(): void
    {
        $mk = fn (string $n, int $amt, array $err = []) => ['service_name' => $n, 'unit' => 'per_night', 'currency' => 'INR', 'valid_from' => null, 'valid_to' => null, 'cost_amount' => $amt, 'errors' => $err];
        $existing = [RateCardRows::key($mk('Deluxe', 100)) => ['id' => 1, 'cost_amount' => 100, 'valid_to' => null], RateCardRows::key($mk('Suite', 500)) => ['id' => 2, 'cost_amount' => 500, 'valid_to' => null]];
        $st = RateCardRows::classify([$mk('deluxe', 100), $mk('Suite', 700), $mk('Villa', 900), $mk('Villa', 950), $mk('Bad', 0, ['x'])], $existing);
        $this->assertSame(['same', 'change', 'new', 'duplicate_in_file', 'invalid'], $st);
        $this->assertTrue(RateCardRows::bigChange(1000, 1600));
        $this->assertFalse(RateCardRows::bigChange(1000, 1400));
    }

    public function testLineReaderHandlesCommonRateListShapes(): void
    {
        $rows = RateCardRows::heuristicLines("Rate list\nDeluxe Room - Rs 12,500 per night\nAirport transfer: ₹2,000 per vehicle\nTerms apply\nSnorkelling @ 1500");
        $this->assertSame(['Deluxe Room', 'Airport transfer', 'Snorkelling'], array_column($rows, 'service_name'));
        $this->assertStringContainsString('per night', $rows[0]['unit']);
        $this->assertSame([1250000, 200000], array_map(fn ($r) => RateCardRows::normalise($r)['row']['cost_amount'], array_slice($rows, 0, 2)));
    }
}
