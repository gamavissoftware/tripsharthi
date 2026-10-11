<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\AdminDashboardService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** The admin home page data: headline numbers, 12-month series, funnel, attention list, recent activity (group "mysql"). */
#[Group('mysql')]
final class AdminDashboardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = strtotime('2026-10-11 12:00:00 UTC');
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['messages', 'support_messages', 'support_tickets', 'platform_credit_notes', 'platform_invoices', 'platform_doc_sequences', 'subscriptions', 'contacts', 'waba_accounts', 'trips', 'bookings', 'partner_commissions', 'partners', 'impersonation_sessions', 'contact_enquiries', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $d = fn (int $days) => date('Y-m-d H:i:s', $this->now - $days * 86400);
        $f = fn (int $days) => date('Y-m-d H:i:s', $this->now + $days * 86400);
        foreach ([[1, 'Alpha Travels', 'growth', 60], [2, 'Bravo Tours', 'growth', 40], [3, 'Charlie Trips', 'free', 20], [4, 'Delta Holidays', 'free', 10], [5, 'Echo Journeys', 'free', 2]] as [$id, $name, $plan, $age]) {
            $db->table('tenants')->insert(['id' => $id, 'name' => $name, 'slug' => "t$id", 'plan' => $plan, 'status' => 'active', 'mode' => 'saas', 'created_at' => $d($age)]);
            $db->table('users')->insert(['id' => $id, 'tenant_id' => $id, 'name' => "Owner $id", 'email' => "o{$id}@example.com", 'password_hash' => 'x', 'role' => 'owner', 'last_login_at' => $d(1), 'created_at' => $d($age)]);
        }
        $sub = fn (int $t, int $paise, ?int $taxable, ?int $gst, string $status, ?string $end, string $start, string $ref) => $db->table('subscriptions')->insert(['tenant_id' => $t, 'razorpay_sub_id' => $ref, 'razorpay_customer_id' => 'c', 'plan' => 'growth', 'amount_paise' => $paise, 'taxable_paise' => $taxable, 'gst_paise' => $gst, 'gst_rate' => $gst ? 18 : null,
            'billing_cycle' => 'monthly', 'status' => $status, 'current_period_start' => $start, 'current_period_end' => $end, 'created_at' => $start, 'updated_at' => $start]);
        $sub(1, 471_882, 399_900, 71_982, 'active', $f(20), $d(5), 'pay_1');            // this month, price 3,999
        $sub(2, 471_882, 399_900, 71_982, 'active', $f(3), $d(40), 'pay_2');            // last month, ends in 3 days
        $db->table('contacts')->insert(['tenant_id' => 1, 'wa_number' => '919800000001', 'name' => 'C', 'status' => 'new', 'source' => 'manual', 'created_at' => $d(5)]);
        $db->table('support_tickets')->insert(['tenant_id' => 1, 'user_id' => 1, 'subject' => 'Help', 'category' => 'other', 'priority' => 'normal', 'status' => 'open', 'last_from' => 'customer', 'last_message_at' => $d(1), 'created_at' => $d(1), 'updated_at' => $d(1)]);
        $db->table('contact_enquiries')->insert(['name' => 'Visitor', 'email' => 'v@example.com', 'message' => 'Hello there, interested', 'status' => 'new', 'created_at' => $d(1)]);
    }

    protected function tearDown(): void { db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function dash(): array { return (new AdminDashboardService($this->now))->build(); }

    public function testHeadlineNumbersUseThePriceWithoutGst(): void
    {
        $h = $this->dash()['headline'];
        $this->assertSame([5, 2, 2 * 399_900, 12 * 2 * 399_900], [$h['customers'], $h['paying'], $h['mrr_paise'], $h['arr_paise']], 'MRR is the price before GST');
        $this->assertSame(40.0, $h['paying_pct']);
        $this->assertSame([399_900, 399_900, 0.0], [$h['revenue_month_paise'], $h['revenue_prev_paise'], $h['revenue_change_pct']]);
        $this->assertSame(1, $h['new_7d']);
    }

    public function testTwelveMonthAndTwelveWeekSeriesAreCompleteAndOrdered(): void
    {
        $d = $this->dash();
        $this->assertCount(12, $d['months']); $this->assertCount(12, $d['weeks']);
        $this->assertSame('2026-10', $d['months'][11]['month']); $this->assertSame('2025-11', $d['months'][0]['month']);
        $this->assertSame([1, 399_900], [$d['months'][11]['payments'], $d['months'][11]['revenue']]);
        $this->assertSame(1, $d['months'][10]['payments'], 'the second payment was last month');
        $this->assertSame(5, array_sum(array_column($d['months'], 'signups')), 'every signup falls in the 12 months');
        $this->assertSame(5, array_sum(array_column($d['weeks'], 'signups')), 'and in the 12 weeks (the oldest is 60 days old)');
    }

    public function testFunnelAndPlanMix(): void
    {
        $d = $this->dash();
        $this->assertSame(['registered' => 5, 'activated' => 1, 'whatsapp' => 0, 'paying' => 2], array_column($d['funnel'], 'n', 'key'));
        $this->assertSame(['free' => 3, 'starter' => 0, 'growth' => 2, 'pro' => 0], $d['plan_mix']);
    }

    public function testAttentionListNamesWhatNeedsTheTeamWithLinks(): void
    {
        $a = array_column($this->dash()['attention'], null, 'key');
        $this->assertSame(1, $a['expiring']['count']); $this->assertStringContainsString('segment=expiring', $a['expiring']['to']);
        $this->assertSame(1, $a['support']['count']); $this->assertSame('/admin/support', $a['support']['to']);
        $this->assertSame(1, $a['enquiries']['count']);
        $this->assertSame(2, $a['missing_invoices']['count'], 'both paid periods have no invoice yet');
        $this->assertArrayHasKey('not_activated', $a);
        $this->assertSame($this->dash()['headline']['attention'], count(array_filter((new \App\Services\Admin\CustomerDirectoryService($this->now))->build(), fn ($r) => $r['attention'])), 'the headline count is the Customers page count');
    }

    public function testRecentActivityAndSystemStatus(): void
    {
        $d = $this->dash();
        $this->assertSame('Echo Journeys', $d['recent']['signups'][0]['name'], 'newest signup first');
        $this->assertSame('TS-00001', $d['recent']['tickets'][0]['code']);
        $this->assertSame(['razorpay', 'mail', 'invoices', 'live_support_sessions'], array_keys($d['system']));
        $this->assertSame(2, $d['system']['invoices']['missing'], 'both paid periods still lack an invoice');
        $this->assertTrue($d['system']['invoices']['ok']);
        $this->assertSame(['Bravo Tours', 'Alpha Travels'], array_column($d['top_customers'], 'name'), 'by lifetime paid; a tie keeps the newer workspace first');
    }
}
