<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Database\Seeds\DripFlowSeeder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The drip graph is seeded rather than drawn by hand, so nothing in the UI
 * catches a mistake in it. These assert the properties that make it safe:
 * it stays inside the free 24-hour window, and a shut window ends the run
 * instead of falling through to a paid send.
 */
class DripFlowGraphTest extends CIUnitTestCase
{
    private const UNIT_SECONDS = ['seconds' => 1, 'minutes' => 60, 'hours' => 3600, 'days' => 86400];

    private array $graph;

    protected function setUp(): void
    {
        parent::setUp();
        $this->graph = DripFlowSeeder::buildGraph(1, 23);
    }

    private function nodesOfType(string $type): array
    {
        return array_values(array_filter($this->graph['nodes'], static fn ($n) => $n['type'] === $type));
    }

    private function edgesFrom(string $nodeId): array
    {
        return array_values(array_filter($this->graph['edges'], static fn ($e) => $e['source'] === $nodeId));
    }

    public function testGraphIsWellFormed(): void
    {
        $ids = array_column($this->graph['nodes'], 'id');

        $this->assertSame($ids, array_unique($ids), 'Node ids must be unique');

        foreach ($this->graph['edges'] as $edge) {
            $this->assertContains($edge['source'], $ids, "Edge {$edge['id']} has an unknown source");
            $this->assertContains($edge['target'], $ids, "Edge {$edge['id']} has an unknown target");
        }
    }

    public function testEveryNodeCarriesACanvasPosition(): void
    {
        // A node without a position white-screens the React Flow canvas.
        foreach ($this->graph['nodes'] as $node) {
            $this->assertArrayHasKey('position', $node, "Node {$node['id']} has no position");
            $this->assertIsNumeric($node['position']['x']);
            $this->assertIsNumeric($node['position']['y']);
        }
    }

    public function testItStartsFromTheTagAddedTrigger(): void
    {
        $this->assertSame('tag_added', $this->graph['nodes'][0]['type']);
        $this->assertSame(1, $this->graph['nodes'][0]['data']['tag_id']);
    }

    /**
     * The economic heart of the design: every send is free-form, which is only
     * free and only permitted while the 24-hour window is open. A send_template
     * here would mean a paid message on a path meant to cost nothing.
     */
    public function testEverySendIsFreeFormNotTemplate(): void
    {
        $this->assertCount(1, $this->nodesOfType('send_freeform'));
        $this->assertCount(1, $this->nodesOfType('send_slots'));
        $this->assertCount(0, $this->nodesOfType('send_template'));
    }

    /**
     * Every free-form send must sit BEHIND a window check — not necessarily
     * immediately after it, since conditions may sit in between. Walks upstream
     * to prove a window_check gates the path, and that the path leaves it by
     * the 'open' branch.
     */
    public function testEveryFreeFormSendIsGuardedByAWindowCheck(): void
    {
        $byId  = array_column($this->graph['nodes'], null, 'id');
        $sends = array_merge($this->nodesOfType('send_freeform'), $this->nodesOfType('send_slots'));

        foreach ($sends as $send) {
            $guarded = false;
            $seen    = [];
            $queue   = [$send['id']];

            while ($queue !== []) {
                $id = array_shift($queue);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                foreach ($this->graph['edges'] as $edge) {
                    if ($edge['target'] !== $id) {
                        continue;
                    }
                    $source = $byId[$edge['source']] ?? null;
                    if (($source['type'] ?? '') === 'window_check') {
                        $this->assertSame('open', $edge['sourceHandle'],
                            "{$send['id']} is reached from a window check's non-open branch");
                        $guarded = true;
                        continue;
                    }
                    $queue[] = $edge['source'];
                }
            }

            $this->assertTrue($guarded, "No window_check gates the path to {$send['id']}");
        }
    }

    /** A shut window must end the run — never fall through to another send. */
    public function testTheClosedWindowBranchIsDeliberatelyTerminal(): void
    {
        foreach ($this->nodesOfType('window_check') as $check) {
            $handles = array_column($this->edgesFrom($check['id']), 'sourceHandle');
            $this->assertContains('open', $handles);
            $this->assertNotContains('closed', $handles, 'The closed branch must lead nowhere');
        }
    }

    /**
     * Total delay must stay under 24h. Past that the window has shut, every
     * window_check takes the closed branch, and the drip silently never runs.
     */
    public function testCumulativeDelayStaysInsideTheFreeWindow(): void
    {
        $total = 0;
        foreach ($this->nodesOfType('delay') as $delay) {
            $total += (int) $delay['data']['value'] * self::UNIT_SECONDS[$delay['data']['unit']];
        }

        $this->assertGreaterThan(0, $total, 'A drip with no delay is just a burst of messages');
        $this->assertLessThan(24 * 3600, $total, 'Delays must total under 24h or the window shuts');
    }

    // ------------------------------------------------------------------
    // Booking
    // ------------------------------------------------------------------

    /** The picker must be followed by something that actually books. */
    public function testTheSlotPickerFeedsABookingNode(): void
    {
        $picker = $this->nodesOfType('send_slots')[0];
        $targets = array_column($this->edgesFrom($picker['id']), 'target');

        $this->assertNotEmpty($targets);
        $booking = $this->nodesOfType('book_slot');
        $this->assertCount(1, $booking);
        $this->assertContains($booking[0]['id'], $targets);
    }

    /**
     * Both outcomes must lead somewhere. An unhandled 'failed' branch means a
     * contact who tried to book, hit a taken slot, and was then ignored.
     */
    public function testBothBookingOutcomesAreHandled(): void
    {
        $booking = $this->nodesOfType('book_slot')[0];
        $handles = array_column($this->edgesFrom($booking['id']), 'sourceHandle');

        $this->assertContains('booked', $handles);
        $this->assertContains('failed', $handles);
    }

    /**
     * The subtlety that silently broke booking in live testing: a parked send
     * resumes by matching an edge whose sourceHandle EQUALS the tapped button
     * id, falling back to 'fallback'. Slot ids are generated per send, so no
     * edge can name one — the picker MUST hand off via 'fallback' or every tap
     * completes the run without booking anything.
     */
    public function testThePickerHandsOffViaFallbackNotNext(): void
    {
        $picker  = $this->nodesOfType('send_slots')[0];
        $handles = array_column($this->edgesFrom($picker['id']), 'sourceHandle');

        $this->assertContains('fallback', $handles);
        $this->assertNotContains('next', $handles, "'next' is never followed after a parked interactive send");
    }

    /** A contact who already booked must not be offered a second list. */
    public function testTheDripSkipsThePickerWhenAlreadyBooked(): void
    {
        $picker    = $this->nodesOfType('send_slots')[0];
        $incoming  = array_values(array_filter($this->graph['edges'], fn ($e) => $e['target'] === $picker['id']));
        $byId      = array_column($this->graph['nodes'], null, 'id');

        $this->assertCount(1, $incoming);
        $gate = $byId[$incoming[0]['source']];
        $this->assertSame('condition', $gate['type']);
        $this->assertSame('not_has_tag', $gate['data']['operator']);
        $this->assertSame('true', $incoming[0]['sourceHandle']);
    }

    public function testASuccessfulBookingIsTagged(): void
    {
        $booking = $this->nodesOfType('book_slot')[0];
        $byId    = array_column($this->graph['nodes'], null, 'id');

        $bookedEdge = array_values(array_filter(
            $this->edgesFrom($booking['id']), static fn ($e) => $e['sourceHandle'] === 'booked'
        ));
        $this->assertCount(1, $bookedEdge);
        $this->assertSame('add_tag', $byId[$bookedEdge[0]['target']]['type']);
    }

    public function testThePickerAsksForATimezoneExplicitly(): void
    {
        // Without one it falls back to a default, and a slot labelled 10:00
        // could be offered at 10:00 in the wrong country.
        $picker = $this->nodesOfType('send_slots')[0];
        $this->assertNotEmpty($picker['data']['timezone'] ?? '');
        $this->assertNotEmpty($this->nodesOfType('book_slot')[0]['data']['timezone'] ?? '');
    }

    public function testItAlertsAHumanAtTheEnd(): void
    {
        $alerts = $this->nodesOfType('notify_number');
        $this->assertCount(1, $alerts);
        $this->assertSame(23, $alerts[0]['data']['template_id']);
        $this->assertNotEmpty($alerts[0]['data']['to']);
    }
}
