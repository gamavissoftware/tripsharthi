<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\Docs\TcsReportService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class TcsReportServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const NOW = 1_791_439_200;   // 2026-10-08

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['tcs_challans', 'booking_payments', 'bookings', 'contacts', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => '=Rohit', 'status' => 'new', 'source' => 'manual', 'created_at' => '2026-01-01 00:00:00']);
    }

    /** ₹1,05,000 package incl. ₹2,000 TCS; two instalments paid in Oct and Nov. */
    private function booking(int $tenant = 1, string $status = 'confirmed'): int
    {
        $db = db_connect();
        $db->table('bookings')->insert(['tenant_id' => $tenant, 'contact_id' => $tenant === 1 ? 1 : null, 'booking_ref' => 'TP-' . random_int(1000, 9999), 'title' => 'Bali', 'status' => $status, 'is_international' => 1,
            'subtotal' => 10_000_000, 'gst_amount' => 500_000, 'tcs_amount' => 210_000, 'total_amount' => 10_710_000, 'created_at' => '2026-09-01 00:00:00']);
        $id = (int) $db->insertID();
        foreach ([['2026-10-05 10:00:00', 5_355_000], ['2026-11-05 10:00:00', 5_355_000]] as [$at, $amt]) {
            $db->table('booking_payments')->insert(['tenant_id' => $tenant, 'booking_id' => $id, 'label' => 'x', 'amount' => $amt, 'status' => 'paid', 'paid_at' => $at, 'created_at' => '2026-09-01 00:00:00']);
        }
        return $id;
    }

    private function svc(): TcsReportService { return new TcsReportService(self::NOW); }

    public function testReportSplitsByMonthAndFlagsMissingPanAndOverdue(): void
    {
        $this->booking();
        $r = $this->svc()->report(1, 2026, 3);                    // Oct-Dec 2026
        $this->assertSame([105_000, 105_000, 0], array_column($r['months'], 'collected'));
        $this->assertSame(210_000, $r['totals']['tcs']);
        $this->assertSame(2.0, $r['rows'][0]['rate']);
        $this->assertSame(['due', 'due', 'nil'], array_column($r['months'], 'status'));   // Oct due 7 Nov: today is 8 Oct
        $this->assertStringContainsString('no valid customer PAN', implode(' ', $r['warnings']));
        $this->assertSame([], $this->svc()->report(1, 2026, 1)['rows']);                  // earlier quarter is empty
    }

    public function testOverdueWhenTheDueDatePassedWithoutAChallan(): void
    {
        $this->booking();
        $late = new TcsReportService(strtotime('2026-11-20'));
        $this->assertSame('overdue', $late->report(1, 2026, 3)['months'][0]['status']);
        $this->assertStringContainsString('not fully deposited', implode(' ', $late->report(1, 2026, 3)['warnings']));
    }

    public function testChallansReduceOutstandingAndAreValidated(): void
    {
        $this->booking();
        $this->svc()->addChallan(1, ['period' => '2026-10', 'amount_rs' => 1050, 'bsr_code' => '0510308', 'challan_serial' => '00012', 'deposit_date' => '2026-10-07'], 5);
        $m = $this->svc()->report(1, 2026, 3)['months'][0];
        $this->assertSame([105_000, 0, 'paid'], [$m['deposited'], $m['outstanding'], $m['status']]);
        foreach ([['bsr_code' => '123'], ['amount_rs' => 0], ['deposit_date' => '2026-12-31'], ['period' => '2026-13'], ['challan_serial' => 'ABC']] as $bad) {
            try { $this->svc()->addChallan(1, $bad + ['period' => '2026-10', 'amount_rs' => 10, 'bsr_code' => '0510308', 'challan_serial' => '1', 'deposit_date' => '2026-10-07'], 5); $this->fail('should reject ' . json_encode($bad)); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $id = $m['challans'][0]['id'];
        $this->svc()->deleteChallan(1, $id, 5);
        $this->assertSame('due', $this->svc()->report(1, 2026, 3)['months'][0]['status']);
    }

    public function testPanIsValidatedStoredAndTenantScoped(): void
    {
        $b = $this->booking();
        $this->svc()->setPan(1, $b, ' abcde1234f ');
        $this->assertSame('ABCDE1234F', db_connect()->table('bookings')->where('id', $b)->get()->getRowArray()['customer_pan']);
        $this->assertSame([], array_filter($this->svc()->report(1, 2026, 3)['warnings'], fn ($w) => str_contains($w, 'no valid customer PAN')));
        try { $this->svc()->setPan(1, $b, 'BADPAN'); $this->fail('invalid PAN accepted'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->setPan(2, $b, 'ABCDE1234F');                 // another tenant cannot touch it
    }

    public function testCancelledBookingsAreWarnedAndCsvIsInjectionSafe(): void
    {
        $this->booking(1, 'cancelled');
        $r = $this->svc()->report(1, 2026, 3);
        $this->assertStringContainsString('cancelled bookings', implode(' ', $r['warnings']));
        $csv = $this->svc()->csv(1, 2026, 3);
        $this->assertStringContainsString("'=Rohit", $csv);
        $this->assertStringContainsString('206C(1G)', $csv);
        $this->assertStringContainsString('1050.00', $csv);
    }

    public function testOtherTenantsBookingsNeverAppear(): void
    {
        $this->booking(2);
        $this->assertSame([], $this->svc()->report(1, 2026, 3)['rows']);
    }
}
