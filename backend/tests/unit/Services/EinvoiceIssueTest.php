<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\InvoiceModel;
use App\Services\Billing\Docs\BusinessProfileService;
use App\Services\Billing\Docs\GstExportService;
use App\Services\Billing\Docs\InvoiceService;
use App\Services\Einvoice\EinvoiceBuilder;
use App\Services\Einvoice\EinvoiceException;
use App\Services\Einvoice\EinvoiceService;
use App\Services\Einvoice\IrpClient;
use App\Services\Einvoice\MockIrpClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** E-invoicing wired into invoice issuing, cancellation, settings. Uses the simulator (EINVOICE_MOCK_MODE) — never a real IRP. MySQL group. */
#[Group('mysql')]
final class EinvoiceIssueTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const NOW = 1_791_439_200;   // 2026-10-08 11:30 IST

    protected function setUp(): void
    {
        parent::setUp();
        putenv('INVOICE_STORAGE_DIR=uploads/invoices-test'); $_ENV['INVOICE_STORAGE_DIR'] = 'uploads/invoices-test';
        putenv('EINVOICE_MOCK_MODE=true'); $_ENV['EINVOICE_MOCK_MODE'] = 'true';
        @unlink(WRITEPATH . 'einvoice-mock-1.json');
        EinvoiceService::$testFactory = null;
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['invoices', 'doc_sequences', 'business_profiles', 'einvoice_settings', 'booking_payments', 'booking_services', 'bookings', 'travelers', 'itinerary_items', 'itinerary_days', 'itineraries', 'trips', 'contacts', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'Demo Travels', 'slug' => 'd', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Rohit Sharma', 'wa_number' => '+919876543210', 'status' => 'new', 'source' => 'manual', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
        (new BusinessProfileService())->save(1, ['legal_name' => 'Demo Travels Private Limited', 'trade_name' => 'Demo Travels', 'gstin' => EinvoicePureTest::gstin('27ABCDE1234F1Z'), 'address_line1' => 'Shop 12, Andheri', 'city' => 'Mumbai', 'pincode' => '400093',
            'phone' => '+919820011111', 'email' => 'hello@demo.in', 'sac_code' => '998554']);
    }

    protected function tearDown(): void
    {
        EinvoiceService::$testFactory = null;
        db_connect()->table('einvoice_settings')->truncate();                                   // never leave e-invoicing switched on for the next test class
        foreach (glob(WRITEPATH . 'uploads/invoices-test/*/*/*') ?: [] as $f) { @unlink($f); }
        foreach (glob(WRITEPATH . 'uploads/invoices-test/*/*') ?: [] as $d) { @rmdir($d); }
        foreach (glob(WRITEPATH . 'uploads/invoices-test/*') ?: [] as $d) { @rmdir($d); }
        @rmdir(WRITEPATH . 'uploads/invoices-test');
        @unlink(WRITEPATH . 'einvoice-mock-1.json');
        putenv('EINVOICE_MOCK_MODE=false'); $_ENV['EINVOICE_MOCK_MODE'] = 'false'; putenv('INVOICE_STORAGE_DIR'); unset($_ENV['INVOICE_STORAGE_DIR']);
        parent::tearDown();
    }

    private function enable(bool $on = true): void { (new EinvoiceService(null, self::NOW))->save(1, ['enabled' => $on, 'mode' => 'demo', 'aato_confirmed' => true], 9); }
    private function inv(?int $now = null): InvoiceService { return new InvoiceService($now ?? self::NOW, false); }

    private function booking(): int
    {
        $db = db_connect();
        $db->table('trips')->insert(['tenant_id' => 1, 'contact_id' => 1, 'title' => 'Bali', 'destination_text' => 'Bali', 'is_international' => 1, 'adults' => 2, 'children' => 0, 'start_date' => '2027-01-20', 'end_date' => '2027-01-26', 'status' => 'booked', 'created_at' => '2026-09-01 00:00:00']);
        $trip = (int) $db->insertID();
        $db->table('itineraries')->insert(['tenant_id' => 1, 'trip_id' => $trip, 'title' => 'Bali honeymoon', 'status' => 'accepted', 'gst_rate' => 5, 'tcs_rate' => 2, 'sell_subtotal' => 4_830_000, 'gst_amount' => 241_500, 'tcs_amount' => 101_430, 'grand_total' => 5_172_930,
            'share_token' => bin2hex(random_bytes(16)), 'nights' => 6, 'adults' => 2, 'children' => 0, 'created_at' => '2026-09-01 00:00:00']);
        $it = (int) $db->insertID();
        $db->table('bookings')->insert(['tenant_id' => 1, 'trip_id' => $trip, 'itinerary_id' => $it, 'contact_id' => 1, 'booking_ref' => 'TP-2026-' . random_int(1000, 9999), 'title' => 'Bali honeymoon', 'status' => 'confirmed', 'travel_start' => '2027-01-20', 'travel_end' => '2027-01-26',
            'is_international' => 1, 'subtotal' => 4_830_000, 'gst_amount' => 241_500, 'tcs_amount' => 101_430, 'total_amount' => 5_172_930, 'paid_amount' => 0, 'created_at' => '2026-09-02 00:00:00']);
        return (int) $db->insertID();
    }

    private function b2b(array $o = []): array
    {
        return array_merge(['name' => 'Acme Holidays LLP', 'gstin' => EinvoicePureTest::gstin('29AAAPL1234C1Z'), 'address_line1' => '12 MG Road', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'pincode' => '560001', 'phone' => '9845011122', 'email' => 'a@acme.in'], $o);
    }

    // ---- issuing ----------------------------------------------------------------------------------------------------------

    public function testB2bInvoiceGetsAnIrnAndTheQrIsStoredAndPrinted(): void
    {
        $this->enable();
        $inv = $this->inv()->issueForBooking(1, $this->booking(), $this->b2b());
        $this->assertSame('generated', $inv['einvoice_status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $inv['einvoice_irn']);
        $this->assertStringStartsWith('MOCK.', $inv['einvoice_qr']);
        $this->assertNotNull($inv['einvoice_ack_no']);
        $pdf = $this->inv()->pdf($inv);
        $this->assertStringStartsWith('%PDF-', $pdf);
        if (trim((string) @shell_exec('command -v pdftotext'))) {
            $f = tempnam(sys_get_temp_dir(), 'ei') . '.pdf'; file_put_contents($f, $pdf);
            $t = preg_replace('/\s+/', ' ', (string) shell_exec('pdftotext ' . escapeshellarg($f) . ' -')); @unlink($f);
            $this->assertStringContainsStringIgnoringCase('e-invoice', $t);
            $this->assertStringContainsString($inv['einvoice_irn'], str_replace(' ', '', $t));
        }
        $this->assertSame(1, (int) db_connect()->table('audit_logs')->where('action', 'invoice.issue')->countAllResults());
    }

    public function testB2cInvoicesAndSwitchedOffTenantsAreNeverEinvoiced(): void
    {
        $this->enable();
        $b2c = $this->inv()->issueForBooking(1, $this->booking(), ['name' => 'Rohit', 'state' => 'Karnataka']);
        $this->assertSame(['none', null], [$b2c['einvoice_status'], $b2c['einvoice_irn']]);
        $this->enable(false);
        $off = $this->inv()->issueForBooking(1, $this->booking(), $this->b2b());
        $this->assertSame('none', $off['einvoice_status']);
        $this->assertSame(['INV/26-27/00001', 'INV/26-27/00002'], [$b2c['number'], $off['number']]);
    }

    public function testAnInvalidInvoiceIsRefusedWithEveryProblemAndConsumesNoNumber(): void
    {
        $this->enable();
        $bk = $this->booking();
        try { $this->inv()->issueForBooking(1, $bk, $this->b2b(['pincode' => '', 'address_line1' => ''])); $this->fail('issued an invoice the IRP would reject'); }
        catch (EinvoiceException $e) { $this->assertSame('invalid', $e->kind); $this->assertStringContainsString('PIN', $e->getMessage()); $this->assertStringContainsString('address', $e->getMessage()); }
        $this->assertSame(0, db_connect()->table('invoices')->countAllResults());
        $this->assertSame(0, (int) (db_connect()->table('doc_sequences')->get()->getRowArray()['last_seq'] ?? 0), 'the number must be rolled back (gap-free numbering)');
        $ok = $this->inv()->issueForBooking(1, $bk, $this->b2b());
        $this->assertSame('INV/26-27/00001', $ok['number']);
    }

    public function testAnOutageIssuesNothingAndTheRetryReusesTheSameNumber(): void
    {
        $this->enable();
        $bk = $this->booking();
        $down = new class implements IrpClient {
            public function generate(array $p): array { throw new EinvoiceException('The e-invoice service is not responding.', EinvoiceException::TRANSIENT); }
            public function cancel(string $irn, int $r, string $m): array { throw new \LogicException(); }
            public function findByDocument(string $t, string $n, string $d): ?array { return null; }
        };
        EinvoiceService::$testFactory = fn () => $down;
        try { $this->inv()->issueForBooking(1, $bk, $this->b2b()); $this->fail('issued while the IRP was down'); } catch (EinvoiceException $e) { $this->assertSame('transient', $e->kind); }
        $this->assertSame(0, db_connect()->table('invoices')->countAllResults());
        EinvoiceService::$testFactory = null;                                                    // IRP is back
        $inv = $this->inv()->issueForBooking(1, $bk, $this->b2b());
        $this->assertSame(['INV/26-27/00001', 'generated'], [$inv['number'], $inv['einvoice_status']]);
    }

    public function testAnIrnRegisteredByAnEarlierFailedAttemptIsAdoptedNotFailed(): void
    {
        $this->enable();
        $bk = $this->booking();
        // The IRP already holds INV/26-27/00001 (we registered it, then our DB write failed).
        $doc = ['doc_type' => 'tax_invoice', 'number' => 'INV/26-27/00001', 'issue_date' => '2026-10-08', 'place_of_supply' => '29', 'sac' => '998554', 'gst_rate' => 5, 'taxable_value' => 4_830_000, 'cgst' => 0, 'sgst' => 0, 'igst' => 241_500, 'tcs' => 101_430, 'total' => 5_172_930,
            'lines' => ['items' => [['title' => 't']]], 'buyer' => ['name' => 'Acme', 'gstin' => EinvoicePureTest::gstin('29AAAPL1234C1Z'), 'state_code' => '29', 'address_line1' => 'x', 'city' => 'Bengaluru', 'pincode' => '560001']];
        $profile = (new BusinessProfileService())->get(1);
        $first = (new MockIrpClient(1, self::NOW))->generate(EinvoiceBuilder::build($doc, $profile));
        $inv = $this->inv()->issueForBooking(1, $bk, $this->b2b());
        $this->assertSame($first['irn'], $inv['einvoice_irn']);                                     // the SAME IRN, no second registration
        $this->assertSame('generated', $inv['einvoice_status']);
    }

    // ---- credit notes & cancellation --------------------------------------------------------------------------------------

    public function testCreditNoteOnAnEinvoicedInvoiceIsItselfRegisteredAsACrn(): void
    {
        $this->enable();
        $inv = $this->inv()->issueForBooking(1, $this->booking(), $this->b2b());
        $cn = $this->inv()->creditNote(1, (int) $inv['id'], ['reason' => 'Customer cancelled', 'full' => true], 9);
        $this->assertSame(['credit_note', 'generated'], [$cn['doc_type'], $cn['einvoice_status']]);
        $this->assertNotSame($inv['einvoice_irn'], $cn['einvoice_irn']);
        $mock = json_decode((string) file_get_contents(WRITEPATH . 'einvoice-mock-1.json'), true)['docs'];
        $this->assertCount(2, $mock);
        $this->assertNotEmpty(array_filter(array_keys($mock), fn ($k) => str_contains($k, '|CRN|')));
    }

    public function testCancelWithinTheWindowCancelsTheInvoiceAndAllowsReInvoicing(): void
    {
        $this->enable();
        $bk = $this->booking();
        $inv = $this->inv()->issueForBooking(1, $bk, $this->b2b(['pincode' => '560002']));
        $this->assertSame(23.0, (new EinvoiceService(null, self::NOW + 3600))->cancelHoursLeft($inv));
        $c = (new EinvoiceService(null, self::NOW + 3600))->cancel(1, (int) $inv['id'], 2, 'Wrong customer address', 9);
        $this->assertSame(['cancelled', 'cancelled'], [$c['status'], $c['einvoice_status']]);
        $this->assertStringContainsString('Data entry mistake: Wrong customer address', $c['einvoice_cancel_reason']);
        try { (new EinvoiceService(null, self::NOW + 3600))->cancel(1, (int) $inv['id'], 2, 'again', 9); $this->fail('cancelled twice'); } catch (\DomainException) { $this->addToAssertionCount(1); }
        $new = $this->inv(self::NOW + 7200)->issueForBooking(1, $bk, $this->b2b());                    // the booking can be invoiced again: new number, new IRN
        $this->assertSame('INV/26-27/00002', $new['number']);
        $this->assertNotSame($inv['einvoice_irn'], $new['einvoice_irn']);
        // the cancelled one is out of GST returns and counted as cancelled in the document summary
        $docs = (new GstExportService())->documents(1, '2026-10');
        $this->assertSame(['cancelled', 'issued'], array_column(array_filter($docs, fn ($d) => $d['doc_type'] === 'tax_invoice'), 'status'));
    }

    public function testCancelAfter24HoursOrAfterACreditNoteIsRefused(): void
    {
        $this->enable();
        $inv = $this->inv()->issueForBooking(1, $this->booking(), $this->b2b());
        try { (new EinvoiceService(null, self::NOW + 25 * 3600))->cancel(1, (int) $inv['id'], 1, 'late', 9); $this->fail('cancelled after the window'); }
        catch (EinvoiceException $e) { $this->assertSame('window', $e->kind); $this->assertStringContainsString('credit note', $e->getMessage()); }
        $this->assertNull((new EinvoiceService(null, self::NOW + 25 * 3600))->cancelHoursLeft($inv));
        $this->inv()->creditNote(1, (int) $inv['id'], ['reason' => 'Partial refund', 'taxable_value' => 1_000_000], 9);
        $this->expectException(\DomainException::class);
        (new EinvoiceService(null, self::NOW + 3600))->cancel(1, (int) $inv['id'], 2, 'after a credit note', 9);
        $this->assertSame('generated', (new InvoiceModel())->setTenant(1)->find((int) $inv['id'])['einvoice_status']);
    }

    public function testTheLegalRecordOtherwiseStaysImmutable(): void
    {
        $this->enable();
        $inv = $this->inv()->issueForBooking(1, $this->booking(), $this->b2b());
        $this->expectException(\LogicException::class);
        (new InvoiceModel())->setTenant(1)->update((int) $inv['id'], ['einvoice_irn' => str_repeat('0', 64)]);          // the IRN can never be rewritten
    }

    // ---- settings & compliance -------------------------------------------------------------------------------------------------

    public function testSecretsAreWriteOnlyAndRulesAreEnforced(): void
    {
        $s = new EinvoiceService(null, self::NOW);
        foreach ([['enabled' => true, 'mode' => 'gsp', 'base_url' => 'https://gsp.example.com'], ['enabled' => true, 'aato_confirmed' => true, 'mode' => 'gsp'], ['mode' => 'gsp', 'base_url' => 'http://insecure.example.com'], ['mode' => 'gsp', 'base_url' => 'https://g.example.com', 'generate_path' => '/a b?x=1']] as $bad) {
            try { $s->save(1, $bad, 9); $this->fail('accepted ' . json_encode($bad)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $v = $s->save(1, ['enabled' => true, 'aato_confirmed' => true, 'mode' => 'gsp', 'base_url' => 'https://gsp.example.com/api', 'auth_type' => 'headers', 'headers' => ['X-Api-Key' => 'SUPER-SECRET-KEY', 'X-Gstin' => '27ABCDE1234F1Z0']], 9);
        $this->assertTrue($v['enabled']);
        $this->assertSame(['X-Api-Key', 'X-Gstin'], $v['config']['header_names']);
        $this->assertTrue($v['config']['has_secret']);
        $this->assertStringNotContainsString('SUPER-SECRET-KEY', json_encode($v));
        $stored = (string) db_connect()->table('einvoice_settings')->get()->getRowArray()['config_enc'];
        $this->assertStringNotContainsString('SUPER-SECRET-KEY', $stored);                                 // encrypted at rest
        // saving again with the header value left blank keeps the stored secret
        $s->save(1, ['enabled' => true, 'aato_confirmed' => true, 'mode' => 'gsp', 'base_url' => 'https://gsp.example.com/api', 'headers' => ['X-Api-Key' => '', 'X-Gstin' => '27ABCDE1234F1Z0']], 9);
        $cfg = json_decode(\App\Services\WhatsApp\TokenCipher::decrypt((string) db_connect()->table('einvoice_settings')->get()->getRowArray()['config_enc']), true);
        $this->assertSame('SUPER-SECRET-KEY', $cfg['auth']['headers']['X-Api-Key']);
        putenv('EINVOICE_MOCK_MODE=false'); $_ENV['EINVOICE_MOCK_MODE'] = 'false';
        try { $s->save(1, ['mode' => 'demo'], 9); $this->fail('demo mode allowed on a production-style server'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('local testing', $e->getMessage()); }
    }

    public function testB2bInvoicesIssuedWithoutAnIrnWhileEnabledAreFlaggedInTheReturn(): void
    {
        $bk = $this->booking();
        $this->enable();
        EinvoiceService::$testFactory = fn () => new MockIrpClient(1, self::NOW);
        // simulate a gap: a B2B invoice row that slipped through without an IRN (e.g. issued before this version)
        $inv = $this->inv()->issueForBooking(1, $bk, $this->b2b());
        db_connect()->table('invoices')->where('id', $inv['id'])->update(['einvoice_status' => 'none', 'einvoice_irn' => null]);
        $r = (new GstExportService())->summary(1, '2026-10');
        $this->assertStringContainsString('no IRN', implode(' ', $r['warnings']));
        $this->assertSame(1, (new EinvoiceService())->missingIrn(1, '2026-10-01', '2026-10-31'));
        $this->assertSame(0, (new EinvoiceService())->missingIrn(2, '2026-10-01', '2026-10-31'));            // tenant isolation
    }
}
