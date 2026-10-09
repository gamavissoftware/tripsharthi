<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\ChecklistService;
use App\Services\Travel\PortalService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class PortalServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const NOW = 1_791_439_200;
    private int $b1; private int $b2; private string $tok1; private int $item1; private int $item2;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('PORTAL_STORAGE_DIR=uploads/portal-test'); $_ENV['PORTAL_STORAGE_DIR'] = 'uploads/portal-test';
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['portal_uploads', 'booking_checklist_items', 'booking_payments', 'booking_services', 'invoices', 'travelers', 'bookings', 'contacts', 'notifications', 'business_profiles', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $this->b1 = $this->booking(1); $this->b2 = $this->booking(1);
        $svc = new PortalService(self::NOW);
        $this->tok1 = $svc->ensureToken(1, $this->b1);
        db_connect()->table('travelers')->insert(['tenant_id' => 1, 'booking_id' => $this->b1, 'full_name' => 'Rohit', 'pax_type' => 'adult', 'created_at' => '2026-09-01 00:00:00']);
        db_connect()->table('travelers')->insert(['tenant_id' => 1, 'booking_id' => $this->b2, 'full_name' => 'Other', 'pax_type' => 'adult', 'created_at' => '2026-09-01 00:00:00']);
        $c = new ChecklistService(self::NOW); $c->generate(1, $this->b1); $c->generate(1, $this->b2);
        $this->item1 = (int) $c->forBooking(1, $this->b1)['items'][0]['id'];
        $this->item2 = (int) $c->forBooking(1, $this->b2)['items'][0]['id'];
    }

    protected function tearDown(): void
    {
        foreach (glob(WRITEPATH . 'uploads/portal-test/*/*/*') ?: [] as $f) { @unlink($f); }
        foreach (glob(WRITEPATH . 'uploads/portal-test/*/*') ?: [] as $d) { @rmdir($d); }
        foreach (glob(WRITEPATH . 'uploads/portal-test/*') ?: [] as $d) { @rmdir($d); }
        @rmdir(WRITEPATH . 'uploads/portal-test');
        putenv('PORTAL_STORAGE_DIR'); unset($_ENV['PORTAL_STORAGE_DIR']);
        parent::tearDown();
    }

    private function booking(int $tenant): int
    {
        $db = db_connect();
        $db->table('bookings')->insert(['tenant_id' => $tenant, 'booking_ref' => 'TP-' . random_int(1000, 9999), 'title' => 'Bali', 'status' => 'confirmed', 'is_international' => 1, 'travel_start' => '2026-12-01', 'owner_id' => null,
            'subtotal' => 1_000_000, 'cost_total' => 800_000, 'total_amount' => 1_050_000, 'paid_amount' => 100_000, 'created_at' => '2026-09-01 00:00:00']);
        $id = (int) $db->insertID();
        $db->table('booking_payments')->insert(['tenant_id' => $tenant, 'booking_id' => $id, 'label' => 'Deposit', 'amount' => 100_000, 'due_date' => '2026-10-20', 'status' => 'pending', 'created_at' => '2026-09-01 00:00:00']);
        $db->table('booking_services')->insert(['tenant_id' => $tenant, 'booking_id' => $id, 'title' => 'SECRET-HOTEL', 'service_type' => 'hotel', 'status' => 'confirmed', 'cost_amount' => 800_000, 'voucher_token' => bin2hex(random_bytes(16)), 'created_at' => '2026-09-01 00:00:00']);
        return $id;
    }

    private function file(string $bytes, string $name = 'passport.pdf'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pt'); file_put_contents($tmp, $bytes);
        return ['tmp_name' => $tmp, 'name' => $name, 'size' => strlen($bytes)];
    }

    private function svc(): PortalService { return new PortalService(self::NOW); }

    public function testTokenIsStableRotatableAndGatesEverything(): void
    {
        $this->assertSame($this->tok1, $this->svc()->ensureToken(1, $this->b1));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->tok1);
        $new = $this->svc()->rotateToken(1, $this->b1);
        $this->assertNotSame($this->tok1, $new);
        $this->expectException(\OutOfBoundsException::class);
        $this->svc()->view($this->tok1);                       // the old link is dead
    }

    public function testViewIsCustomerSafeAndMalformedTokensRefused(): void
    {
        $v = $this->svc()->view($this->tok1);
        $blob = json_encode($v);
        foreach (['cost_total', 'cost_amount', 'margin', '800000', 'supplier_id', 'tenant_id', 'portal_token'] as $leak) { $this->assertStringNotContainsString($leak, $blob, "portal leaks $leak"); }
        $this->assertSame(1_050_000, $v['booking']['total']);
        $this->assertSame(950_000, $v['booking']['due']);
        $this->assertTrue($v['payments'][0]['payable']);
        $this->assertNotEmpty($v['checklist']);
        $this->assertCount(1, array_filter($v['documents'], fn ($d) => $d['kind'] === 'voucher'));
        foreach (['', 'short', str_repeat('g', 32), str_repeat('a', 32)] as $bad) {
            try { $this->svc()->view($bad); $this->fail("accepted '$bad'"); } catch (\OutOfBoundsException) { $this->addToAssertionCount(1); }
        }
    }

    public function testUploadIsEncryptedAtRestFlagsTheItemAndStaffCanReadItBack(): void
    {
        $secret = '%PDF-1.4 PASSPORT-NUMBER-Z1234567 ' . str_repeat('x', 100);
        $this->assertSame('uploaded', $this->svc()->upload($this->tok1, $this->item1, $this->file($secret))['status']);
        $row = db_connect()->table('portal_uploads')->get()->getRowArray();
        $this->assertStringNotContainsString('Z1234567', (string) file_get_contents(WRITEPATH . $row['path']), 'file must be encrypted on disk');
        $this->assertSame($secret, $this->svc()->readUpload(1, (int) $row['id'])['bytes']);
        $this->assertSame('uploaded', db_connect()->table('booking_checklist_items')->where('id', $this->item1)->get()->getRowArray()['status']);
        $this->assertFalse((new ChecklistService(self::NOW))->forBooking(1, $this->b1)['progress']['ready']);
        $this->assertFalse($this->svc()->view($this->tok1)['checklist'][0]['can_upload'] === false);   // still replaceable until accepted
    }

    public function testRejectedFileGoesBackToPendingWithAReasonAndAcceptCompletesIt(): void
    {
        $this->svc()->upload($this->tok1, $this->item1, $this->file('%PDF-1.4 a'));
        $id = (int) db_connect()->table('portal_uploads')->get()->getRowArray()['id'];
        try { $this->svc()->review(1, $id, false, ' ', 5); $this->fail('reject needs a reason'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->svc()->review(1, $id, false, 'Photo is blurry', 5);
        $v = $this->svc()->view($this->tok1)['checklist'][0];
        $this->assertSame(['pending', 'Photo is blurry'], [$v['status'], $v['rejected_reason']]);
        $this->svc()->upload($this->tok1, $this->item1, $this->file("\x89PNG\r\n\x1a\n....", 'scan.png'));
        $id2 = (int) db_connect()->table('portal_uploads')->orderBy('id', 'DESC')->get()->getRowArray()['id'];
        $this->svc()->review(1, $id2, true, null, 5);
        $this->assertSame('received', db_connect()->table('booking_checklist_items')->where('id', $this->item1)->get()->getRowArray()['status']);
        $this->expectException(\DomainException::class);
        $this->svc()->upload($this->tok1, $this->item1, $this->file('%PDF-1.4 again'));          // completed items accept no more files
    }

    public function testDangerousOversizedAndMisdeclaredFilesAreRefused(): void
    {
        foreach ([["MZ\x90\x00 evil.exe", 'cv.pdf'], ['<?php system($_GET[0]);', 'photo.jpg'], ['<svg onload=alert(1)>', 'x.png'], ['', 'empty.pdf']] as [$bytes, $name]) {
            try { $this->svc()->upload($this->tok1, $this->item1, $this->file($bytes, $name)); $this->fail("accepted $name"); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $big = $this->file('%PDF-' . str_repeat('x', PortalService::MAX_BYTES));
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->upload($this->tok1, $this->item1, $big);
    }

    public function testCustomerCannotTouchAnotherBookingsItemsOrPayments(): void
    {
        try { $this->svc()->upload($this->tok1, $this->item2, $this->file('%PDF-1.4 x')); $this->fail('uploaded to another booking'); } catch (\OutOfBoundsException) { $this->addToAssertionCount(1); }
        $otherPay = (int) db_connect()->table('booking_payments')->where('booking_id', $this->b2)->get()->getRowArray()['id'];
        $this->expectException(\OutOfBoundsException::class);
        $this->svc()->payLink($this->tok1, $otherPay);
    }

    public function testPerItemLimitAndStaffTenantIsolation(): void
    {
        for ($i = 0; $i < PortalService::MAX_PER_ITEM; $i++) { $this->svc()->upload($this->tok1, $this->item1, $this->file('%PDF-1.4 ' . $i)); }
        try { $this->svc()->upload($this->tok1, $this->item1, $this->file('%PDF-1.4 more')); $this->fail('limit not enforced'); } catch (\DomainException) { $this->addToAssertionCount(1); }
        $id = (int) db_connect()->table('portal_uploads')->get()->getRowArray()['id'];
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->readUpload(2, $id);                                                          // another tenant's staff cannot read it
    }
}
