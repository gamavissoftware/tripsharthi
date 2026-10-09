<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Analytics\MessageFilters;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The delivery report's filters are read straight off the query string, so
 * normalize() is the boundary between "what the operator asked for" and "what
 * the SQL is allowed to say". Everything it lets through ends up in a WHERE
 * clause; everything it drops must disappear rather than degrade the scope.
 */
class MessageFiltersTest extends CIUnitTestCase
{
    public function testOutboundIsTheDefaultDirection(): void
    {
        // Every "did it arrive / was it read" question is about what we sent.
        $this->assertSame('out', MessageFilters::normalize([])['direction']);
        $this->assertSame('in', MessageFilters::normalize(['direction' => 'in'])['direction']);
        $this->assertNull(MessageFilters::normalize(['direction' => 'all'])['direction']);
        $this->assertSame('out', MessageFilters::normalize(['direction' => 'sideways'])['direction']);
    }

    public function testUnknownEnumValuesAreDroppedNotPassedThrough(): void
    {
        $f = MessageFilters::normalize([
            'status'   => 'read,exploded,delivered',
            'category' => 'marketing,nonsense',
            'type'     => 'template,telepathy',
        ]);

        $this->assertSame(['read', 'delivered'], $f['statuses']);
        $this->assertSame(['marketing'], $f['categories']);
        $this->assertSame(['template'], $f['types']);
    }

    public function testCampaignNoneIsolatesNonBroadcastTraffic(): void
    {
        $this->assertSame('none', MessageFilters::normalize(['campaign_id' => 'none'])['campaign_id']);
        $this->assertSame(42, MessageFilters::normalize(['campaign_id' => '42'])['campaign_id']);
        $this->assertArrayNotHasKey('campaign_id', MessageFilters::normalize(['campaign_id' => '7 OR 1=1']));
    }

    public function testLocalCalendarDaysBecomeUtcInstants(): void
    {
        // IST is UTC+5:30. "Everything on 11 Sep, India time" is 10 Sep 18:30Z
        // through 11 Sep 18:29:59Z — get this wrong and a report labelled today
        // quietly shows five and a half hours of yesterday.
        $f = MessageFilters::normalize(['from' => '2026-09-11', 'to' => '2026-09-11', 'tz_offset' => 330]);

        $this->assertSame('2026-09-10 18:30:00', $f['from_at']);
        $this->assertSame('2026-09-11 18:29:59', $f['to_at']);
    }

    public function testUtcOperatorsGetPlainDayBoundaries(): void
    {
        $f = MessageFilters::normalize(['from' => '2026-09-11', 'to' => '2026-09-11']);

        $this->assertSame('2026-09-11 00:00:00', $f['from_at']);
        $this->assertSame('2026-09-11 23:59:59', $f['to_at']);
    }

    public function testABackwardsRangeIsOrderedRatherThanReturningNothing(): void
    {
        $f = MessageFilters::normalize(['from' => '2026-09-30', 'to' => '2026-09-01']);

        $this->assertSame('2026-09-01', $f['from']);
        $this->assertSame('2026-09-30', $f['to']);
    }

    public function testAnImpossibleDateIsIgnoredEntirely(): void
    {
        $f = MessageFilters::normalize(['from' => '2026-02-31', 'to' => 'yesterday']);

        $this->assertArrayNotHasKey('from', $f);
        $this->assertArrayNotHasKey('to', $f);
    }

    public function testAnOversizedRangeIsClampedFromTheRecentEnd(): void
    {
        // One request must stay bounded; keep the end the operator cares about.
        $f = MessageFilters::normalize(['from' => '2020-01-01', 'to' => '2026-09-18']);

        $expected = date('Y-m-d', strtotime('2026-09-18') - MessageFilters::MAX_RANGE_DAYS * 86400);
        $this->assertSame($expected, $f['from']);
        $this->assertSame('2026-09-18', $f['to']);
    }

    public function testRangeLabelNamesTheExportFile(): void
    {
        $this->assertSame('all-time', MessageFilters::rangeLabel([]));
        $this->assertSame('2026-09-11', MessageFilters::rangeLabel(['from' => '2026-09-11', 'to' => '2026-09-11']));
        $this->assertSame(
            '2026-09-01_to_2026-09-11',
            MessageFilters::rangeLabel(['from' => '2026-09-01', 'to' => '2026-09-11'])
        );
    }
}
