<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\InvoiceModel;
use App\Services\Billing\Docs\BusinessProfileService;
use App\Services\Billing\Docs\Gst;
use App\Services\Billing\Docs\InvoiceService;
use App\Services\Billing\Docs\QuoteDocument;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class InvoiceServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    // 2026-10-08
    private const NOW = 1_791_439_200;
    private const GSTIN = '27ABCDE1234F1Z0';   // Maharashtra

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['invoices', 'einvoice_settings', 'doc_sequences', 'business_profiles', 'booking_payments', 'booking_services', 'bookings', 'travelers', 'itinerary_items', 'itinerary_days', 'itineraries', 'trips', 'contacts', 'suppliers', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'Demo Travels', 'slug' => 'd', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Rohit Sharma', 'wa_number' => '+919876543210', 'email' => 'r@x.test', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'status' => 'new', 'source' => 'manual', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $this->profile();
        // Isolated storage: tests must NEVER read or delete the folder real invoices live in.
        putenv('INVOICE_STORAGE_DIR=uploads/invoices-test'); $_ENV['INVOICE_STORAGE_DIR'] = 'uploads/invoices-test';
        $this->wipeStorage();
    }

    protected function tearDown(): void
    {
        $this->wipeStorage();
        putenv('INVOICE_STORAGE_DIR'); unset($_ENV['INVOICE_STORAGE_DIR']);
        parent::tearDown();
    }

    private function wipeStorage(): void
    {
        $root = WRITEPATH . 'uploads/invoices-test';
        foreach (glob($root . '/*/*/*') ?: [] as $f) { @unlink($f); }
        foreach (glob($root . '/*/*') ?: [] as $d) { @rmdir($d); }
        foreach (glob($root . '/*') ?: [] as $d) { is_dir($d) ? @rmdir($d) : @unlink($d); }
        @rmdir($root);
    }

    private function svc(): InvoiceService { return new InvoiceService(self::NOW, false); }

    private function profile(array $o = []): void
    {
        (new BusinessProfileService())->save(1, $o + ['legal_name' => 'Demo Travels Private Limited', 'trade_name' => 'Demo Travels', 'gstin' => self::GSTIN, 'address_line1' => 'Shop 12', 'city' => 'Mumbai', 'pincode' => '400093',
            'phone' => '+919820011111', 'email' => 'hello@demo.in', 'bank_name' => 'HDFC', 'bank_account_no' => '123456789', 'bank_ifsc' => 'HDFC0001234', 'upi_id' => 'demo@okhdfcbank', 'signatory' => 'Anita Rao']);
    }

    /** subtotal ₹48,300; GST 5% ₹2,415; TCS 2% ₹1,014.30; total ₹51,729.30 */
    private function booking(array $o = []): int
    {
        $db = db_connect();
        $db->table('trips')->insert(['tenant_id' => 1, 'contact_id' => 1, 'title' => 'Bali honeymoon', 'destination_text' => 'Bali', 'is_international' => 1, 'adults' => 2, 'children' => 0, 'start_date' => '2027-01-20', 'end_date' => '2027-01-26', 'status' => 'booked', 'created_at' => '2026-09-01 00:00:00']);
        $trip = (int) $db->insertID();
        $db->table('itineraries')->insert(['tenant_id' => 1, 'trip_id' => $trip, 'title' => 'Bali honeymoon', 'status' => 'accepted', 'gst_rate' => 5, 'tcs_rate' => 2, 'sell_subtotal' => 4_830_000, 'gst_amount' => 241_500, 'tcs_amount' => 101_430, 'grand_total' => 5_172_930,
            'share_token' => bin2hex(random_bytes(16)), 'nights' => 6, 'adults' => 2, 'children' => 0, 'created_at' => '2026-09-01 00:00:00']);
        $it = (int) $db->insertID();
        $db->table('bookings')->insert($o + ['tenant_id' => 1, 'trip_id' => $trip, 'itinerary_id' => $it, 'contact_id' => 1, 'booking_ref' => 'TP-2026-' . random_int(1000, 9999), 'title' => 'Bali honeymoon', 'status' => 'confirmed',
            'travel_start' => '2027-01-20', 'travel_end' => '2027-01-26', 'is_international' => 1, 'subtotal' => 4_830_000, 'gst_amount' => 241_500, 'tcs_amount' => 101_430, 'total_amount' => 5_172_930, 'paid_amount' => 1_551_879, 'created_at' => '2026-09-02 00:00:00']);
        return (int) $db->insertID();
    }

    private function text(string $pdf): ?string
    {
        if (! trim((string) @shell_exec('command -v pdftotext'))) { return null; }
        $f = tempnam(sys_get_temp_dir(), 'tp') . '.pdf'; file_put_contents($f, $pdf);
        $t = (string) shell_exec('pdftotext ' . escapeshellarg($f) . ' -'); @unlink($f);   // reading order (not -layout, which interleaves columns)
        return preg_replace('/\s+/', ' ', $t);
    }

    // ---- tax maths & content -------------------------------------------------------------------------------

    public function testInterStateInvoiceUsesIgstAndMatchesTheBookingToThePaisa(): void
    {
        $inv = $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Karnataka', 'city' => 'Bengaluru', 'pincode' => '560034']);
        $this->assertSame('tax_invoice', $inv['doc_type']);
        $this->assertSame('INV/26-27/00001', $inv['number']);
        $this->assertSame('inter', $inv['supply_type']);
        $this->assertSame('29', $inv['place_of_supply']);
        $this->assertSame([0, 0, 241_500], [(int) $inv['cgst'], (int) $inv['sgst'], (int) $inv['igst']]);
        $this->assertSame(5_172_930, (int) $inv['total']);
        $this->assertSame('998554', $inv['sac']);

        $pdf = $this->svc()->pdf($inv);
        $this->assertStringStartsWith('%PDF-', $pdf);
        if (($t = $this->text($pdf)) !== null) {
            foreach (['TAX INVOICE', 'INV/26-27/00001', self::GSTIN, 'IGST', 'Karnataka (29)', '998554', '51,729.30', 'Rupees Fifty One Thousand Seven Hundred Twenty Nine and Thirty Paise Only', 'TCS @ 2%', 'Unregistered'] as $needle) {
                $this->assertStringContainsString($needle, $t, $needle);
            }
            $this->assertStringNotContainsString('CGST', $t);
        }
    }

    public function testIntraStateInvoiceSplitsCgstSgstAndBuyerGstinFixesPlaceOfSupply(): void
    {
        $inv = $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Maharashtra']);
        $this->assertSame('intra', $inv['supply_type']);
        $this->assertSame([120_750, 120_750, 0], [(int) $inv['cgst'], (int) $inv['sgst'], (int) $inv['igst']]);

        $b2bGstin = '29ABCDE1234F1Z' . Gst::checkChar('29ABCDE1234F1Z');
        $inv2 = $this->svc()->issueForBooking(1, $this->booking(), ['name' => 'Acme Pvt Ltd', 'gstin' => $b2bGstin]);   // no state given: GSTIN decides
        $this->assertSame('29', $inv2['place_of_supply']);
        $this->assertSame('inter', $inv2['supply_type']);
        $this->assertSame($b2bGstin, json_decode($inv2['buyer'], true)['gstin']);
    }

    public function testBuyerValidation(): void
    {
        $b = $this->booking();
        foreach ([['gstin' => '29ABCDE1234F1Z9'], ['state' => 'Atlantis'], ['pincode' => '12'], ['name' => ' ', 'state' => 'Kerala']] as $bad) {
            try { $this->svc()->issueForBooking(1, $b, $bad + ['name' => 'X']); $this->fail('accepted ' . json_encode($bad)); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(0, db_connect()->table('invoices')->countAllResults());   // nothing issued, no number consumed
        $this->assertSame(0, db_connect()->table('doc_sequences')->countAllResults());
    }

    // ---- numbering -------------------------------------------------------------------------------------------------

    public function testNumberingIsSequentialPerSeriesAndFinancialYear(): void
    {
        $a = $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Kerala']);
        $b = $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Kerala']);
        $this->assertSame(['INV/26-27/00001', 'INV/26-27/00002'], [$a['number'], $b['number']]);
        $cn = $this->svc()->creditNote(1, (int) $a['id'], ['reason' => 'Test', 'full' => true]);
        $this->assertSame('CN/26-27/00001', $cn['number']);                          // separate series
        $next = new InvoiceService(strtotime('2027-04-02 10:00:00 UTC'), false);     // new financial year restarts the count
        $c = $next->issueForBooking(1, $this->booking(), ['state' => 'Kerala']);
        $this->assertSame('INV/27-28/00001', $c['number']);
    }

    public function testAFailureAfterTheNumberIsTakenRollsTheNumberBack(): void
    {
        $dir = WRITEPATH . 'uploads/invoices-test/1';
        if (is_dir($dir)) { @rmdir($dir); }
        @mkdir(dirname($dir), 0755, true);
        file_put_contents($dir, 'a FILE where the folder should be');               // makes storage fail AFTER the sequence was incremented
        try { $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Kerala']); $this->fail('should fail'); }
        catch (\RuntimeException) { $this->addToAssertionCount(1); }
        $this->assertSame(0, db_connect()->table('invoices')->countAllResults());
        unlink($dir);

        $ok = $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Kerala']);
        $this->assertSame('INV/26-27/00001', $ok['number']);                          // gap-free: the failed attempt left no hole
    }

    // ---- one live invoice, bill of supply ------------------------------------------------------------------------------

    public function testABookingCanHaveOnlyOneLiveInvoiceUntilItIsFullyCredited(): void
    {
        $b = $this->booking();
        $inv = $this->svc()->issueForBooking(1, $b, ['state' => 'Kerala']);
        try { $this->svc()->issueForBooking(1, $b, ['state' => 'Kerala']); $this->fail('duplicate'); }
        catch (\DomainException $e) { $this->assertStringContainsString($inv['number'], $e->getMessage()); }

        $this->svc()->creditNote(1, (int) $inv['id'], ['reason' => 'Booking cancelled', 'full' => true]);
        $again = $this->svc()->issueForBooking(1, $b, ['state' => 'Kerala']);       // fully credited -> may be re-invoiced
        $this->assertSame('INV/26-27/00002', $again['number']);
    }

    public function testWithoutAGstinABillOfSupplyIsIssuedAndNeverForAGstBearingBooking(): void
    {
        $db = db_connect();
        $db->table('business_profiles')->where('tenant_id', 1)->update(['gstin' => null]);
        try { $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Kerala']); $this->fail('GST-bearing booking with no GSTIN'); }
        catch (\DomainException $e) { $this->assertStringContainsString('no GSTIN', $e->getMessage()); }

        $b = $this->booking(['subtotal' => 4_830_000, 'gst_amount' => 0, 'tcs_amount' => 101_430, 'total_amount' => 4_931_430]);
        $inv = $this->svc()->issueForBooking(1, $b, ['state' => 'Kerala']);
        $this->assertSame('bill_of_supply', $inv['doc_type']);
        $this->assertSame([0, 0, 0], [(int) $inv['cgst'], (int) $inv['sgst'], (int) $inv['igst']]);
        $this->assertSame(4_931_430, (int) $inv['total']);
        if (($t = $this->text($this->svc()->pdf($inv))) !== null) {
            $this->assertStringContainsString('BILL OF SUPPLY', $t);
            $this->assertStringNotContainsString('IGST', $t);
            $this->assertStringNotContainsString('CGST', $t);
        }
    }

    public function testACreditNoteAgainstABillOfSupplyCarriesNoTaxColumnsOrGstDeclaration(): void
    {
        db_connect()->table('business_profiles')->where('tenant_id', 1)->update(['gstin' => null]);
        $b = $this->booking(['subtotal' => 4_830_000, 'gst_amount' => 0, 'tcs_amount' => 0, 'total_amount' => 4_830_000]);
        $inv = $this->svc()->issueForBooking(1, $b, ['state' => 'Kerala']);
        $this->assertSame('bill_of_supply', $inv['doc_type']);

        $cn = $this->svc()->creditNote(1, (int) $inv['id'], ['reason' => 'One traveller dropped out', 'taxable_value' => 350_000]);
        $this->assertSame('credit_note', $cn['doc_type']);
        $this->assertSame([0, 0, 0], [(int) $cn['cgst'], (int) $cn['sgst'], (int) $cn['igst']]);
        $this->assertSame(350_000, (int) $cn['total']);
        if (($t = $this->text($this->svc()->pdf($cn))) !== null) {
            $this->assertStringContainsString('CREDIT NOTE', $t);
            $this->assertStringContainsString($inv['number'], $t);
            foreach (['IGST', 'CGST', 'SGST', 'GST charged', 'Place of supply'] as $nope) {
                $this->assertStringNotContainsString($nope, $t, "a credit note against a bill of supply must not print '{$nope}'");
            }
            $this->assertStringContainsString('not registered under GST', $t);
        }
    }

    public function testIncompleteProfileAndMismatchedTotalsAreRefused(): void
    {
        db_connect()->table('business_profiles')->where('tenant_id', 1)->update(['address_line1' => null, 'pincode' => null]);
        try { $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Kerala']); $this->fail('profile incomplete'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Address', $e->getMessage()); $this->assertStringContainsString('PIN code', $e->getMessage()); }

        $this->profile(['address_line1' => 'Shop 12', 'pincode' => '400093']);
        $b = $this->booking(['total_amount' => 5_000_000]);                          // inconsistent with subtotal + GST + TCS
        $this->expectException(\DomainException::class);
        $this->svc()->issueForBooking(1, $b, ['state' => 'Kerala']);
    }

    // ---- credit notes ---------------------------------------------------------------------------------------------------

    public function testCreditNotesNeverExceedTheInvoiceAndNetToExactlyZero(): void
    {
        $inv = $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Maharashtra']);   // intra: CGST+SGST
        $c1 = $this->svc()->creditNote(1, (int) $inv['id'], ['reason' => 'Partial', 'taxable_value' => 1_234_567]);
        $c2 = $this->svc()->creditNote(1, (int) $inv['id'], ['reason' => 'Partial 2', 'taxable_value' => 1_000_001]);

        try { $this->svc()->creditNote(1, (int) $inv['id'], ['reason' => 'Too much', 'taxable_value' => 4_830_000]); $this->fail('over-credit'); }
        catch (\DomainException $e) { $this->assertStringContainsString('remaining', $e->getMessage()); }

        $c3 = $this->svc()->creditNote(1, (int) $inv['id'], ['reason' => 'Rest', 'full' => true]);   // takes the exact remainder
        $sum = static fn (string $f) => (int) $c1[$f] + (int) $c2[$f] + (int) $c3[$f];
        $this->assertSame((int) $inv['taxable_value'], $sum('taxable_value'));
        $this->assertSame((int) $inv['cgst'], $sum('cgst'));
        $this->assertSame((int) $inv['sgst'], $sum('sgst'));
        $this->assertSame((int) $inv['tcs'], $sum('tcs'));                              // no rounding drift, ever
        $this->assertSame((int) $inv['total'], $sum('total'));

        try { $this->svc()->creditNote(1, (int) $inv['id'], ['reason' => 'Again', 'full' => true]); $this->fail('fully credited'); }
        catch (\DomainException $e) { $this->assertStringContainsString('fully credited', $e->getMessage()); }
    }

    public function testCreditNoteNeedsAReasonAndAnInvoice(): void
    {
        $inv = $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Kerala']);
        foreach ([['reason' => ''], ['reason' => 'x'], ['reason' => 'x', 'taxable_value' => 0]] as $bad) {
            try { $this->svc()->creditNote(1, (int) $inv['id'], $bad); $this->fail(json_encode($bad)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $cn = $this->svc()->creditNote(1, (int) $inv['id'], ['reason' => 'ok', 'full' => true]);
        $this->expectException(\DomainException::class);                              // a credit note cannot itself be credited
        $this->svc()->creditNote(1, (int) $cn['id'], ['reason' => 'nope', 'full' => true]);
    }

    // ---- receipts ---------------------------------------------------------------------------------------------------------

    public function testReceiptsAreOnePerPaymentAndOnlyForReceivedMoney(): void
    {
        $b = $this->booking();
        $db = db_connect();
        $db->table('booking_payments')->insert(['id' => 1, 'tenant_id' => 1, 'booking_id' => $b, 'label' => 'Booking amount', 'due_date' => '2026-10-08', 'amount' => 1_551_879, 'status' => 'paid', 'mode' => 'upi', 'reference' => 'UTR123', 'paid_at' => '2026-10-08 10:00:00', 'created_at' => '2026-10-08 09:00:00']);
        $db->table('booking_payments')->insert(['id' => 2, 'tenant_id' => 1, 'booking_id' => $b, 'label' => 'Balance', 'due_date' => '2026-12-01', 'amount' => 3_621_051, 'status' => 'pending', 'created_at' => '2026-10-08 09:00:00']);

        $r = $this->svc()->receipt(1, 1);
        $again = $this->svc()->receipt(1, 1);
        $this->assertSame($r['id'], $again['id']);                                     // idempotent
        $this->assertSame('RC/26-27/00001', $r['number']);
        $this->assertSame(1_551_879, (int) $r['total']);
        $this->assertSame(1, (int) $db->table('invoices')->where('doc_type', 'receipt')->countAllResults());
        if (($t = $this->text($this->svc()->pdf($r))) !== null) { foreach (['PAYMENT RECEIPT', 'UTR123', '15,518.79', 'not a tax invoice'] as $n) { $this->assertStringContainsString($n, $t); } }

        $this->expectException(\DomainException::class);
        $this->svc()->receipt(1, 2);                                                   // pending payment: no receipt
    }

    // ---- immutability & integrity ------------------------------------------------------------------------------------------

    public function testIssuedDocumentsCannotBeEdited(): void
    {
        $inv = $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Kerala']);
        $this->expectException(\LogicException::class);
        (new InvoiceModel())->setTenant(1)->update((int) $inv['id'], ['total' => 1]);
    }

    public function testTamperedOrMissingStoredPdfIsNeverServed(): void
    {
        $inv = $this->svc()->issueForBooking(1, $this->booking(), ['state' => 'Kerala']);
        $path = WRITEPATH . $inv['pdf_path'];
        $this->assertSame(hash('sha256', file_get_contents($path)), $inv['pdf_sha256']);
        $orig = file_get_contents($path);

        file_put_contents($path, $orig . "\n%tampered");
        try { $this->svc()->pdf($inv); $this->fail('tampered file served'); } catch (\RuntimeException $e) { $this->assertStringContainsString('integrity', $e->getMessage()); }

        unlink($path);
        try { $this->svc()->pdf($inv); $this->fail('missing file served'); } catch (\RuntimeException $e) { $this->assertStringContainsString('never be regenerated', $e->getMessage()); }
    }

    public function testCancelledBookingCannotBeInvoiced(): void
    {
        $this->expectException(\DomainException::class);
        $this->svc()->issueForBooking(1, $this->booking(['status' => 'cancelled']), ['state' => 'Kerala']);
    }

    // ---- quote PDF is customer-safe ------------------------------------------------------------------------------------------

    public function testQuotePdfNeverContainsCostMarginOrSupplierData(): void
    {
        $db = db_connect();
        $b = $this->booking();
        $it = (int) $db->table('bookings')->where('id', $b)->get()->getRowArray()['itinerary_id'];
        $db->table('suppliers')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'SECRET-SUPPLIER-CO', 'type' => 'hotel', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('itineraries')->where('id', $it)->update(['cost_total' => 4_200_000, 'margin_amount' => 630_000, 'markup_value' => 15, 'inclusions' => "Daily breakfast\nAirport transfers", 'exclusions' => 'Airfare']);
        $db->table('itinerary_days')->insert(['tenant_id' => 1, 'itinerary_id' => $it, 'day_no' => 1, 'title' => 'Arrival in Bali', 'created_at' => '2026-09-01 00:00:00']);
        $dayId = (int) $db->insertID();
        $db->table('itinerary_items')->insert(['tenant_id' => 1, 'itinerary_id' => $it, 'day_id' => $dayId, 'type' => 'hotel', 'title' => 'Ubud Villa', 'supplier_id' => 1, 'nights' => 5, 'unit_cost' => 777_777, 'cost_amount' => 3_888_885, 'created_at' => '2026-09-01 00:00:00']);

        $data = (new QuoteDocument())->data(1, $it, false);
        $blob = json_encode($data);
        foreach (['SECRET-SUPPLIER-CO', 'cost_amount', 'unit_cost', 'margin', 'cost_total', 'markup', '3888885', '777777', '4200000', 'supplier_id'] as $leak) {
            $this->assertStringNotContainsString($leak, $blob, "quote data leaks: {$leak}");
        }
        $r = (new QuoteDocument())->render(1, $it);
        $this->assertStringStartsWith('%PDF-', $r['pdf']);
        if (($t = $this->text($r['pdf'])) !== null) {
            foreach (['Arrival in Bali', 'Ubud Villa', '51,729', 'Inclusions', 'Exclusions', 'Daily breakfast'] as $must) { $this->assertStringContainsString($must, $t); }
            foreach (['SECRET-SUPPLIER-CO', '38,888', '7,777', '42,000', 'margin'] as $leak) { $this->assertStringNotContainsString($leak, $t, $leak); }
        }
    }
}
