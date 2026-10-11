<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\BillingService;
use App\Services\Billing\Docs\Gst;
use App\Services\Billing\PlatformInvoiceService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** TripSarthi's own GST tax invoices: tax maths, numbering, immutability, e-mail, filters, exports (group "mysql"). */
#[Group('mysql')]
final class PlatformInvoiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private string $dir = '';
    private array $mails = [];
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = strtotime('2026-10-11 12:00:00 UTC');
        $this->dir = sys_get_temp_dir() . '/tsinv-' . bin2hex(random_bytes(4));
        putenv('PLATFORM_INVOICE_STORAGE_DIR=' . $this->dir); $_ENV['PLATFORM_INVOICE_STORAGE_DIR'] = $this->dir; $_SERVER['PLATFORM_INVOICE_STORAGE_DIR'] = $this->dir;
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['platform_invoices', 'platform_doc_sequences', 'subscriptions', 'business_profiles', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query("DELETE FROM platform_settings WHERE setting_key LIKE 'inv\\_%'");
        foreach ([1 => 'Alpha Travels', 2 => 'Bravo Tours', 3 => 'Charlie Trips'] as $id => $name) {
            $db->table('tenants')->insert(['id' => $id, 'name' => $name, 'slug' => "t$id", 'plan' => 'growth', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
            $db->table('users')->insert(['id' => $id, 'tenant_id' => $id, 'name' => "Owner $id", 'email' => "owner{$id}@example.com", 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        }
        $this->mails = [];
        PlatformInvoiceService::$mailer = function (string $to, string $subject, string $html, string $text, array $attachments, ?string $bcc): bool { $this->mails[] = compact('to', 'subject', 'html', 'text', 'attachments', 'bcc'); return true; };
    }

    protected function tearDown(): void
    {
        PlatformInvoiceService::$mailer = null; PlatformInvoiceService::$enableInTests = false;
        foreach (glob($this->dir . '/*/*') ?: [] as $f) { @unlink($f); } foreach (glob($this->dir . '/*') ?: [] as $d) { @rmdir($d); } @rmdir($this->dir);
        putenv('PLATFORM_INVOICE_STORAGE_DIR'); unset($_ENV['PLATFORM_INVOICE_STORAGE_DIR'], $_SERVER['PLATFORM_INVOICE_STORAGE_DIR']);
        db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown();
    }

    private function svc(?int $now = null): PlatformInvoiceService { return new PlatformInvoiceService($now ?? $this->now); }

    private function sub(int $tenant, int $paise = 59000, string $status = 'active', string $ref = 'pay_ABC', string $plan = 'growth', string $cycle = 'monthly'): int
    {
        db_connect()->table('subscriptions')->insert(['tenant_id' => $tenant, 'razorpay_sub_id' => $ref, 'razorpay_customer_id' => 'c', 'plan' => $plan, 'amount_paise' => $paise, 'billing_cycle' => $cycle, 'status' => $status,
            'current_period_start' => '2026-10-11 10:00:00', 'current_period_end' => '2026-11-10 10:00:00', 'created_at' => '2026-10-11 10:00:00', 'updated_at' => '2026-10-11 10:00:00']);
        return (int) db_connect()->insertID();
    }

    private function profile(int $tenant, array $f): void
    {
        db_connect()->table('business_profiles')->insert(['tenant_id' => $tenant, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'] + $f);
    }

    private function gstin(string $state, string $pan = 'AABCB4821K'): string { $p = $state . $pan . '1Z'; return $p . Gst::checkChar($p); }

    // ---- tax maths ----------------------------------------------------------------------------------------------------------------------

    public function testHaryanaBuyerPaysCgstPlusSgstOutOfAnInclusivePrice(): void
    {
        $inv = $this->svc()->issueForSubscription($this->sub(1, 59000));
        $this->assertSame('GS/26-27/00001', $inv['number']);
        $this->assertSame('2026-10-11', $inv['issue_date']);
        $this->assertSame('b2c', $inv['buyer_type']); $this->assertSame('06', $inv['place_of_supply']); $this->assertSame('intra', $inv['supply_type']);
        $this->assertSame([50000, 4500, 4500, 0, 59000], [(int) $inv['taxable_value'], (int) $inv['cgst'], (int) $inv['sgst'], (int) $inv['igst'], (int) $inv['total']]);
        $this->assertSame('online', $inv['source']); $this->assertSame('pay_ABC', $inv['payment_ref']);
    }

    public function testRegisteredBuyerInAnotherStateGetsIgstAndTheirGstin(): void
    {
        $g = $this->gstin('27');
        $this->profile(2, ['legal_name' => 'Bravo Tours Pvt Ltd', 'gstin' => $g, 'state_code' => '27', 'address_line1' => '12 Marine Drive', 'city' => 'Mumbai', 'pincode' => '400001']);
        $inv = $this->svc()->issueForSubscription($this->sub(2, 59000));
        $this->assertSame('b2b', $inv['buyer_type']); $this->assertSame($g, $inv['buyer_gstin']); $this->assertSame('Bravo Tours Pvt Ltd', $inv['buyer_name']);
        $this->assertSame('27', $inv['place_of_supply']); $this->assertSame('inter', $inv['supply_type']);
        $this->assertSame([50000, 0, 0, 9000], [(int) $inv['taxable_value'], (int) $inv['cgst'], (int) $inv['sgst'], (int) $inv['igst']]);
    }

    public function testAnInvalidGstinIsIgnoredNotPrinted(): void
    {
        $this->profile(3, ['legal_name' => 'Charlie', 'gstin' => '27AABCB4821K1ZX', 'state_code' => '27']);
        $inv = $this->svc()->issueForSubscription($this->sub(3));
        $this->assertSame('b2c', $inv['buyer_type']); $this->assertNull($inv['buyer_gstin']);
        $this->assertSame('27', $inv['place_of_supply'], 'the state they typed still decides the place of supply');
    }

    public function testTaxAlwaysAddsUpToTheTotalToThePaisa(): void
    {
        foreach ([100, 101, 999, 1234, 59001, 590000, 4999900] as $i => $paise) {
            $inv = $this->svc()->issueForSubscription($this->sub(1, $paise, 'active', 'pay_' . $i));
            $this->assertSame($paise, (int) $inv['taxable_value'] + (int) $inv['cgst'] + (int) $inv['sgst'] + (int) $inv['igst'], "total $paise");
        }
    }

    public function testNothingToInvoiceForFreeComplimentaryOrUnpaid(): void
    {
        $this->assertNull($this->svc()->issueForSubscription($this->sub(1, 0, 'active', 'admin-1')), 'a complimentary period');
        $this->assertNull($this->svc()->issueForSubscription($this->sub(1, 59000, 'created', 'order_X')), 'an order that was never paid');
        $this->assertSame(0, db_connect()->table('platform_invoices')->countAllResults());
    }

    // ---- numbering ------------------------------------------------------------------------------------------------------------------------

    public function testOneInvoicePerPaymentAndNoGaps(): void
    {
        $a = $this->sub(1); $b = $this->sub(2, 59000, 'active', 'pay_2');
        $one = $this->svc()->issueForSubscription($a); $again = $this->svc()->issueForSubscription($a); $two = $this->svc()->issueForSubscription($b);
        $this->assertSame($one['id'], $again['id'], 'asking twice returns the same invoice');
        $this->assertSame(['GS/26-27/00001', 'GS/26-27/00002'], [$one['number'], $two['number']]);
    }

    public function testNumberingRestartsEachFinancialYearOnTheIndianDate(): void
    {
        $this->svc(strtotime('2027-03-31 10:00:00 UTC'))->issueForSubscription($this->sub(1, 59000, 'active', 'p1'));
        $late = $this->svc(strtotime('2027-03-31 19:00:00 UTC'))->issueForSubscription($this->sub(1, 59000, 'active', 'p2'));   // 00:30 on 1 April in India
        $this->assertSame('2027-04-01', $late['issue_date']);
        $this->assertSame('GS/27-28/00001', $late['number']);
        $this->assertSame(['GS/26-27/00001'], array_column(db_connect()->table('platform_invoices')->where('fy', '2026-27')->get()->getResultArray(), 'number'));
    }

    // ---- the PDF and its integrity ----------------------------------------------------------------------------------------------------------

    public function testPdfIsStoredOnceCheckedOnReadAndTenantScoped(): void
    {
        $inv = $this->svc()->issueForSubscription($this->sub(1));
        $f = $this->svc()->pdf((int) $inv['id']);
        $this->assertStringStartsWith('%PDF', $f['bytes']);
        $this->assertSame('GS-26-27-00001.pdf', $f['filename']);
        $this->assertSame(hash('sha256', $f['bytes']), $inv['pdf_sha256']);
        try { $this->svc()->pdf((int) $inv['id'], 2); $this->fail('another workspace must not open it'); } catch (\OutOfBoundsException) { $this->assertTrue(true); }
        $this->assertSame((int) $inv['id'], (int) $this->svc()->pdf((int) $inv['id'], 1)['invoice']['id']);

        file_put_contents($this->dir . '/' . $inv['pdf_path'], $f['bytes'] . 'tampered');
        $this->expectException(\DomainException::class);
        $this->svc()->pdf((int) $inv['id']);
    }

    public function testMissingFileIsReportedNotRegenerated(): void
    {
        $inv = $this->svc()->issueForSubscription($this->sub(1));
        unlink($this->dir . '/' . $inv['pdf_path']);
        $this->expectException(\DomainException::class);
        $this->svc()->pdf((int) $inv['id']);
    }

    // ---- the seller (software owner) ----------------------------------------------------------------------------------------------------------

    public function testDefaultsComeFromTheGstCertificateAndAreValid(): void
    {
        $s = $this->svc()->settings();
        $this->assertSame('06ABCFG6551H1ZG', $s['gstin']); $this->assertSame('Gamavis Software Solutions', $s['legal_name']);
        $this->assertSame([], PlatformInvoiceService::validate($s));
        $inv = $this->svc()->issueForSubscription($this->sub(1)); $seller = json_decode($inv['seller'], true);
        $this->assertSame(['Haryana', '06'], [$seller['state_name'], $seller['state_code']]);
    }

    public function testSettingsAreValidatedAndChangesNeverTouchIssuedInvoices(): void
    {
        $s = $this->svc();
        try { $s->saveSettings(['gstin' => '06ABCFG6551H1ZX'], 1); $this->fail('bad checksum'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('GSTIN', $e->getMessage()); }
        try { $s->saveSettings(['state_code' => '27'], 1); $this->fail('state must match the GSTIN'); } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        try { $s->saveSettings(['prefix' => 'TOOLONG'], 1); $this->fail('prefix too long'); } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        $old = $s->issueForSubscription($this->sub(1));
        $s->saveSettings(['prefix' => 'TS', 'sac_code' => '998431', 'gst_rate' => '18', 'legal_name' => 'Gamavis Software Solutions LLP'], 1);
        $new = $s->issueForSubscription($this->sub(2, 59000, 'active', 'pay_2'));
        $this->assertSame('TS/26-27/00001', $new['number'], 'a new prefix starts its own series');
        $this->assertSame('998431', $new['sac']);
        $this->assertSame('Gamavis Software Solutions', json_decode(db_connect()->table('platform_invoices')->where('id', $old['id'])->get()->getRowArray()['seller'], true)['legal_name'], 'an issued invoice keeps the seller it was issued with');
    }

    // ---- e-mail ---------------------------------------------------------------------------------------------------------------------------------

    public function testInvoiceIsEmailedWithThePdfAttached(): void
    {
        $this->svc()->saveSettings(['copy_to' => 'accounts@example.com'], 1);
        $inv = $this->svc()->issueForSubscription($this->sub(1));
        $r = $this->svc()->email((int) $inv['id']);
        $this->assertTrue($r['sent']); $this->assertSame('owner1@example.com', $r['to']);
        $m = $this->mails[0];
        $this->assertSame('owner1@example.com', $m['to']);
        $this->assertStringContainsString('GS/26-27/00001', $m['subject']);
        $this->assertSame('accounts@example.com', $m['bcc'], 'the accountant is copied');
        $this->assertSame('application/pdf', $m['attachments'][0]['mime']); $this->assertStringStartsWith('%PDF', $m['attachments'][0]['content']);
        $this->assertStringContainsString('no GSTIN', $m['text'] . $m['html'], 'a consumer invoice explains how to get a GSTIN on it');
        $row = db_connect()->table('platform_invoices')->where('id', $inv['id'])->get()->getRowArray();
        $this->assertNotNull($row['emailed_at']); $this->assertNull($row['email_error']); $this->assertSame(1, (int) $row['email_attempts']);
    }

    public function testFailedEmailIsRecordedAndCanBeResent(): void
    {
        $inv = $this->svc()->issueForSubscription($this->sub(1));
        PlatformInvoiceService::$mailer = fn () => false;
        $r = $this->svc()->email((int) $inv['id']);
        $this->assertFalse($r['sent']);
        $row = db_connect()->table('platform_invoices')->where('id', $inv['id'])->get()->getRowArray();
        $this->assertNull($row['emailed_at']); $this->assertNotNull($row['email_error']);
        PlatformInvoiceService::$mailer = function (string $to, string $s, string $h, string $t, array $a, ?string $b): bool { $this->mails[] = ['to' => $to]; return true; };
        $this->assertTrue($this->svc()->email((int) $inv['id'])['sent']);
        $row = db_connect()->table('platform_invoices')->where('id', $inv['id'])->get()->getRowArray();
        $this->assertNotNull($row['emailed_at']); $this->assertNull($row['email_error']); $this->assertSame(2, (int) $row['email_attempts']);
    }

    // ---- the admin's reading side ----------------------------------------------------------------------------------------------------------------

    private function seedBook(): void
    {
        $g = $this->gstin('27');
        $this->profile(2, ['legal_name' => 'Bravo Tours Pvt Ltd', 'gstin' => $g, 'state_code' => '27']);
        $s = fn (string $d) => $this->svc(strtotime($d . ' 08:00:00 UTC'));
        $s('2026-09-05')->issueForSubscription($this->sub(1, 59000, 'active', 'pay_a', 'growth', 'monthly'));
        $s('2026-10-02')->issueForSubscription($this->sub(2, 59000, 'active', 'pay_b', 'growth', 'monthly'));            // B2B inter-state
        $s('2026-10-20')->issueForSubscription($this->sub(3, 119000, 'active', 'pay_c', 'pro', 'monthly'));
        $s('2026-10-25')->issueForSubscription($this->sub(1, 590000, 'active', 'admin-x1', 'growth', 'annual'), 'offline');
    }

    public function testMonthFilterAndTotals(): void
    {
        $this->seedBook();
        $r = $this->svc()->list(['month' => '2026-10']);
        $this->assertSame(3, $r['total']);
        $this->assertSame(59000 + 119000 + 590000, $r['totals']['total']);
        $this->assertSame($r['totals']['taxable'] + $r['totals']['gst'], $r['totals']['total']);
        $this->assertSame($r['totals']['cgst'] + $r['totals']['sgst'] + $r['totals']['igst'], $r['totals']['gst']);
        $this->assertSame(1, $this->svc()->list(['month' => '2026-09'])['total']);
        $this->assertSame(4, $this->svc()->list(['fy' => '2026-27'])['total']);
        $this->assertSame(0, $this->svc()->list(['fy' => '2025-26'])['total']);
        $this->assertSame(2, $this->svc()->list(['from' => '2026-10-10', 'to' => '2026-10-31'])['total']);
    }

    public function testFilters(): void
    {
        $this->seedBook(); $s = $this->svc();
        $this->assertSame(1, $s->list(['buyer_type' => 'b2b'])['total']);
        $this->assertSame(3, $s->list(['buyer_type' => 'b2c'])['total']);
        $this->assertSame(1, $s->list(['supply_type' => 'inter'])['total']);
        $this->assertSame(1, $s->list(['plan' => 'pro'])['total']);
        $this->assertSame(1, $s->list(['source' => 'offline'])['total']);
        $this->assertSame(1, $s->list(['pos' => '27'])['total']);
        $this->assertSame(1, $s->list(['q' => 'Bravo'])['total']);
        $this->assertSame(1, $s->list(['q' => 'pay_c'])['total']);
        $this->assertSame(2, $s->list(['q' => 'owner1@example.com'])['total']);
        $this->assertSame(2, $s->list(['min' => '1000', 'max' => '10000'])['total'], 'amount range in rupees: INR 1,190 and INR 5,900');
        $this->assertSame(0, $s->list(['email' => 'sent'])['total']);
        $this->assertSame(4, $s->list(['email' => 'pending'])['total']);
        $first = $s->list(['sort' => 'oldest'])['rows'][0]['number']; $this->assertSame('GS/26-27/00001', $first);
        $this->assertSame(4, $s->list(['per_page' => 5, 'page' => 1])['total']);
        $this->assertSame([], $s->list(['per_page' => 5, 'page' => 2])['rows'], 'page 2 of 4 invoices at 5 per page is empty');
    }

    public function testSummaryGroupsB2bByGstinAndB2cByStateForTheAccountant(): void
    {
        $this->seedBook();
        $m = $this->svc()->summary(['month' => '2026-10']);
        $this->assertCount(1, $m['b2b']); $this->assertSame('Bravo Tours Pvt Ltd', $m['b2b'][0]['name']); $this->assertSame(59000, $m['b2b'][0]['total']); $this->assertSame(9000, $m['b2b'][0]['igst']);
        $this->assertSame(2, $m['b2c'][0]['n']); $this->assertSame('Haryana', $m['b2c'][0]['place_name']);
        $this->assertSame($m['totals']['total'], array_sum(array_column($m['by_plan'], 'total')));
        $this->assertSame($m['totals']['total'], array_sum(array_column($m['by_supply'], 'total')));
        $this->assertSame(['2026-10'], array_column($m['by_month'], 'month'));
    }

    public function testCsvIsAccountantReadyAndSafeInASpreadsheet(): void
    {
        $this->seedBook();
        db_connect()->table('platform_invoices')->where('seq', 1)->update(['buyer_name' => '=HYPERLINK("http://evil","x")']);
        $csv = $this->svc()->csv(['fy' => '2026-27']);
        $this->assertStringStartsWith("\xEF\xBB\xBF" . '"Invoice no","Date"', $csv);
        $lines = array_values(array_filter(explode("\r\n", $csv)));
        $this->assertCount(5, $lines, 'header + 4 invoices');
        $this->assertStringContainsString('"\'=HYPERLINK', $csv, 'a formula typed by a customer is neutralised');
        $this->assertStringContainsString('"500.00","45.00","45.00","0.00","90.00","590.00"', $this->svc()->csv(['month' => '2026-09']), 'taxable, CGST, SGST, IGST, total GST, invoice total in rupees');
        $this->assertStringContainsString('Offline (recorded by hand)', $csv);
    }

    public function testZipHoldsEveryPdfOfTheMonth(): void
    {
        $this->seedBook();
        $z = $this->svc()->zip(['month' => '2026-10']);
        $this->assertSame(3, $z['count']); $this->assertSame([], $z['skipped']);
        $zip = new \ZipArchive(); $zip->open($z['path']); $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
        $zip->close(); @unlink($z['path']);
        sort($names); $this->assertSame(['GS-26-27-00002.pdf', 'GS-26-27-00003.pdf', 'GS-26-27-00004.pdf'], $names);
    }

    // ---- paid periods without an invoice ------------------------------------------------------------------------------------------------------

    public function testMissingInvoicesAreListedAndCanBeIssued(): void
    {
        $a = $this->sub(1, 59000, 'active', 'pay_old'); $off = $this->sub(2, 59000, 'active', 'admin-77'); $this->sub(3, 0, 'active', 'admin-0'); $this->sub(3, 59000, 'created', 'order_unpaid');
        $m = $this->svc()->missing();
        $this->assertSame([$a, $off], array_column($m, 'id'));
        $this->assertSame([false, true], array_column($m, 'offline'));
        $r = $this->svc()->issueMissing([$a, $off], 1);
        $this->assertSame(2, $r['issued']); $this->assertSame([], $this->svc()->missing());
        $this->assertSame(2, count($this->mails), 'issuing also e-mails them');
        $this->assertSame(['online', 'offline'], array_column(db_connect()->table('platform_invoices')->orderBy('id')->get()->getResultArray(), 'source'));
    }

    // ---- wiring into payments -----------------------------------------------------------------------------------------------------------------------

    public function testAPaymentIssuesTheInvoiceAutomatically(): void
    {
        PlatformInvoiceService::$enableInTests = true;
        $this->svc()->saveSettings(['auto_email' => '0'], 1);          // e-mail goes through the shutdown hook, which is not what is under test here
        $db = db_connect();
        $db->table('subscriptions')->insert(['tenant_id' => 1, 'razorpay_sub_id' => 'order_AUTO1', 'razorpay_customer_id' => 'c', 'plan' => 'growth', 'amount_paise' => 59000, 'billing_cycle' => 'monthly', 'status' => 'created', 'created_at' => '2026-10-11 10:00:00', 'updated_at' => '2026-10-11 10:00:00']);
        $r = (new BillingService())->confirmOrderPaid('order_AUTO1', 'pay_AUTO1', 59000);
        $this->assertSame('activated', $r);
        $inv = $db->table('platform_invoices')->get()->getRowArray();
        $this->assertNotNull($inv, 'the invoice exists the moment the payment is confirmed');
        $this->assertSame('pay_AUTO1', $inv['payment_ref']); $this->assertSame(59000, (int) $inv['total']);
        (new BillingService())->confirmOrderPaid('order_AUTO1', 'pay_AUTO1', 59000);       // the webhook and the browser both report it
        $this->assertSame(1, $db->table('platform_invoices')->countAllResults(), 'never a second invoice for the same payment');
    }

    public function testNoInvoiceWorkHappensInOtherTestsUnlessSwitchedOn(): void
    {
        PlatformInvoiceService::onPaid($this->sub(1));
        $this->assertSame(0, db_connect()->table('platform_invoices')->countAllResults());
    }
}
