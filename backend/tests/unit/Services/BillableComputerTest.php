<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WhatsApp\BillableComputer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Exhaustive unit tests for BillableComputer.
 *
 * Tests every cell of the 8-cell truth table (category × window state)
 * plus edge cases. No DB required — BillableComputer is pure logic.
 */
class BillableComputerTest extends CIUnitTestCase
{
    // ------------------------------------------------------------------
    // Marketing — always billable regardless of window
    // ------------------------------------------------------------------

    public function testMarketingWindowOpenIsBillable(): void
    {
        $this->assertSame(1, BillableComputer::compute('marketing', true));
    }

    public function testMarketingWindowClosedIsBillable(): void
    {
        $this->assertSame(1, BillableComputer::compute('marketing', false));
    }

    // ------------------------------------------------------------------
    // Authentication — always billable regardless of window
    // ------------------------------------------------------------------

    public function testAuthenticationWindowOpenIsBillable(): void
    {
        $this->assertSame(1, BillableComputer::compute('authentication', true));
    }

    public function testAuthenticationWindowClosedIsBillable(): void
    {
        $this->assertSame(1, BillableComputer::compute('authentication', false));
    }

    // ------------------------------------------------------------------
    // Utility — free inside open window; paid outside
    // ------------------------------------------------------------------

    public function testUtilityWindowOpenIsFree(): void
    {
        $this->assertSame(0, BillableComputer::compute('utility', true));
    }

    public function testUtilityWindowClosedIsBillable(): void
    {
        $this->assertSame(1, BillableComputer::compute('utility', false));
    }

    // ------------------------------------------------------------------
    // Free-form / service — only sent inside window; always free
    // ------------------------------------------------------------------

    public function testFreeFormWindowOpenIsFree(): void
    {
        $this->assertSame(0, BillableComputer::compute('free_form', true));
    }

    public function testFreeFormWindowClosedIsFree(): void
    {
        // Free-form can never be sent when window is closed (blocked by WindowService),
        // but BillableComputer must still return 0 defensively.
        $this->assertSame(0, BillableComputer::compute('free_form', false));
    }

    public function testServiceAliasWindowOpenIsFree(): void
    {
        $this->assertSame(0, BillableComputer::compute('service', true));
    }

    public function testServiceAliasWindowClosedIsFree(): void
    {
        $this->assertSame(0, BillableComputer::compute('service', false));
    }

    // ------------------------------------------------------------------
    // Default / unknown category — safe default = billable
    // ------------------------------------------------------------------

    public function testUnknownCategoryIsBillable(): void
    {
        $this->assertSame(1, BillableComputer::compute('unknown_future_category', true));
        $this->assertSame(1, BillableComputer::compute('unknown_future_category', false));
    }

    public function testEmptyCategoryIsBillable(): void
    {
        $this->assertSame(1, BillableComputer::compute('', true));
        $this->assertSame(1, BillableComputer::compute('', false));
    }

    // ------------------------------------------------------------------
    // Return type is strictly int (not bool)
    // ------------------------------------------------------------------

    public function testReturnsIntNotBool(): void
    {
        $result = BillableComputer::compute('marketing', true);
        $this->assertIsInt($result);
        $this->assertNotSame(true,  $result, 'Must be int 1, not bool true');
        $this->assertNotSame(false, $result, 'Must be int 0, not bool false');
    }
}
