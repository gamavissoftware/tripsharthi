<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\TravelAiService;
use PHPUnit\Framework\TestCase;

final class TravelAiServiceTest extends TestCase
{
    public function testHeuristicParsesHinglishEnquiry(): void
    {
        $p = TravelAiService::heuristicParse('Hi, hum 2 adults aur 1 kid ke liye Bali honeymoon plan chahte hain, 5 nights, budget 3.5 lakh, December mein');
        $this->assertSame('Bali', $p['destination_text']);
        $this->assertSame(1, $p['is_international']);
        $this->assertSame(2, $p['adults']);
        $this->assertSame(1, $p['children']);
        $this->assertSame(5, $p['nights']);
        $this->assertSame(35_000_000, $p['budget_max']);   // paise
        $this->assertSame('Dec', $p['travel_month']);
        $this->assertSame('honeymoon', $p['trip_type']);
    }

    public function testDomesticDestinationAndDaysToNights(): void
    {
        $p = TravelAiService::heuristicParse('Need Kerala family trip 6 days for 4 people, rs 80,000');
        $this->assertSame(0, $p['is_international']);
        $this->assertSame(5, $p['nights']);
        $this->assertSame(4, $p['adults']);
        $this->assertSame(8_000_000, $p['budget_max']);
    }

    public function testExtractJsonHandlesCodeFences(): void
    {
        $this->assertSame(['a' => 1], TravelAiService::extractJson("```json\n{\"a\":1}\n```"));
        $this->assertNull(TravelAiService::extractJson('no json here'));
    }

    public function testFallbackPlanShape(): void
    {
        $plan = TravelAiService::fallbackPlan(['destination_text' => 'Goa'], 3);
        $this->assertCount(4, $plan['days']);                  // N nights = N+1 days
        $this->assertSame('Departure', $plan['days'][3]['title']);
        $this->assertSame('3N/4D Goa', $plan['title']);
    }
}
