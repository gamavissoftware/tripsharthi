<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\Docs\GstDeadlines;
use App\Services\Billing\Docs\Gstr3b;
use App\Services\Billing\Docs\HsnSummary;
use PHPUnit\Framework\TestCase;

final class Gstr3bTest extends TestCase
{
    private const GSTIN = '27ABCDE1234F1Z0';   // Maharashtra
    private int $n = 0;
    private function z(): array { return ['igst' => 0, 'cgst' => 0, 'sgst' => 0]; }
    private function t(int $i, int $c, int $s): array { return ['igst' => $i, 'cgst' => $c, 'sgst' => $s]; }

    private function doc(array $o = []): array
    {
        $this->n++;
        return array_merge(['id' => $this->n, 'doc_type' => 'tax_invoice', 'status' => 'issued', 'number' => 'INV/26-27/' . $this->n, 'issue_date' => '2026-10-05', 'sac' => '998554', 'gst_rate' => 5, 'place_of_supply' => '27',
            'buyer' => ['name' => 'Rohit', 'gstin' => null], 'taxable_value' => 100_000, 'cgst' => 2_500, 'sgst' => 2_500, 'igst' => 0, 'tcs' => 0, 'total' => 105_000], $o);
    }
    private function inter(array $o = []): array { return $this->doc(array_merge(['place_of_supply' => '29', 'cgst' => 0, 'sgst' => 0, 'igst' => 5_000], $o)); }

    // ---- Rule 88A set-off ---------------------------------------------------------------------------------------------------

    public function testIgstCreditIsUsedFirstThenCgstThenSgst(): void
    {
        $r = Gstr3b::setOff($this->t(100, 50, 50), $this->t(200, 0, 0));
        $this->assertSame($this->z(), $r['cash']);
        $this->assertSame(['igst_by_igst' => 100, 'cgst_by_igst' => 50, 'sgst_by_igst' => 50], array_filter($r['used']));
        $this->assertSame($this->z(), $r['closing']);
        $partial = Gstr3b::setOff($this->t(100, 50, 50), $this->t(60, 0, 0));
        $this->assertSame($this->t(40, 50, 50), $partial['cash']);
    }

    public function testCgstAndSgstCreditsNeverPayEachOthersLiability(): void
    {
        $r = Gstr3b::setOff($this->t(0, 0, 50), $this->t(0, 100, 0));          // CGST credit, SGST liability only
        $this->assertSame($this->t(0, 0, 50), $r['cash']);
        $this->assertSame($this->t(0, 100, 0), $r['closing']);                   // the credit is carried forward untouched
        $r2 = Gstr3b::setOff($this->t(0, 50, 0), $this->t(0, 0, 100));
        $this->assertSame($this->t(0, 50, 0), $r2['cash']);
    }

    public function testCgstAndSgstCreditCanPayIgstOnlyAfterTheirOwnHead(): void
    {
        $r = Gstr3b::setOff($this->t(40, 10, 5), $this->t(0, 30, 20));
        $this->assertSame(['cgst_by_cgst' => 10, 'igst_by_cgst' => 20, 'sgst_by_sgst' => 5, 'igst_by_sgst' => 15], array_filter($r['used']));
        $this->assertSame($this->t(5, 0, 0), $r['cash']);                                    // IGST 40 - 20 (CGST credit) - 15 (SGST credit) = 5 left in cash
        $this->assertSame($this->z(), $r['closing']);
    }

    public function testSetOffConservesMoneyForManyRandomCases(): void
    {
        mt_srand(88);
        for ($i = 0; $i < 300; $i++) {
            $l = $this->t(mt_rand(0, 5000), mt_rand(0, 5000), mt_rand(0, 5000)); $c = $this->t(mt_rand(0, 5000), mt_rand(0, 5000), mt_rand(0, 5000));
            $r = Gstr3b::setOff($l, $c);
            $this->assertSame(array_sum($l), array_sum($r['used']) + array_sum($r['cash']), 'liability must equal ITC used + cash');
            $this->assertSame(array_sum($c), array_sum($r['used']) + array_sum($r['closing']), 'credit must equal used + carried forward');
            foreach (['cash', 'closing'] as $k) { foreach ($r[$k] as $v) { $this->assertGreaterThanOrEqual(0, $v); } }
        }
    }

    // ---- building the return -----------------------------------------------------------------------------------------------------------

    public function testOutwardSuppliesNetOfCreditNotesAndOnlyUnregisteredInterstateGoesIn32(): void
    {
        $docs = [$this->doc(), $this->inter(), $this->inter(['buyer' => ['name' => 'Acme', 'gstin' => '29AAAPL1234C1ZV']]),                  // intra / inter B2C / inter B2B
                 $this->inter(['doc_type' => 'credit_note', 'taxable_value' => 40_000, 'igst' => 2_000, 'total' => 42_000])];                 // credit note on the first inter-state invoice
        $r = Gstr3b::build(self::GSTIN, '2026-10', $docs, $this->z(), $this->z());
        $this->assertSame(260_000, $r['outward']['taxable']['taxable']);                                                                       // 100k + 100k + 100k - 40k
        $this->assertSame(['igst' => 8_000, 'cgst' => 2_500, 'sgst' => 2_500], $r['outward']['liability']);
        $u = $r['outward']['unregistered_interstate'];
        $this->assertCount(1, $u);
        $this->assertSame(['29', 60_000, 3_000], [$u[0]['pos'], $u[0]['taxable'], $u[0]['igst']]);                                              // B2B inter-state is NOT here; the credit note reduces it
        $this->assertSame([3, 1], [$r['counts']['invoices'], $r['counts']['credit_notes']]);
        $this->assertSame(13_000, $r['payment']['cash_total']);
        $this->assertSame('102026', $r['json']['ret_period']);
        $this->assertSame(2600.0, $r['json']['sup_details']['osup_det']['txval']);
        $this->assertSame(80.0, $r['json']['sup_details']['osup_det']['iamt']);
        $this->assertSame([['pos' => '29', 'txval' => 600.0, 'iamt' => 30.0]], $r['json']['inter_sup']['unreg_details']);
    }

    public function testNilRatedBillsOfSupplyReceiptsAndCancelledAreHandledCorrectly(): void
    {
        $docs = [$this->doc(['gst_rate' => 0, 'cgst' => 0, 'sgst' => 0, 'total' => 100_000]), $this->doc(['doc_type' => 'bill_of_supply', 'gst_rate' => 0, 'cgst' => 0, 'sgst' => 0]),
                 $this->doc(['doc_type' => 'receipt']), $this->doc(['status' => 'cancelled']), $this->doc()];
        $r = Gstr3b::build(self::GSTIN, '2026-10', $docs, $this->z(), $this->z());
        $this->assertSame(200_000, $r['outward']['nil_exempt']);                                // 3.1(c): the 0% invoice + the bill of supply
        $this->assertSame(100_000, $r['outward']['taxable']['taxable']);                       // only the 5% invoice is 3.1(a)
        $this->assertSame(2_000.0, $r['json']['sup_details']['osup_nil_exmp']['txval']);
        $this->assertSame(2, $r['counts']['skipped']);                                          // receipt + cancelled
    }

    public function testItcIsSetOffAndTheBalanceCarriesForward(): void
    {
        $docs = [$this->doc(['gst_rate' => 18, 'taxable_value' => 1_000_000, 'cgst' => 90_000, 'sgst' => 90_000, 'total' => 1_180_000])];
        $r = Gstr3b::build(self::GSTIN, '2026-10', $docs, $this->t(30_000, 100_000, 20_000), $this->t(0, 0, 5_000), $this->t(0, 10_000, 0));
        $this->assertSame($this->t(30_000, 90_000, 25_000), $r['itc']['available']);          // IGST 30k, CGST 100k-10k reversed, SGST 20k+5k brought forward
        $this->assertSame(['igst' => 0, 'cgst' => 0, 'sgst' => 65_000], $r['payment']['cash']);   // 30k IGST credit pays CGST 30k, then CGST credit 90k clears CGST 60k and the rest... SGST pays from SGST credit only
        $this->assertSame(array_sum($r['payment']['liability']), array_sum($r['payment']['by_itc']) + $r['payment']['cash_total']);
        $this->assertSame(['iamt' => 300.0, 'camt' => 900.0, 'samt' => 200.0, 'csamt' => 0.0], $r['json']['itc_elg']['itc_net']);          // net of the reversal, before brought-forward balance
        $this->assertSame($this->t(0, 30_000, 0), $r['payment']['closing_credit']);           // unused CGST credit carries to next month
    }

    public function testCreditNotesLargerThanTheMonthFloorTheLiabilityAtZeroAndWarn(): void
    {
        $docs = [$this->doc(), $this->doc(['doc_type' => 'credit_note', 'taxable_value' => 300_000, 'cgst' => 7_500, 'sgst' => 7_500, 'total' => 315_000])];
        $r = Gstr3b::build(self::GSTIN, '2026-10', $docs, $this->z(), $this->z());
        $this->assertSame($this->z(), $r['payment']['liability']);
        $this->assertStringContainsString('exceed the tax', implode(' ', $r['warnings']));
        $this->assertSame(0, $r['payment']['cash_total']);
    }

    public function testItcSanityWarnings(): void
    {
        $five = [$this->doc()];
        $this->assertStringContainsString('5%', implode(' ', Gstr3b::build(self::GSTIN, '2026-10', $five, $this->t(0, 1_000, 1_000), $this->z())['warnings']));
        $this->assertStringNotContainsString('5% do not allow', implode(' ', Gstr3b::build(self::GSTIN, '2026-10', $five, $this->z(), $this->z())['warnings']));
        $eighteen = [$this->doc(['gst_rate' => 18, 'cgst' => 9_000, 'sgst' => 9_000, 'total' => 118_000])];
        $this->assertStringContainsString('entered no input tax credit', implode(' ', Gstr3b::build(self::GSTIN, '2026-10', $eighteen, $this->z(), $this->z())['warnings']));
    }

    // ---- HSN ----------------------------------------------------------------------------------------------------------------------------

    public function testHsnGroupsBySacAndRateAndCreditNotesReduceTheRow(): void
    {
        $docs = [$this->doc(), $this->doc(), $this->doc(['gst_rate' => 18, 'cgst' => 9_000, 'sgst' => 9_000, 'total' => 118_000]), $this->doc(['sac' => '9985', 'taxable_value' => 50_000, 'cgst' => 1_250, 'sgst' => 1_250, 'total' => 52_500]),
                 $this->doc(['doc_type' => 'credit_note', 'taxable_value' => 30_000, 'cgst' => 750, 'sgst' => 750, 'total' => 31_500]), $this->doc(['doc_type' => 'receipt']), $this->doc(['status' => 'cancelled'])];
        $rows = HsnSummary::build($docs);
        $this->assertCount(3, $rows);                                                           // 998554@5, 998554@18, 9985@5
        $r5 = array_values(array_filter($rows, fn ($r) => $r['hsn'] === '998554' && $r['rate'] === 5.0))[0];
        $this->assertSame([170_000, 4_250, 4_250, 3], [$r5['taxable'], $r5['cgst'], $r5['sgst'], $r5['docs']]);   // 2 invoices - 1 credit note
        $this->assertSame('NA', $r5['uqc']);
        $json = HsnSummary::json($rows);
        $this->assertSame(['num', 'hsn_sc', 'desc', 'uqc', 'qty', 'rt', 'val', 'txval', 'iamt', 'camt', 'samt', 'csamt'], array_keys($json['data'][0]));
        $four = HsnSummary::build([$this->doc()], '998554', 4);
        $this->assertSame('9985', $four[0]['hsn']);                                             // the 4-digit rule for smaller taxpayers
        $this->assertSame('998554', HsnSummary::build([$this->doc(['sac' => null])])[0]['hsn']);    // missing SAC falls back to the profile default
        $this->assertStringContainsString("HSN/SAC", HsnSummary::csv($rows));
    }

    // ---- deadlines ----------------------------------------------------------------------------------------------------------------------

    public function testDueDatesAndStatus(): void
    {
        $this->assertSame('2026-11-11', GstDeadlines::due('2026-10', 'GSTR1'));
        $this->assertSame('2026-11-20', GstDeadlines::due('2026-10', 'GSTR3B'));
        $this->assertSame('2027-01-20', GstDeadlines::due('2026-12', 'GSTR3B'));                // year rollover
        $this->assertSame(['upcoming', 12], array_values(array_intersect_key(GstDeadlines::status('2026-10', 'GSTR3B', null, '2026-11-08'), ['status' => 1, 'days' => 1])));
        $this->assertSame('due_soon', GstDeadlines::status('2026-10', 'GSTR3B', null, '2026-11-18')['status']);
        $this->assertSame('due_soon', GstDeadlines::status('2026-10', 'GSTR3B', null, '2026-11-20')['status']);   // the due date itself is not late
        $late = GstDeadlines::status('2026-10', 'GSTR3B', null, '2026-11-25');
        $this->assertSame(['overdue', 5], [$late['status'], $late['days']]);
        $ok = GstDeadlines::status('2026-10', 'GSTR3B', '2026-11-22', '2026-12-30');
        $this->assertSame(['filed', 2], [$ok['status'], $ok['days']]);                           // filed, but 2 days late
        $this->assertSame(0, GstDeadlines::status('2026-10', 'GSTR1', '2026-11-05', '2026-12-30')['days']);
    }
}
