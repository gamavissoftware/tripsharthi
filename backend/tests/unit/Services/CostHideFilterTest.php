<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Filters\CostHideFilter;
use CodeIgniter\Test\CIUnitTestCase;

/** What an agent must never read: supplier cost, margin and supplier payments (the pure stripping rules). */
final class CostHideFilterTest extends CIUnitTestCase
{
    public function testItineraryCostAndMarginAreRemovedButPricesStay(): void
    {
        $it = ['id' => 1, 'sell_subtotal' => 100000, 'gst_amount' => 5000, 'grand_total' => 105000, 'markup_type' => 'percent', 'markup_value' => 15, 'discount_amount' => 0,
            'cost_total' => 80000, 'margin_amount' => 20000,
            'days' => [['id' => 1, 'items' => [['id' => 9, 'title' => 'Villa', 'unit_cost' => 40000, 'cost_amount' => 80000, 'cost_currency' => 'USD', 'unit_cost_fx' => 500, 'quantity' => 2]]]]];
        $out = CostHideFilter::strip($it);
        foreach (['cost_total', 'margin_amount'] as $k) { $this->assertArrayNotHasKey($k, $out); }
        $item = $out['days'][0]['items'][0];
        foreach (['unit_cost', 'cost_amount', 'cost_currency', 'unit_cost_fx'] as $k) { $this->assertArrayNotHasKey($k, $item); }
        $this->assertSame('Villa', $item['title']);
        $this->assertSame(105000, $out['grand_total']);
        $this->assertSame(15, $out['markup_value'], 'agents quote with the markup, so it stays');
    }

    public function testBookingKeepsWhatTheCustomerPaidButHidesWhatWePaidSuppliers(): void
    {
        $b = ['booking_ref' => 'TP-1', 'total_amount' => 500000, 'paid_amount' => 200000, 'cost_total' => 400000, 'supplier_paid' => 100000,
            'payments' => [['id' => 1, 'amount' => 200000, 'status' => 'paid']],
            'services' => [['id' => 3, 'title' => 'Hotel', 'cost_amount' => 300000, 'paid_amount' => 100000, 'supplier_id' => 7, 'pay_by' => '2026-11-01', 'status' => 'confirmed']]];
        $out = CostHideFilter::strip($b);
        $this->assertSame(200000, $out['paid_amount'], 'the CUSTOMER payment on the booking stays');
        $this->assertArrayNotHasKey('cost_total', $out);
        $this->assertArrayNotHasKey('supplier_paid', $out);
        $svc = $out['services'][0];
        $this->assertSame(['id' => 3, 'title' => 'Hotel', 'status' => 'confirmed'], $svc, 'a service shows what it is and its status, nothing about money to suppliers');
        $this->assertSame(200000, $out['payments'][0]['amount']);
    }

    public function testDashboardMarginAndRateCardCostsAreRemoved(): void
    {
        $out = CostHideFilter::strip(['receivables' => ['overdue' => 5], 'totals' => ['n' => 4, 'revenue' => 900, 'gross_margin' => 120, 'collected' => 700],
            'rates' => [['name' => 'Ubud villa', 'cost_amount' => 800000, 'cost_currency' => 'INR', 'unit' => 'per_night']]]);
        $this->assertArrayNotHasKey('gross_margin', $out['totals']);
        $this->assertSame(900, $out['totals']['revenue']);
        $this->assertSame([['name' => 'Ubud villa', 'unit' => 'per_night']], $out['rates']);
    }

    public function testListsAndNonCostDataPassThroughUnchanged(): void
    {
        $data = [['id' => 1, 'title' => 'Bali'], ['id' => 2, 'title' => 'Goa']];
        $this->assertSame($data, CostHideFilter::strip($data));
        $this->assertSame(['paid_amount' => 5, 'services' => []], CostHideFilter::strip(['paid_amount' => 5, 'services' => []]));
    }
}
