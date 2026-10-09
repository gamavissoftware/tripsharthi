<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Flow\DateTriggerService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests the pure date-matching logic for birthday / date-based triggers.
 */
class DateTriggerServiceTest extends CIUnitTestCase
{
    private function d(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd . ' 00:00:00 UTC');
    }

    public function testRecurringMatchesMonthDayAcrossYears(): void
    {
        // Birthday stored in 1990 still matches the same month/day this year.
        $this->assertTrue(DateTriggerService::matchesDate('1990-06-10', $this->d('2026-06-10'), true));
    }

    public function testRecurringRejectsDifferentDay(): void
    {
        $this->assertFalse(DateTriggerService::matchesDate('1990-06-11', $this->d('2026-06-10'), true));
    }

    public function testNonRecurringRequiresFullDate(): void
    {
        $this->assertTrue(DateTriggerService::matchesDate('2026-06-10', $this->d('2026-06-10'), false));
        $this->assertFalse(DateTriggerService::matchesDate('1990-06-10', $this->d('2026-06-10'), false));
    }

    public function testAcceptsCommonDateFormats(): void
    {
        $this->assertTrue(DateTriggerService::matchesDate('10 June 1992', $this->d('2026-06-10'), true));
        $this->assertTrue(DateTriggerService::matchesDate('2000-06-10T00:00:00', $this->d('2026-06-10'), true));
    }

    public function testRejectsEmptyOrBadInput(): void
    {
        $this->assertFalse(DateTriggerService::matchesDate('', $this->d('2026-06-10'), true));
        $this->assertFalse(DateTriggerService::matchesDate('not-a-date', $this->d('2026-06-10'), true));
    }
}
