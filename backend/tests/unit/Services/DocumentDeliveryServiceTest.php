<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\Docs\BusinessProfileService;
use App\Services\Billing\Docs\DocumentDeliveryService;
use App\Services\Billing\Docs\InvoiceService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class DocumentDeliveryServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const NOW = 1_791_439_200;   // 2026-10-08 11:30 IST
    private object $wa;
    private int $bookingId;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('INVOICE_STORAGE_DIR=uploads/invoices-test'); $_ENV['INVOICE_STORAGE_DIR'] = 'uploads/invoices-test';
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['document_deliveries', 'invoices', 'einvoice_settings', 'doc_sequences', 'business_profiles', 'messages', 'conversations', 'templates', 'booking_payments', 'booking_services', 'bookings', 'itineraries', 'trips', 'contacts', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'Demo Travels', 'slug' => 'd', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Rohit Sharma', 'wa_number' => '+919876543210', 'status' => 'new', 'source' => 'manual', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
        (new BusinessProfileService())->save(1, ['legal_name' => 'Demo Travels Pvt Ltd', 'trade_name' => 'Demo Travels', 'gstin' => '27ABCDE1234F1Z0', 'address_line1' => 'Shop 12', 'city' => 'Mumbai', 'pincode' => '400093',
            'phone' => '+919820011111', 'email' => 'hello@demo.in', 'bank_name' => 'HDFC', 'bank_account_no' => '123456789', 'bank_ifsc' => 'HDFC0001234', 'signatory' => 'Anita Rao']);
        $db->table('trips')->insert(['tenant_id' => 1, 'contact_id' => 1, 'title' => 'Bali', 'status' => 'booked', 'created_at' => '2026-09-01 00:00:00']);
        $trip = (int) $db->insertID();
        $db->table('itineraries')->insert(['tenant_id' => 1, 'trip_id' => $trip, 'title' => 'Bali', 'status' => 'accepted', 'sell_subtotal' => 100_000, 'gst_rate' => 5, 'gst_amount' => 5_000, 'grand_total' => 105_000, 'share_token' => bin2hex(random_bytes(16)), 'created_at' => '2026-09-01 00:00:00']);
        $db->table('bookings')->insert(['tenant_id' => 1, 'trip_id' => $trip, 'itinerary_id' => (int) $db->insertID(), 'contact_id' => 1, 'booking_ref' => 'TP-2026-1001', 'title' => 'Bali', 'status' => 'confirmed', 'subtotal' => 100_000, 'gst_amount' => 5_000, 'total_amount' => 105_000, 'created_at' => '2026-09-02 00:00:00']);
        $this->bookingId = (int) $db->insertID();

        $this->wa = new class {
            public array $texts = []; public array $templates = []; public array $media = []; public bool $mediaWorks = true; public bool $fail = false;
            public function sendText(string $to, string $b): array { $this->texts[] = [$to, $b]; return ['success' => ! $this->fail, 'message_id' => 'wamid.T', 'error' => $this->fail ? 'boom' : null]; }
            public function sendTemplate(string $to, string $n, string $l, array $c = []): array { $this->templates[] = [$to, $n, $c]; return ['success' => true, 'message_id' => 'wamid.P', 'error' => null]; }
            public function uploadMedia(string $f, string $m): array { return $this->mediaWorks ? ['success' => true, 'media_id' => 'm1', 'error' => null] : ['success' => false, 'media_id' => null, 'error' => 'unsupported']; }
            public function sendMedia(string $to, string $t, string $id, string $cap = '', string $fn = ''): array { $this->media[] = [$to, $t, $id, $cap, $fn]; return ['success' => true, 'message_id' => 'wamid.M', 'error' => null]; }
        };
    }

    protected function tearDown(): void
    {
        foreach (glob(WRITEPATH . 'uploads/invoices-test/*/*/*') ?: [] as $f) { @unlink($f); }
        foreach (glob(WRITEPATH . 'uploads/invoices-test/*/*') ?: [] as $d) { @rmdir($d); }
        foreach (glob(WRITEPATH . 'uploads/invoices-test/*') ?: [] as $d) { @rmdir($d); }
        @rmdir(WRITEPATH . 'uploads/invoices-test');
        putenv('INVOICE_STORAGE_DIR'); unset($_ENV['INVOICE_STORAGE_DIR']);
        parent::tearDown();
    }

    private function svc(): DocumentDeliveryService { return new DocumentDeliveryService(fn () => $this->wa, self::NOW); }
    private function invoice(): int { return (int) (new InvoiceService(self::NOW, false))->issueForBooking(1, $this->bookingId, ['state' => 'Maharashtra'])['id']; }
    private function openWindow(): void { (new \App\Models\ConversationModel())->findOrCreate(1, '+919876543210'); db_connect()->table('conversations')->where('tenant_id', 1)->update(['window_expires_at' => date('Y-m-d H:i:s', self::NOW + 3600)]); }
    private function approvedTemplate(): void
    {
        $id = $this->svc()->setupTemplate(1)['id'];
        db_connect()->table('templates')->where('id', $id)->update(['meta_status' => 'approved']);
    }

    public function testWindowOpenSendsThePdfAsADocument(): void
    {
        $inv = $this->invoice(); $this->openWindow();
        $r = $this->svc()->send(1, 'invoice', $inv, 7);
        $this->assertSame('document', $r['mode']);
        $this->assertCount(1, $this->wa->media);
        $this->assertSame('document', $this->wa->media[0][1]);
        $this->assertStringEndsWith('.pdf', $this->wa->media[0][4]);
        $this->assertStringNotContainsString('/', $this->wa->media[0][4]);
        $this->assertSame([], $this->wa->templates);
        $row = db_connect()->table('document_deliveries')->get()->getRowArray();
        $this->assertSame(['sent', 'document', 7], [$row['status'], $row['mode'], (int) $row['sent_by']]);
        $this->assertSame('document', db_connect()->table('messages')->get()->getRowArray()['type']);
    }

    public function testProviderWithoutMediaFallsBackToADownloadLink(): void
    {
        $inv = $this->invoice(); $this->openWindow(); $this->wa->mediaWorks = false;
        $r = $this->svc()->send(1, 'invoice', $inv);
        $this->assertSame('link', $r['mode']);
        $this->assertMatchesRegularExpression('#/api/v1/public/invoices/[a-f0-9]{32}/pdf#', $this->wa->texts[0][1]);
    }

    public function testWindowClosedWithoutApprovedTemplateSendsNothing(): void
    {
        $inv = $this->invoice();
        try { $this->svc()->send(1, 'invoice', $inv); $this->fail('expected refusal'); }
        catch (\DomainException $e) { $this->assertStringContainsString('window is closed', $e->getMessage()); }
        $this->assertSame([], $this->wa->texts); $this->assertSame([], $this->wa->media); $this->assertSame([], $this->wa->templates);
        // an unapproved (draft) template is not enough either
        $this->svc()->setupTemplate(1);
        $this->expectException(\DomainException::class);
        $this->svc()->send(1, 'invoice', $inv);
    }

    public function testWindowClosedUsesTheApprovedTemplateWithTheLink(): void
    {
        $inv = $this->invoice(); $this->approvedTemplate();
        $r = $this->svc()->send(1, 'invoice', $inv);
        $this->assertSame('template', $r['mode']);
        $this->assertSame(DocumentDeliveryService::TEMPLATE, $this->wa->templates[0][1]);
        $this->assertStringContainsString('/api/v1/public/invoices/', json_encode($this->wa->templates[0][2], JSON_UNESCAPED_SLASHES));
        $this->assertSame([], $this->wa->texts);
    }

    public function testOptedOutCustomerIsNeverMessaged(): void
    {
        $inv = $this->invoice(); $this->openWindow();
        db_connect()->table('contacts')->where('id', 1)->update(['opt_in' => 0]);
        $this->expectException(\DomainException::class);
        try { $this->svc()->send(1, 'invoice', $inv); } finally { $this->assertSame([], $this->wa->media); }
    }

    public function testDoubleClickCannotSendTwice(): void
    {
        $inv = $this->invoice(); $this->openWindow();
        $this->svc()->send(1, 'invoice', $inv);
        try { $this->svc()->send(1, 'invoice', $inv); $this->fail('expected duplicate refusal'); }
        catch (\DomainException $e) { $this->assertStringContainsString('just sent', $e->getMessage()); }
        $this->assertCount(1, $this->wa->media);
        // after the guard window it may be sent again (customer asked for it again)
        $later = new DocumentDeliveryService(fn () => $this->wa, self::NOW + 120);
        db_connect()->table('conversations')->where('tenant_id', 1)->update(['window_expires_at' => date('Y-m-d H:i:s', self::NOW + 3600)]);
        $later->send(1, 'invoice', $inv);
        $this->assertCount(2, $this->wa->media);
    }

    public function testFailedSendIsRecordedAndDoesNotBlockARetry(): void
    {
        $inv = $this->invoice(); $this->openWindow(); $this->wa->mediaWorks = false; $this->wa->fail = true;
        try { $this->svc()->send(1, 'invoice', $inv); $this->fail('expected failure'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('boom', $e->getMessage()); }
        $this->assertSame('failed', db_connect()->table('document_deliveries')->get()->getRowArray()['status']);
        $this->wa->fail = false;
        $this->assertSame('link', $this->svc()->send(1, 'invoice', $inv)['mode']);
    }

    public function testTamperedStoredPdfIsNeverSent(): void
    {
        $inv = $this->invoice(); $this->openWindow();
        $path = WRITEPATH . db_connect()->table('invoices')->where('id', $inv)->get()->getRowArray()['pdf_path'];
        file_put_contents($path, '%PDF-tampered');
        try { $this->svc()->send(1, 'invoice', $inv); $this->fail('expected integrity failure'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('integrity', $e->getMessage()); }
        $this->assertSame([], $this->wa->media); $this->assertSame([], $this->wa->texts);
    }

    public function testVoucherSendAndCancelledServiceRefused(): void
    {
        $db = db_connect(); $this->openWindow();
        $db->table('booking_services')->insert(['tenant_id' => 1, 'booking_id' => $this->bookingId, 'service_type' => 'hotel', 'title' => 'Ubud Villa', 'status' => 'confirmed', 'voucher_token' => bin2hex(random_bytes(16)), 'created_at' => '2026-09-02 00:00:00']);
        $id = (int) $db->insertID();
        $this->assertSame('document', $this->svc()->send(1, 'voucher', $id)['mode']);
        $this->assertStringStartsWith('Voucher_TP-2026-1001', $this->wa->media[0][4]);
        $db->table('booking_services')->where('id', $id)->update(['status' => 'cancelled']);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->send(1, 'voucher', $id);
    }

    public function testPortalLinkIsSentAsTextInsideTheWindowAndNeedsTheTemplateOutside(): void
    {
        $this->openWindow();
        $r = $this->svc()->send(1, 'portal', $this->bookingId);
        $this->assertSame('link', $r['mode']);
        $this->assertMatchesRegularExpression('~/#/trip/[a-f0-9]{32}~', $this->wa->texts[0][1]);
        $this->assertSame([], $this->wa->media);
        db_connect()->table('conversations')->where('tenant_id', 1)->update(['window_expires_at' => null]);
        $later = new DocumentDeliveryService(fn () => $this->wa, self::NOW + 300);
        $this->expectException(\DomainException::class);
        $later->send(1, 'portal', $this->bookingId);
    }

    public function testOtherTenantsDocumentsAreInvisible(): void
    {
        $inv = $this->invoice();
        db_connect()->table('tenants')->insert(['id' => 2, 'name' => 'Other', 'slug' => 'o', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->send(2, 'invoice', $inv);
    }

    public function testTemplateSetupIsIdempotent(): void
    {
        $a = $this->svc()->setupTemplate(1); $b = $this->svc()->setupTemplate(1);
        $this->assertTrue($a['created']); $this->assertFalse($b['created']); $this->assertSame($a['id'], $b['id']);
        $this->assertSame('draft', $this->svc()->templateStatus(1));
    }
}
