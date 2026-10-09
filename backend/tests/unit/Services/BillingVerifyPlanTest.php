<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\BillingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\BlankSlateSchema;

/**
 * Security regression: verifyPayment() must activate the plan that was actually
 * paid for (the 'created' subscription row stored at createOrder time), NOT a
 * plan supplied in the verify request. The Razorpay signature only covers
 * order_id|payment_id, so trusting the request would let a user pay for
 * 'starter' and claim 'pro'.
 */
class BillingVerifyPlanTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use BlankSlateSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
        // Skip the live Razorpay signature check for the test.
        putenv('RAZORPAY_MOCK_MODE=true');
        $_ENV['RAZORPAY_MOCK_MODE'] = 'true';
        $_SERVER['RAZORPAY_MOCK_MODE'] = 'true';

        $db = db_connect();
        $p  = $db->DBPrefix;
        // NOTE: the in-memory SQLite DB is shared across the whole suite, so never
        // DROP shared tables and always use the SAME superset `tenants` schema the
        // other tests use — only clear our own rows.
        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (
            id INTEGER PRIMARY KEY, name TEXT, slug TEXT, plan TEXT DEFAULT 'free',
            status TEXT DEFAULT 'active', mode TEXT DEFAULT 'saas',
            settings TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            razorpay_sub_id TEXT, razorpay_customer_id TEXT, plan TEXT,
            amount_paise INTEGER, billing_cycle TEXT, status TEXT,
            current_period_start TEXT, current_period_end TEXT,
            created_at TEXT, updated_at TEXT
        )");
        $db->query("DELETE FROM {$p}tenants WHERE id = 7");
        $db->query("DELETE FROM {$p}subscriptions WHERE tenant_id = 7");
        $db->table('tenants')->insert(['id' => 7, 'name' => 'BillTest', 'plan' => 'free']);
        // A 'created' order for the STARTER plan (what was actually paid for).
        $db->table('subscriptions')->insert([
            'tenant_id' => 7, 'razorpay_sub_id' => 'order_ABC', 'plan' => 'starter',
            'amount_paise' => 149900, 'billing_cycle' => 'monthly', 'status' => 'created',
        ]);
    }

    public function testVerifyActivatesPaidPlanNotRequestedPlan(): void
    {
        // Attacker passes plan='pro', billing='annual' — must be ignored.
        (new BillingService())->verifyPayment(7, 'order_ABC', 'pay_XYZ', 'sig', 'pro', 'annual');

        $db = db_connect();
        $tenant = $db->table('tenants')->where('id', 7)->get()->getRowArray();
        $sub    = $db->table('subscriptions')->where('razorpay_sub_id', 'pay_XYZ')->get()->getRowArray();

        $this->assertSame('starter', $tenant['plan'], 'tenant must get the PAID plan, not the requested one');
        $this->assertSame('active', $sub['status']);
        $this->assertSame('starter', $sub['plan']);
        $this->assertSame('monthly', $sub['billing_cycle'], 'cycle must come from the order, not the request');
        $this->assertSame(149900, (int) $sub['amount_paise']);
    }

    public function testVerifyRejectsUnknownOrder(): void
    {
        $this->expectException(\RuntimeException::class);
        (new BillingService())->verifyPayment(7, 'order_DOES_NOT_EXIST', 'pay_X', 'sig', 'pro', 'monthly');
    }
}
