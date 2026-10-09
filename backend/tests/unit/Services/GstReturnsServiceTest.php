<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\Docs\BusinessProfileService;
use App\Services\Billing\Docs\GstExportService;
use App\Services\Billing\Docs\GstFilingReminder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** GSTR-3B + HSN + filing tracker against real tables. MySQL group. */
#[Group('mysql')]
final class GstReturnsServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['gst_filings', 'gst_itc_entries', 'invoices', 'business_profiles', 'travel_trigger_log', 'notifications', 'users', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        foreach ([[5, 'owner'], [6, 'agent']] as [$id, $role]) { $db->table('users')->insert(['id' => $id, 'tenant_id' => 1, 'name' => "U$id", 'email' => "u$id@x.test", 'password_hash' => 'x', 'role' => $role, 'created_at' => '2026-01-01 00:00:00']); }
        (new BusinessProfileService())->save(1, ['legal_name' => 'Demo Travels Pvt Ltd', 'gstin' => '27ABCDE1234F1Z0', 'address_line1' => 'Shop 12', 'city' => 'Mumbai', 'pincode' => '400093', 'phone' => '+919820011111', 'email' => 'h@d.in', 'sac_code' => '998554']);
    }

    private function inv(string $date, array $o = []): int
    {
        $this->seq++;
        $db = db_connect();
        $db->table('invoices')->insert(array_merge(['tenant_id' => 1, 'booking_id' => 1, 'doc_type' => 'tax_invoice', 'number' => 'INV/26-27/' . str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT), 'fy' => '2026-27', 'seq' => $this->seq, 'issue_date' => $date, 'status' => 'issued',
            'seller' => '{}', 'buyer' => json_encode(['name' => 'Rohit', 'gstin' => null]), 'lines' => '{}', 'place_of_supply' => '27', 'supply_type' => 'intra', 'sac' => '998554', 'taxable_value' => 10_000_000, 'gst_rate' => 5,
            'cgst' => 250_000, 'sgst' => 250_000, 'igst' => 0, 'tcs' => 0, 'total' => 10_500_000, 'share_token' => bin2hex(random_bytes(16)), 'created_at' => $date . ' 10:00:00'], $o));
        return (int) $db->insertID();
    }

    private function svc(?int $now = null): GstExportService { return new GstExportService($now ?? strtotime('2026-11-25 10:00:00')); }

    public function testThreeBMatchesGstr1AndTotalsThePeriod(): void
    {
        $a = $this->inv('2026-10-05'); $this->inv('2026-10-12');
        $this->inv('2026-10-20', ['doc_type' => 'credit_note', 'related_id' => $a, 'taxable_value' => 4_000_000, 'cgst' => 100_000, 'sgst' => 100_000, 'total' => 4_200_000]);
        $this->inv('2026-09-30');                                                                  // another month: never included
        $r = $this->svc()->gstr3b(1, '2026-10');
        $this->assertSame(['igst' => 0, 'cgst' => 400_000, 'sgst' => 400_000], $r['outward']['liability']);
        $this->assertSame(16_000_000, $r['outward']['taxable']['taxable']);
        $this->assertTrue($r['reconciliation']['matches_gstr1'], json_encode($r['reconciliation']));
        $this->assertSame(800_000, $r['payment']['cash_total']);
        $this->assertSame('102026', $r['json']['ret_period']);
    }

    public function testItcIsValidatedStoredAndReducesTheCash(): void
    {
        $this->inv('2026-10-05', ['gst_rate' => 18, 'cgst' => 900_000, 'sgst' => 900_000, 'total' => 11_800_000]);
        foreach ([['itc_igst' => -1], ['itc_cgst' => 2_000_000_000], ['period' => 'x']] as $bad) {
            try { $this->svc()->saveItc(1, $bad['period'] ?? '2026-10', $bad); $this->fail('accepted ' . json_encode($bad)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $r = $this->svc()->saveItc(1, '2026-10', ['itc_igst' => 3000, 'itc_cgst' => 5000, 'itc_sgst' => 4000, 'rev_cgst' => 500, 'notes' => 'From supplier invoices'], 5);
        $this->assertSame(['igst' => 300_000, 'cgst' => 450_000, 'sgst' => 400_000], $r['itc']['net']);
        $this->assertSame(['igst' => 0, 'cgst' => 150_000, 'sgst' => 500_000], $r['payment']['cash']);     // CGST 900k - IGST credit 300k - CGST credit 450k = 150k cash; SGST 900k - SGST credit 400k = 500k cash
        $this->assertSame($this->svc()->itcEntry(1, '2026-10')['itc']['cgst'], 500_000);
    }

    public function testClosingCreditCarriesForwardUnlessAnOpeningBalanceIsTyped(): void
    {
        $this->inv('2026-10-05');                                                                  // October: ₹5,000 CGST + ₹5,000 SGST
        $this->svc()->saveItc(1, '2026-10', ['itc_cgst' => 20_000, 'itc_sgst' => 0]);               // ₹20,000 CGST credit: ₹15,000 left over
        $this->inv('2026-11-05');
        $this->svc()->saveItc(1, '2026-11', ['itc_cgst' => 0]);                                      // November: no new ITC, nothing typed as opening
        $nov = $this->svc()->gstr3b(1, '2026-11');
        $this->assertSame([['igst' => 0, 'cgst' => 1_750_000, 'sgst' => 0], 'carried'], [$nov['itc']['opening'], $nov['itc']['opening_source']]);   // 20,000 credit - 2,500 used in October
        $this->assertSame(['igst' => 0, 'cgst' => 0, 'sgst' => 250_000], $nov['payment']['cash']);        // carried CGST credit pays the CGST; SGST is cash
        $this->assertSame(['igst' => 0, 'cgst' => 1_500_000, 'sgst' => 0], $nov['payment']['closing_credit']);
        // a typed opening balance (e.g. first month on TravelPilot) overrides the carry-forward
        $this->svc()->saveItc(1, '2026-11', ['itc_cgst' => 0, 'opening_set' => 1, 'open_cgst' => 100]);
        $this->assertSame(['entered', 10_000], [$this->svc()->gstr3b(1, '2026-11')['itc']['opening_source'], $this->svc()->gstr3b(1, '2026-11')['itc']['opening']['cgst']]);
    }

    public function testFirstEverMonthHasNoCarryForward(): void
    {
        $this->inv('2026-10-05');
        $r = $this->svc()->gstr3b(1, '2026-10');
        $this->assertSame([['igst' => 0, 'cgst' => 0, 'sgst' => 0], 'none'], [$r['itc']['opening'], $r['itc']['opening_source']]);
    }

    public function testNoGstinMeansNoReturn(): void
    {
        $this->expectException(\DomainException::class);
        (new GstExportService())->gstr3b(2, '2026-10');
    }

    public function testGstr1JsonCarriesTheHsnTableAndTheFilesAreServable(): void
    {
        $this->inv('2026-10-05'); $this->inv('2026-10-06', ['gst_rate' => 18, 'cgst' => 900_000, 'sgst' => 900_000, 'total' => 11_800_000]);
        $g1 = json_decode($this->svc()->file(1, '2026-10', 'gstr1.json')['body'], true);
        $this->assertCount(2, $g1['hsn']['data']);
        $this->assertSame(['998554', 998554 === 0 ? 0 : 5.0], [$g1['hsn']['data'][0]['hsn_sc'], $g1['hsn']['data'][0]['rt']]);
        $csv = $this->svc()->file(1, '2026-10', 'hsn.csv');
        $this->assertStringContainsString('998554', $csv['body']);
        $this->assertSame('HSN_summary_2026-10.csv', $csv['filename']);
        $b = $this->svc()->file(1, '2026-10', 'gstr3b.json');
        $this->assertSame('GSTR3B_27ABCDE1234F1Z0_2026-10.json', $b['filename']);
        $this->assertSame('27ABCDE1234F1Z0', json_decode($b['body'], true)['gstin']);
        $this->assertSame(2, count($this->svc()->hsn(1, '2026-10')['rows']));
    }

    public function testFilingTrackerStatusValidationAndEditing(): void
    {
        $f = $this->svc()->filings(1, 2026, '2026-11-25');
        $oct = array_values(array_filter($f['months'], fn ($m) => $m['period'] === '2026-10'))[0];
        $this->assertSame(['overdue', 'overdue'], [$oct['GSTR1']['status'], $oct['GSTR3B']['status']]);
        $this->assertSame(['2026-11-11', '2026-11-20'], [$oct['GSTR1']['due'], $oct['GSTR3B']['due']]);
        $this->assertSame([], array_filter($f['months'], fn ($m) => $m['period'] === '2026-11'));          // a month that has not ended has nothing to file
        foreach ([['filed_on' => '2099-01-01'], ['filed_on' => 'x'], ['filed_on' => '2026-11-22', 'arn' => 'short']] as $bad) {
            try { $this->svc()->recordFiling(1, '2026-10', 'GSTR3B', $bad); $this->fail('accepted ' . json_encode($bad)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->svc()->recordFiling(1, '2026-10', 'GSTR3B', ['filed_on' => '2026-11-22', 'arn' => 'aa2910251234567', 'tax_paid_rs' => 8000], 5);
        $st = array_values(array_filter($this->svc()->filings(1, 2026, '2026-11-25')['months'], fn ($m) => $m['period'] === '2026-10'))[0];
        $this->assertSame(['filed', 2, 'AA2910251234567', 800_000], [$st['GSTR3B']['status'], $st['GSTR3B']['days'], $st['GSTR3B']['arn'], $st['GSTR3B']['tax_paid_cash']]);
        $this->assertSame('overdue', $st['GSTR1']['status']);                                              // the other return is independent
        $this->svc()->removeFiling(1, '2026-10', 'GSTR3B', 5);
        $this->assertSame('overdue', array_values(array_filter($this->svc()->filings(1, 2026, '2026-11-25')['months'], fn ($m) => $m['period'] === '2026-10'))[0]['GSTR3B']['status']);
        $this->assertSame([], array_filter($this->svc()->filings(2, 2026, '2026-11-25')['months'], fn ($m) => ! empty($m['GSTR3B']['filed_on'])));   // tenant isolation
    }

    public function testRemindersGoToOwnersOnceAndStopWhenFiled(): void
    {
        $now = strtotime('2026-11-18 09:00:00');                                                          // 2 days before GSTR-3B (20 Nov); GSTR-1 (11 Nov) already overdue
        $n = (new GstFilingReminder($now))->run();
        $this->assertSame(2, $n);                                                                          // GSTR-1 overdue + GSTR-3B due soon, to ONE owner (the agent is not notified)
        $this->assertSame([5], array_unique(array_map(fn ($r) => (int) $r['user_id'], db_connect()->table('notifications')->where('type', 'gst_due')->get()->getResultArray())));
        $this->assertSame(0, (new GstFilingReminder($now + 3600))->run());                                  // the hourly cron must not repeat itself
        $this->svc()->recordFiling(1, '2026-10', 'GSTR1', ['filed_on' => '2026-11-10']);          // (the service clock is 25 Nov, so these dates are in the past)
        $this->svc()->recordFiling(1, '2026-10', 'GSTR3B', ['filed_on' => '2026-11-17']);
        $this->assertSame(0, (new GstFilingReminder(strtotime('2026-11-19 09:00:00')))->run());            // everything filed: silence
    }
}
