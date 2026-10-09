<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\SupplierPayableService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class SupplierPayableServiceTest extends CIUnitTestCase
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
        foreach (['supplier_payments', 'booking_services', 'bookings', 'suppliers', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('suppliers')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Ubud Villas', 'type' => 'hotel', 'created_at' => '2026-01-01 00:00:00']);
    }

    private function svc(): SupplierPayableService { return new SupplierPayableService(self::NOW); }

    /** @return array{0:int,1:int} [bookingId, serviceId] */
    private function service(array $o = [], array $b = [], int $tenant = 1): array
    {
        $db = db_connect();
        $db->table('bookings')->insert($b + ['tenant_id' => $tenant, 'booking_ref' => 'TP-' . random_int(1000, 9999), 'title' => 'Bali', 'status' => 'confirmed', 'travel_start' => '2026-12-20', 'created_at' => '2026-09-01 00:00:00']);
        $bid = (int) $db->insertID();
        $db->table('booking_services')->insert($o + ['tenant_id' => $tenant, 'booking_id' => $bid, 'supplier_id' => 1, 'service_type' => 'hotel', 'title' => 'Ubud Villa', 'status' => 'confirmed', 'cost_amount' => 5_000_000, 'created_at' => '2026-09-01 00:00:00']);
        return [$bid, (int) $db->insertID()];
    }

    public function testPaymentsAreALedgerAndRollUpToServiceAndBooking(): void
    {
        [$bid, $sid] = $this->service();
        $this->svc()->pay(1, $sid, ['amount_rs' => 20000, 'paid_on' => '2026-10-01', 'mode' => 'bank', 'reference' => 'UTR1'], 9);
        $r = $this->svc()->pay(1, $sid, ['amount_rs' => 10000, 'mode' => 'upi']);
        $this->assertSame([5_000_000, 3_000_000, 2_000_000], [$r['cost'], $r['paid'], $r['outstanding']]);
        $this->assertCount(2, $r['payments']);
        $this->assertSame(3_000_000, (int) db_connect()->table('bookings')->where('id', $bid)->get()->getRowArray()['supplier_paid']);
        $r = $this->svc()->delete(1, $r['payments'][0]['id']);
        $this->assertSame(2_000_000, $r['paid']);
        $this->assertSame(2_000_000, (int) db_connect()->table('bookings')->where('id', $bid)->get()->getRowArray()['supplier_paid']);
    }

    public function testOverpayingFutureDatesBadModeAndCancelledAreRefused(): void
    {
        [, $sid] = $this->service();
        foreach ([['amount_rs' => 50001], ['amount_rs' => 0], ['amount_rs' => 10, 'paid_on' => '2026-12-31'], ['amount_rs' => 10, 'mode' => 'barter'], ['amount_rs' => 10, 'paid_on' => 'yesterday']] as $bad) {
            try { $this->svc()->pay(1, $sid, $bad); $this->fail('accepted ' . json_encode($bad)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->svc()->pay(1, $sid, ['amount_rs' => 50000]);                       // exactly the cost is fine
        try { $this->svc()->pay(1, $sid, ['amount_rs' => 1]); $this->fail('paid beyond cost'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        db_connect()->table('booking_services')->where('id', $sid)->update(['status' => 'cancelled']);
        $this->expectException(\DomainException::class);
        $this->svc()->pay(1, $sid, ['amount_rs' => 1]);
    }

    public function testPayablesGroupBySupplierAndFlagOverdueAndDueSoon(): void
    {
        $this->service(['pay_by' => '2026-10-01', 'cost_amount' => 1_000_000]);                // overdue
        $this->service(['pay_by' => '2026-10-12', 'cost_amount' => 2_000_000]);                // within 7 days
        $this->service(['pay_by' => null, 'cost_amount' => 4_000_000], ['travel_start' => '2027-03-01']);   // default = a week before travel, far away
        $this->service(['supplier_id' => null, 'cost_amount' => 500_000, 'pay_by' => '2026-11-30']);
        $p = $this->svc()->payables(1);
        $this->assertSame(7_500_000, $p['totals']['outstanding']);
        $this->assertSame(1_000_000, $p['totals']['overdue']);
        $this->assertSame(2_000_000, $p['totals']['due_7d']);
        $this->assertSame('Ubud Villas', $p['suppliers'][0]['supplier']);
        $this->assertSame('No supplier assigned', $p['suppliers'][1]['supplier']);
        $this->assertCount(1, $this->svc()->payables(1, 'overdue')['suppliers'][0]['items']);
        $this->assertSame(3_000_000, $this->svc()->payables(1, 'week')['totals']['overdue'] + $this->svc()->payables(1, 'week')['totals']['due_7d']);
    }

    public function testSettledCancelledAndOtherTenantsAreExcludedAndRefundsSurface(): void
    {
        [, $paid] = $this->service(['cost_amount' => 1_000_000]);
        $this->svc()->pay(1, $paid, ['amount_rs' => 10000]);                                    // fully paid -> not outstanding
        [, $cx] = $this->service(['cost_amount' => 1_000_000, 'paid_amount' => 300_000]);
        db_connect()->table('supplier_payments')->insert(['tenant_id' => 1, 'booking_id' => 1, 'booking_service_id' => $cx, 'amount' => 300_000, 'paid_on' => '2026-10-01', 'mode' => 'bank', 'created_at' => '2026-10-01 00:00:00']);
        db_connect()->table('booking_services')->where('id', $cx)->update(['status' => 'cancelled']);
        $this->service([], [], 2);
        $p = $this->svc()->payables(1);
        $this->assertSame([], $p['suppliers']);
        $this->assertSame([1, 300_000], [$p['refunds_due']['count'], $p['refunds_due']['amount']] === [1, 300_000] ? [1, 300_000] : $p['refunds_due']);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->pay(2, $paid, ['amount_rs' => 1]);                                        // tenant 2 cannot touch tenant 1's service
    }
}
