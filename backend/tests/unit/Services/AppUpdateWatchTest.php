<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\AppUpdateWatch;
use CodeIgniter\Test\CIUnitTestCase;

/** The rule that spots a broken over-the-air update (pure: no database, no network). */
final class AppUpdateWatchTest extends CIUnitTestCase
{
    private const PUB = 1_792_000_000;
    private function ph(int $firstAgoDays, int $lastTs): array { return ['first' => self::PUB - $firstAgoDays * 86400, 'last' => $lastTs]; }

    public function testAllPhonesReportedSinceIsOk(): void
    {
        $r = AppUpdateWatch::evaluate([$this->ph(10, self::PUB + 3600), $this->ph(5, self::PUB + 7200)], self::PUB, self::PUB + 3 * 86400);
        $this->assertSame('ok', $r['state']);
        $this->assertSame(0, $r['silent']);
    }

    public function testSilencePastADayWithHalfOrMoreSilentWarns(): void
    {
        $r = AppUpdateWatch::evaluate([$this->ph(10, self::PUB - 3600), $this->ph(5, self::PUB - 7200)], self::PUB, self::PUB + 30 * 3600);
        $this->assertSame('warn', $r['state']);
        $this->assertSame(2, $r['silent']);
        $this->assertStringContainsString('eas update:rollback', $r['message']);
    }

    public function testTheFirstDayIsOnlyWatched(): void
    {
        $r = AppUpdateWatch::evaluate([$this->ph(10, self::PUB - 3600)], self::PUB, self::PUB + 5 * 3600);
        $this->assertSame('watching', $r['state']);
    }

    public function testMostPhonesReportingMeansNoAlarm(): void
    {
        $r = AppUpdateWatch::evaluate([$this->ph(10, self::PUB + 100), $this->ph(9, self::PUB + 200), $this->ph(8, self::PUB + 300), $this->ph(7, self::PUB - 100)], self::PUB, self::PUB + 2 * 86400);
        $this->assertSame('ok', $r['state'], '1 of 4 silent is normal (someone on holiday)');
        $this->assertSame(1, $r['silent']);
    }

    public function testPhonesInstalledAfterTheUpdateAndLongGoneOnesAreIgnored(): void
    {
        $new = ['first' => self::PUB + 600, 'last' => self::PUB + 900];
        $old = $this->ph(40, self::PUB - 20 * 86400);            // last seen 20 days before the update: not "using the app" then
        $r = AppUpdateWatch::evaluate([$new, $old], self::PUB, self::PUB + 3 * 86400);
        $this->assertSame(0, $r['before']);
        $this->assertSame('ok', $r['state']);
    }

    public function testStatusUsesTheInjectedFetchAndSurvivesNoAnswer(): void
    {
        $s = AppUpdateWatch::status(static fn () => null);
        $this->assertSame('unknown', $s['state']);
    }
}
