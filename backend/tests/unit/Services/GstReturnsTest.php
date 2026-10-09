<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\Docs\GstReturns;
use CodeIgniter\Test\CIUnitTestCase;

final class GstReturnsTest extends CIUnitTestCase
{
    private const SELLER = '27ABCDE1234F1Z0';   // Maharashtra (27)
    private int $n = 0;

    private function doc(array $o = []): array
    {
        $this->n++;
        $o = $o + ['id' => $this->n, 'doc_type' => 'tax_invoice', 'number' => 'INV/26-27/' . str_pad((string) $this->n, 5, '0', STR_PAD_LEFT), 'seq' => $this->n, 'issue_date' => '2026-10-05', 'status' => 'issued',
            'buyer' => ['name' => 'Rohit', 'gstin' => null], 'place_of_supply' => '27', 'supply_type' => 'intra', 'sac' => '998554', 'gst_rate' => 5, 'related_id' => null, 'reason' => null,
            'taxable_value' => 100_000, 'cgst' => 2_500, 'sgst' => 2_500, 'igst' => 0, 'tcs' => 0, 'total' => 105_000];
        return $o;
    }

    private function inter(array $o = []): array { return $this->doc($o + ['place_of_supply' => '29', 'supply_type' => 'inter', 'cgst' => 0, 'sgst' => 0, 'igst' => 5_000]); }

    public function testSectionsAreAssignedByBuyerAndValue(): void
    {
        $docs = [
            $this->doc(['buyer' => ['name' => 'Acme', 'gstin' => '29AAAPL1234C1ZV']]),                       // B2B (GSTIN not checksum-valid -> warning but still B2B)
            $this->inter(['taxable_value' => 30_000_000, 'igst' => 1_500_000, 'total' => 31_500_000]),      // B2CL (inter, > 2.5L)
            $this->inter(),                                                                                  // inter but small -> B2CS
            $this->doc(),                                                                                    // intra -> B2CS
            $this->doc(['taxable_value' => 30_000_000, 'cgst' => 750_000, 'sgst' => 750_000, 'total' => 31_500_000]), // intra big -> B2CS (B2CL needs inter-state)
        ];
        $r = GstReturns::gstr1(self::SELLER, '2026-10', $docs);
        $this->assertSame(1, $r['summary']['sections']['b2b']);
        $this->assertSame(1, $r['summary']['sections']['b2cl']);
        $this->assertSame(2, $r['summary']['sections']['b2cs_rows']);   // INTRA/27 and INTER/29 at 5%
        $b2cs = array_column($r['json']['b2cs'], null, 'sply_ty');
        $this->assertEqualsWithDelta(1_000 + 300_000, $b2cs['INTRA']['txval'], 0.001);
        $this->assertSame('102026', $r['json']['fp']);
    }

    public function testCreditNoteAgainstSmallB2cReducesB2csAndBigOneGoesToCdnur(): void
    {
        $small = $this->doc();
        $big = $this->inter(['taxable_value' => 30_000_000, 'igst' => 1_500_000, 'total' => 31_500_000]);
        $cnSmall = $this->doc(['doc_type' => 'credit_note', 'number' => 'CN/26-27/00001', 'seq' => 1, 'related_id' => $small['id'], 'taxable_value' => 100_000, 'cgst' => 2_500, 'sgst' => 2_500, 'total' => 105_000]);
        $cnBig = $this->inter(['doc_type' => 'credit_note', 'number' => 'CN/26-27/00002', 'seq' => 2, 'related_id' => $big['id'], 'taxable_value' => 30_000_000, 'igst' => 1_500_000, 'total' => 31_500_000]);
        $r = GstReturns::gstr1(self::SELLER, '2026-10', [$small, $big, $cnSmall, $cnBig]);
        $intra = array_values(array_filter($r['json']['b2cs'] ?? [], fn ($x) => $x['sply_ty'] === 'INTRA'));
        $this->assertEqualsWithDelta(0.0, $intra[0]['txval'], 0.001);          // fully netted
        $this->assertCount(1, $r['json']['cdnur']);
        $this->assertSame('B2CL', $r['json']['cdnur'][0]['typ']);
        $this->assertSame(0, $r['summary']['net']['total']);
    }

    public function testCreditNoteToRegisteredBuyerIsCdnr(): void
    {
        $inv = $this->doc(['buyer' => ['name' => 'Acme', 'gstin' => '27AAAPL1234C1ZV']]);
        $cn = $this->doc(['doc_type' => 'credit_note', 'number' => 'CN/26-27/00001', 'seq' => 1, 'related_id' => $inv['id'], 'buyer' => $inv['buyer']]);
        $r = GstReturns::gstr1(self::SELLER, '2026-10', [$inv, $cn]);
        $this->assertSame('27AAAPL1234C1ZV', $r['json']['cdnr'][0]['ctin']);
        $this->assertSame('C', $r['json']['cdnr'][0]['nt'][0]['ntty']);
        $this->assertSame('05-10-2026', $r['json']['cdnr'][0]['nt'][0]['nt_dt']);
    }

    public function testReceiptsBillsOfSupplyAndCancelledAreNotReported(): void
    {
        $docs = [$this->doc(), $this->doc(['doc_type' => 'receipt']), $this->doc(['doc_type' => 'bill_of_supply']), $this->doc(['status' => 'cancelled'])];
        $r = GstReturns::gstr1(self::SELLER, '2026-10', $docs);
        $this->assertSame(1, $r['summary']['invoices']['count']);
        $this->assertSame(['bill_of_supply' => 1, 'receipt' => 1, 'cancelled' => 1], $r['summary']['skipped']);
    }

    public function testWarnsOnMathMismatchBadGstinAndNumberingGap(): void
    {
        $a = $this->doc(['seq' => 1]);
        $b = $this->doc(['seq' => 3, 'total' => 999, 'buyer' => ['name' => 'X', 'gstin' => '27AAAPL1234C1ZZ']]);
        $w = implode(' | ', GstReturns::gstr1(self::SELLER, '2026-10', [$a, $b])['warnings']);
        $this->assertStringContainsString('does not equal the total', $w);
        $this->assertStringContainsString('not valid', $w);
        $this->assertStringContainsString('numbering has a gap', $w);
    }

    public function testDocIssueSummaryCountsRangesAndCancellations(): void
    {
        $docs = [$this->doc(['seq' => 1, 'number' => 'INV/26-27/00001']), $this->doc(['seq' => 2, 'number' => 'INV/26-27/00002', 'status' => 'cancelled'])];
        $d = GstReturns::gstr1(self::SELLER, '2026-10', $docs)['json']['doc_issue']['doc_det'][0]['docs'][0];
        $this->assertSame(['INV/26-27/00001', 'INV/26-27/00002', 2, 1, 1], [$d['from'], $d['to'], $d['totnum'], $d['cancel'], $d['net_issue']]);
    }

    public function testRegisterCsvNegatesCreditNotesAndBlocksFormulaInjection(): void
    {
        $inv = $this->doc(['buyer' => ['name' => '=HYPERLINK("http://x")', 'gstin' => null]]);
        $cn = $this->doc(['doc_type' => 'credit_note', 'number' => 'CN/26-27/00001', 'related_id' => $inv['id'], '_against' => $inv['number']]);
        $csv = GstReturns::registerCsv([$inv, $cn]);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('-1050.00', $csv);
        $this->assertStringContainsString('1050.00', $csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function testTallyXmlIsWellFormedBalancedAndEscaped(): void
    {
        $inv = $this->inter(['buyer' => ['name' => 'Ravi & <Sons>', 'gstin' => null], 'tcs' => 2_000, 'total' => 107_000]);
        $cn = $this->doc(['doc_type' => 'credit_note', 'number' => 'CN/26-27/00001', 'reason' => 'Cancelled']);
        $xml = GstReturns::tallyXml('Demo & Co', [$inv, $cn]);
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'XML must parse');
        foreach ($dom->getElementsByTagName('VOUCHER') as $v) {
            $sum = 0.0;
            foreach ($v->getElementsByTagName('AMOUNT') as $a) { $sum += (float) $a->nodeValue; }
            $this->assertEqualsWithDelta(0.0, $sum, 0.001, 'every voucher must balance to zero');
        }
        $this->assertStringContainsString('Ravi &amp; &lt;Sons&gt;', $xml);
        $this->assertStringContainsString('<VOUCHERTYPENAME>Credit Note</VOUCHERTYPENAME>', $xml);
        $this->assertStringContainsString('Output IGST', $xml);
        $this->assertStringContainsString('TCS Payable', $xml);
    }
}
