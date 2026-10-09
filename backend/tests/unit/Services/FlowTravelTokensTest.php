<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Flow\FlowEngine;
use App\Services\Flow\FlowTriggers;
use PHPUnit\Framework\TestCase;

/** Free-form tokens for travel flows resolve from run state; unknown/empty ones are left visible. */
final class FlowTravelTokensTest extends TestCase
{
    private function render(string $content, array $state, array $contact = ['name' => 'Rohit Sharma']): string
    {
        $m = new \ReflectionMethod(FlowEngine::class, 'renderStateTokens');
        $m->setAccessible(true);
        $ctx = ['state' => $state, 'contact' => $contact];
        return $m->invokeArgs(new FlowEngine(), [$content, &$ctx]);
    }

    public function testTravelTokensResolveFromState(): void
    {
        $out = $this->render('Hi {{contact.name}}, {{trip.destination}} trip {{booking.ref}} total {{booking.total}} — {{quote.link}}', [
            'trip_destination' => 'Bali', 'booking_ref' => 'TP-2026-0001', 'booking_total' => '₹51,729', 'quote_link' => 'https://x.test/#/q/abc',
        ]);
        $this->assertSame('Hi Rohit Sharma, Bali trip TP-2026-0001 total ₹51,729 — https://x.test/#/q/abc', $out);
    }

    public function testSpacedTokensAndRepeatsResolve(): void
    {
        $this->assertSame('Bali / Bali', $this->render('{{ trip.destination }} / {{trip.destination}}', ['trip_destination' => 'Bali']));
    }

    public function testMissingValueLeavesTokenVisibleRatherThanBlank(): void
    {
        $this->assertSame('Pay {{payment.link}} now', $this->render('Pay {{payment.link}} now', ['payment_link' => '']));
    }

    public function testOnlyWhitelistedGroupsAreResolved(): void
    {
        $this->assertSame('{{secret.key}} {{trip.nights}}', $this->render('{{secret.key}} {{trip.nights}}', ['secret_key' => 'LEAK', 'trip_nights' => '']));
    }

    public function testPlainCopyIsUntouched(): void
    {
        $this->assertSame('No tokens here', $this->render('No tokens here', ['trip_destination' => 'Bali']));
    }

    public function testRegistryHasNoDuplicatesAndIncludesEveryTravelTrigger(): void
    {
        $all = FlowTriggers::all();
        $this->assertSame(count($all), count(array_unique($all)));
        foreach (FlowTriggers::TRAVEL as $t) { $this->assertContains($t, $all); }
        $this->assertContains('task_due', $all);      // previously accepted by the engine but not storable
        $this->assertContains('date_reached', $all);  // previously rejected by the create API
    }
}
