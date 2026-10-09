<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Ads\AdGuardrails;
use PHPUnit\Framework\TestCase;

final class AdGuardrailsTest extends TestCase
{
    private const S = ['daily_spend_cap' => 500_000, 'min_daily_budget' => 10_000, 'max_increase_pct' => 30]; // ₹5,000 cap, ₹100 min

    public function testNoCapMeansNothingMaySpend(): void
    {
        $v = AdGuardrails::check(['daily_spend_cap' => 0] + self::S, 'INR', 0, 100_000);
        $this->assertCount(1, $v);
        $this->assertStringContainsString('daily spend cap', $v[0]);
    }

    public function testNewCampaignWithinCapIsAllowed(): void
    {
        $this->assertSame([], AdGuardrails::check(self::S, 'INR', 200_000, 100_000));
    }

    public function testCapIsEnforcedAcrossAllActiveCampaigns(): void
    {
        $v = AdGuardrails::check(self::S, 'INR', 450_000, 100_000);   // 4,500 + 1,000 > 5,000
        $this->assertCount(1, $v);
        $this->assertStringContainsString('above your cap', $v[0]);
    }

    public function testEditProjectsTotalWithoutDoubleCountingTheOldBudget(): void
    {
        // total active 4,500 incl. this campaign at 1,000; raising to 1,300 -> 4,800 (<= 5,000) and +30% is the limit
        $this->assertSame([], AdGuardrails::check(self::S, 'INR', 450_000, 130_000, 100_000));
    }

    public function testSingleIncreaseLimitedToConfiguredPercent(): void
    {
        $v = AdGuardrails::check(self::S, 'INR', 100_000, 140_000, 100_000);   // +40% > 30%
        $this->assertCount(1, $v);
        $this->assertStringContainsString('at most 30%', $v[0]);
    }

    public function testReductionsAreAlwaysAllowedEvenOverTheCap(): void
    {
        $this->assertSame([], AdGuardrails::check(self::S, 'INR', 900_000, 50_000, 100_000));   // total already over cap; cutting is fine
    }

    public function testMinimumBudgetAndCurrencyAreEnforced(): void
    {
        $v = AdGuardrails::check(self::S, 'USD', 0, 5_000);
        $this->assertCount(2, $v);
        $this->assertStringContainsString('USD', implode(' ', $v));
        $this->assertStringContainsString('minimum', implode(' ', $v));
    }

    public function testPausedCampaignDoesNotCountAgainstTheCap(): void
    {
        // Creating a paused draft costs nothing: only the minimum/currency rules apply.
        $this->assertSame([], AdGuardrails::check(self::S, 'INR', 480_000, 100_000, null, false));
    }

    public function testMoneyConversions(): void
    {
        $this->assertSame(150_000, AdGuardrails::toPaise('1500'));
        $this->assertSame(1_500_000_000, AdGuardrails::paiseToMicros(150_000));
        $this->assertSame(150_000, AdGuardrails::microsToPaise(1_500_000_000));
        $this->assertSame('₹1,500', AdGuardrails::inr(150_000));
    }
}
