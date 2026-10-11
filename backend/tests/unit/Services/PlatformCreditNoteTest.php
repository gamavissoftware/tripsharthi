<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\Docs\Gst;
use App\Services\Billing\PlatformCreditNoteService;
use App\Services\Billing\PlatformInvoiceService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Credit notes (refunds) against TripSarthi's own GST invoices (group "mysql"). */
#[Group('mysql')]
final class PlatformCreditNoteTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const REASON = 'Customer cancelled within the refund window';
    private string $dir = '';
    private array $mails = [];
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = strtotime('2026-10-11 12:00:00 UTC');
        $this->dir = sys_get_temp_dir() . '/tscn-' . bin2hex(random_bytes(4));
        putenv('PLATFORM_INVOICE_STORAGE_DIR=' . $this->dir); $_ENV['PLATFORM_INVOICE_STORAGE_DIR'] = $this->dir; $_SERVER['PLATFORM_INVOICE_STORAGE_DIR'] = $this->dir;
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['platform_credit_notes', 'platform_invoices', 'platform_doc_sequences', 'partner_commissions', 'partner_events', 'partners', 'subscriptions', 'business_profiles', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query("DELETE FROM platform_settings WHERE setting_key LIKE 'inv\\_%'");
        foreach ([1 => 'Alpha Travels', 2 => 'Bravo Tours'] as $id => $name) {
            $db->table('tenants')->insert(['id' => $id, 'name' => $name, 'slug' => "t$id", 'plan' => 'growth', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
            $db->table('users')->insert(['id' => $id, 'tenant_id' => $id, 'name' => "Owner $id", 'email' => "owner{$id}@example.com", 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        }
        $this->mails = [];
        PlatformInvoiceService::$mailer = function (string $to, string $subject, string $html, string $text, array $attachments, ?string $bcc): bool { $this->mails[] = compact('to', 'subject', 'html', 'text', 'attachments', 'bcc'); return true; };
    }

    protected function tearDown(): void
    {
        PlatformInvoiceService::$mailer = null;
        db_connect()->query("DELETE FROM platform_settings WHERE setting_key LIKE 'inv\\_%'");
        foreach (glob($this->dir . '/*/*') ?: [] as $f) { @unlink($f); } foreach (glob($this->dir . '/*') ?: [] as $d) { @rmdir($d); } @rmdir($this->dir);
        putenv('PLATFORM_INVOICE_STORAGE_DIR'); unset($_ENV['PLATFORM_INVOICE_STORAGE_DIR'], $_SERVER['PLATFORM_INVOICE_STORAGE_DIR']);
        db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown();
    }

    private function cn(?int $now = null): PlatformCreditNoteService { return new PlatformCreditNoteService($now ?? $this->now); }
    private function inv(?int $now = null): PlatformInvoiceService { return new PlatformInvoiceService($now ?? $this->now); }

    /** An invoice with GST charged on top: price 3,999 + GST 719.82 = 4,718.82 (Haryana buyer: CGST + SGST). */
    private function invoice(int $tenant = 1, int $total = 471_882, int $taxable = 399_900, int $gst = 71_982, string $ref = 'pay_1', bool $exclusive = true): array
    {
        db_connect()->table('subscriptions')->insert(['tenant_id' => $tenant, 'razorpay_sub_id' => $ref, 'razorpay_customer_id' => 'c', 'plan' => 'growth', 'amount_paise' => $total, 'taxable_paise' => $exclusive ? $taxable : null, 'gst_paise' => $exclusive ? $gst : null, 'gst_rate' => $exclusive ? 18 : null,
            'billing_cycle' => 'monthly', 'status' => 'active', 'current_period_start' => '2026-10-11 10:00:00', 'current_period_end' => '2026-11-10 10:00:00', 'created_at' => '2026-10-11 10:00:00', 'updated_at' => '2026-10-11 10:00:00']);
        return $this->inv()->issueForSubscription((int) db_connect()->insertID());
    }

    private function interStateInvoice(): array
    {
        $p = '27AABCB4821K1Z';
        db_connect()->table('business_profiles')->insert(['tenant_id' => 2, 'legal_name' => 'Bravo Tours Pvt Ltd', 'gstin' => $p . Gst::checkChar($p), 'state_code' => '27', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
        return $this->invoice(2, 471_882, 399_900, 71_982, 'pay_2');
    }

    public function testFullCreditReversesTheInvoiceExactlyInItsOwnSeries(): void
    {
        $i = $this->invoice();
        $n = $this->cn()->issue((int) $i['id'], null, self::REASON, 'rfnd_ABC', 1);
        $this->assertSame('CN/26-27/00001', $n['number']);
        $this->assertSame('GS/26-27/00001', $n['invoice_number']);
        $this->assertSame([399_900, 35_991, 35_991, 0, 471_882], [(int) $n['taxable_value'], (int) $n['cgst'], (int) $n['sgst'], (int) $n['igst'], (int) $n['total']]);
        $this->assertSame('rfnd_ABC', $n['refund_ref']);
        $this->assertSame(0, $this->cn()->remaining((int) $i['id'])['total']);
        $this->expectException(\DomainException::class);
        $this->cn()->issue((int) $i['id'], null, self::REASON, null, 1);
    }

    public function testPartialCreditsThenTheRemainderLeaveNoStrayPaisa(): void
    {
        $i = $this->invoice(); $id = (int) $i['id'];
        $a = $this->cn()->issue($id, 100_000, self::REASON, null, 1);                 // refund INR 1,000 incl. GST
        $this->assertSame([84_746, 7_627, 7_627, 0, 100_000], [(int) $a['taxable_value'], (int) $a['cgst'], (int) $a['sgst'], (int) $a['igst'], (int) $a['total']]);
        $this->assertSame(371_882, $this->cn()->remaining($id)['total']);
        $b = $this->cn()->issue($id, 100_001, self::REASON, null, 1);                 // an odd amount
        $this->assertSame((int) $b['total'], (int) $b['taxable_value'] + (int) $b['cgst'] + (int) $b['sgst'] + (int) $b['igst'], 'each note adds up to its own total');
        $c = $this->cn()->issue($id, null, self::REASON, null, 1);                    // the whole remainder
        $sum = fn (string $k) => (int) $a[$k] + (int) $b[$k] + (int) $c[$k];
        $this->assertSame([399_900, 35_991, 35_991, 0, 471_882], [$sum('taxable_value'), $sum('cgst'), $sum('sgst'), $sum('igst'), $sum('total')], 'three notes together equal the invoice to the paisa');
        $this->assertSame(['CN/26-27/00001', 'CN/26-27/00002', 'CN/26-27/00003'], [$a['number'], $b['number'], $c['number']]);
    }

    public function testInterStateInvoiceCreditsIgstOnly(): void
    {
        $i = $this->interStateInvoice();
        $n = $this->cn()->issue((int) $i['id'], 118_000, self::REASON, null, 1);
        $this->assertSame([100_000, 0, 0, 18_000], [(int) $n['taxable_value'], (int) $n['cgst'], (int) $n['sgst'], (int) $n['igst']]);
        $this->assertSame('b2b', $n['buyer_type']); $this->assertSame('27', $n['place_of_supply']);
    }

    public function testAnOlderInclusiveInvoiceCanBeCreditedToo(): void
    {
        $i = $this->invoice(1, 59_000, 0, 0, 'pay_old', false);
        $this->assertSame('inclusive', $i['price_basis']);
        $n = $this->cn()->issue((int) $i['id'], null, self::REASON, null, 1);
        $this->assertSame([50_000, 4_500, 4_500, 59_000], [(int) $n['taxable_value'], (int) $n['cgst'], (int) $n['sgst'], (int) $n['total']]);
    }

    public function testRefusals(): void
    {
        $i = $this->invoice(); $id = (int) $i['id'];
        foreach ([[0, 'zero'], [471_883, 'more than the invoice'], [-5, 'negative']] as [$amount, $why]) {
            try { $this->cn()->issue($id, $amount, self::REASON, null, 1); $this->fail("accepted $why"); } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        }
        try { $this->cn()->issue($id, 1000, 'too short', null, 1); $this->fail('a reason is required'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('reason', $e->getMessage()); }
        $this->cn()->issue($id, 400_000, self::REASON, null, 1);
        try { $this->cn()->issue($id, 100_000, self::REASON, null, 1); $this->fail('cannot exceed the remainder'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('718.82', $e->getMessage(), 'it says how much can still be credited'); }
        $this->assertSame(0, db_connect()->table('platform_credit_notes')->where('total', 100_000)->countAllResults(), 'nothing was saved by a refused attempt');
        $this->expectException(\OutOfBoundsException::class);
        $this->cn()->issue(9999, null, self::REASON, null, 1);
    }

    public function testNumberingIsGapFreeAndRestartsEachFinancialYear(): void
    {
        $i = $this->invoice();
        $a = $this->cn()->issue((int) $i['id'], 10_000, self::REASON, null, 1);
        $late = $this->cn(strtotime('2027-03-31 19:00:00 UTC'))->issue((int) $i['id'], 10_000, self::REASON, null, 1);   // 1 April in India
        $this->assertSame(['CN/26-27/00001', 'CN/27-28/00001'], [$a['number'], $late['number']]);
        $this->assertSame('GS/26-27/00001', $this->inv()->list([])['rows'][0]['number'], 'the invoice series is untouched');
    }

    public function testPdfIsStoredCheckedAndTenantScoped(): void
    {
        $i = $this->invoice(); $n = $this->cn()->issue((int) $i['id'], 50_000, self::REASON, 'rfnd_1', 1);
        $f = $this->cn()->pdf((int) $n['id']);
        $this->assertStringStartsWith('%PDF', $f['bytes']); $this->assertSame('CN-26-27-00001.pdf', $f['filename']);
        $this->assertSame(hash('sha256', $f['bytes']), $n['pdf_sha256']);
        try { $this->cn()->pdf((int) $n['id'], 2); $this->fail('another workspace must not open it'); } catch (\OutOfBoundsException) { $this->assertTrue(true); }
        file_put_contents($this->dir . '/' . $n['pdf_path'], $f['bytes'] . 'x');
        $this->expectException(\DomainException::class);
        $this->cn()->pdf((int) $n['id']);
    }

    public function testCreditNoteIsEmailedWithThePdfAndTheAccountantCopy(): void
    {
        $this->inv()->saveSettings(['copy_to' => 'accounts@example.com'], 1);
        $i = $this->invoice(); $n = $this->cn()->issue((int) $i['id'], 50_000, self::REASON, 'rfnd_1', 1);
        $r = $this->cn()->email((int) $n['id']);
        $this->assertTrue($r['sent']);
        $m = $this->mails[0];
        $this->assertSame('owner1@example.com', $m['to']); $this->assertSame('accounts@example.com', $m['bcc']);
        $this->assertStringContainsString('CN/26-27/00001', $m['subject']); $this->assertSame('application/pdf', $m['attachments'][0]['mime']);
        $this->assertStringContainsString('GS/26-27/00001', $m['text']);
        $this->assertNotNull(db_connect()->table('platform_credit_notes')->get()->getRowArray()['emailed_at']);
    }

    public function testListTotalsSummaryAndTheInvoiceRowShowWhatWasCredited(): void
    {
        $i = $this->invoice(); $this->interStateInvoice();
        $this->cn()->issue((int) $i['id'], 100_000, self::REASON, null, 1);
        $this->cn()->issue((int) $this->inv()->list(['q' => 'Bravo'])['rows'][0]['id'], null, self::REASON, 'rfnd_9', 1);
        $l = $this->cn()->list(['month' => '2026-10']);
        $this->assertSame(2, $l['total']); $this->assertSame(100_000 + 471_882, $l['totals']['total']);
        $this->assertSame(1, $this->cn()->list(['buyer_type' => 'b2b'])['total']); $this->assertSame(1, $this->cn()->list(['supply_type' => 'inter'])['total']);
        $this->assertSame(1, $this->cn()->list(['q' => 'rfnd_9'])['total']); $this->assertSame(0, $this->cn()->list(['month' => '2026-09'])['total']);
        $sum = $this->inv()->summary(['month' => '2026-10']);
        $this->assertSame(2 * 471_882, $sum['totals']['total']); $this->assertSame(100_000 + 471_882, $sum['credit_notes']['total']);
        $this->assertSame(2 * 471_882 - 100_000 - 471_882, $sum['net']['total'], 'the net is invoices minus credit notes');
        $this->assertSame($sum['net']['taxable'] + $sum['net']['gst'], $sum['net']['total']);
        $this->assertCount(1, $sum['cn_b2b']); $this->assertCount(1, $sum['cn_b2c']);
        $credited = array_column($this->inv()->list(['sort' => 'oldest'])['rows'], 'credited', 'number');
        $this->assertSame(['GS/26-27/00001' => 100_000, 'GS/26-27/00002' => 471_882], $credited);
    }

    public function testCsvAndZipIncludeCreditNotesWithNegativeAmounts(): void
    {
        $i = $this->invoice(); $this->cn()->issue((int) $i['id'], 100_000, self::REASON, 'rfnd_1', 1);
        $csv = $this->inv()->csv(['month' => '2026-10']); $lines = array_values(array_filter(explode("\r\n", $csv)));
        $this->assertCount(3, $lines, 'header + invoice + credit note');
        $this->assertStringStartsWith('"Credit note","CN/26-27/00001"', $lines[2]);
        $this->assertStringContainsString('"-1000.00"', $lines[2]); $this->assertStringContainsString('"GS/26-27/00001"', $lines[2]);
        $z = $this->inv()->zip(['month' => '2026-10']); $this->assertSame(2, $z['count']); @unlink($z['path']);
    }

    public function testACustomerSeesOnlyTheirOwnCreditNotes(): void
    {
        $i = $this->invoice(); $this->interStateInvoice();
        $this->cn()->issue((int) $i['id'], 50_000, self::REASON, null, 1);
        $this->assertCount(1, $this->cn()->forTenant(1)); $this->assertCount(0, $this->cn()->forTenant(2));
    }

    // ---- partner commission -----------------------------------------------------------------------------------------------------------------

    private function commission(int $subId, string $status = 'pending'): int
    {
        $db = db_connect();
        if (! $db->table('partners')->where('id', 1)->countAllResults()) { $db->table('partners')->insert(['id' => 1, 'name' => 'Diksha', 'email' => 'p@example.com', 'code' => 'DIKSHA1', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('partner_commissions')->insert(['partner_id' => 1, 'tenant_id' => 1, 'subscription_id' => $subId, 'plan' => 'growth', 'cycle' => 'monthly', 'base_paise' => 399_900, 'rate_pct' => 20, 'amount_paise' => 79_980, 'status' => $status, 'earned_at' => '2026-10-11 10:00:00', 'payable_after' => '2026-11-10 10:00:00', 'created_at' => '2026-10-11 10:00:00']);
        return (int) $db->insertID();
    }

    public function testAFullRefundVoidsThePendingPartnerCommissionButAPartialDoesNot(): void
    {
        $i = $this->invoice(); $cid = $this->commission((int) $i['subscription_id']);
        $part = $this->cn()->issue((int) $i['id'], 100_000, self::REASON, null, 1);
        $this->assertSame('none', $part['commission']);
        $this->assertSame('pending', db_connect()->table('partner_commissions')->where('id', $cid)->get()->getRowArray()['status']);
        $full = $this->cn()->issue((int) $i['id'], null, self::REASON, null, 1);
        $this->assertSame('voided', $full['commission']);
        $row = db_connect()->table('partner_commissions')->where('id', $cid)->get()->getRowArray();
        $this->assertSame('void', $row['status']); $this->assertStringContainsString($full['number'], $row['void_reason']);
    }

    public function testACommissionAlreadyPaidOutIsLeftAloneAndReported(): void
    {
        $i = $this->invoice(); $cid = $this->commission((int) $i['subscription_id'], 'paid');
        $full = $this->cn()->issue((int) $i['id'], null, self::REASON, null, 1);
        $this->assertSame('paid_out', $full['commission']);
        $this->assertSame('paid', db_connect()->table('partner_commissions')->where('id', $cid)->get()->getRowArray()['status']);
    }

    public function testEveryCreditNoteCarriesAReasonCategoryAndOnlyOtherNeedsAWrittenNote(): void
    {
        $i = $this->invoice(); $id = (int) $i['id'];
        $a = $this->cn()->issue($id, 10_000, '', null, 1, 'duplicate_payment');
        $this->assertSame(['duplicate_payment', 'Duplicate payment'], [$a['reason_code'], $a['reason']]);
        $b = $this->cn()->issue($id, 10_000, 'paid twice by mistake on 11 Oct', 'rfnd_77', 1, 'duplicate_payment');
        $this->assertSame('Duplicate payment: paid twice by mistake on 11 Oct', $b['reason']);
        foreach (['wrong_details', 'payment_dispute', 'billing_error'] as $code) { $this->assertSame($code, $this->cn()->issue($id, 1_000, '', null, 1, $code)['reason_code']); }
        try { $this->cn()->issue($id, 1_000, 'short', null, 1, 'other'); $this->fail('"other" needs a written reason'); } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        try { $this->cn()->issue($id, 1_000, 'a long enough note here', null, 1, 'customer_changed_mind'); $this->fail('an unknown category is refused'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('why', $e->getMessage()); }
        $this->assertSame(5, db_connect()->table('platform_credit_notes')->countAllResults(), 'refused attempts saved nothing');
        $this->assertSame(['wrong_details', 'duplicate_payment', 'payment_dispute', 'billing_error', 'other'], array_keys(PlatformCreditNoteService::REASONS));
    }

    public function testCreditPrefixMustDifferFromTheInvoicePrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->inv()->saveSettings(['credit_prefix' => 'GS'], 1);
    }
}
