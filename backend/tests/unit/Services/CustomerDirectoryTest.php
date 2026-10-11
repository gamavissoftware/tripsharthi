<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\CustomerDirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** The admin Customers page data: billing state, flags, segments, summary, search, sort, paging (group "mysql"). */
#[Group('mysql')]
final class CustomerDirectoryTest extends CIUnitTestCase
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
        $this->now = strtotime('2026-10-11 12:00:00');
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['messages', 'support_messages', 'support_tickets', 'subscriptions', 'contacts', 'waba_accounts', 'trips', 'bookings', 'users', 'partners', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $d = fn (int $days) => date('Y-m-d H:i:s', $this->now - $days * 86400);
        $f = fn (int $days) => date('Y-m-d H:i:s', $this->now + $days * 86400);
        $db->table('partners')->insert(['id' => 1, 'name' => 'Diksha Partner', 'email' => 'p@example.com', 'code' => 'DIKSHA1', 'status' => 'active', 'created_at' => $d(90)]);
        // id, name, plan, status, joined days ago, owner last login days ago (null = never), referred partner
        $tenants = [
            [1, 'Alpha Travels', 'growth', 'active', 60, 1, null], [2, 'Bravo Tours', 'growth', 'active', 40, 2, null], [3, 'Charlie Trips', 'growth', 'active', 20, 3, 1],
            [4, 'Delta Holidays', 'free', 'active', 10, null, null], [5, 'Echo Journeys', 'free', 'active', 50, 5, null], [6, 'Foxtrot Escapes', 'free', 'active', 80, 70, null],
            [7, 'Golf Voyages', 'growth', 'suspended', 70, 40, null], [8, 'Hotel India Stays', 'pro', 'active', 100, 30, null], [9, 'India New Co', 'free', 'active', 2, 1, null],
        ];
        foreach ($tenants as [$id, $name, $plan, $status, $joined, $login, $partner]) {
            $db->table('tenants')->insert(['id' => $id, 'name' => $name, 'slug' => 'slug' . $id, 'plan' => $plan, 'status' => $status, 'mode' => 'saas', 'created_at' => $d($joined), 'referred_partner_id' => $partner]);
            $db->table('users')->insert(['id' => $id, 'tenant_id' => $id, 'name' => "Owner $id", 'email' => "owner{$id}@example.com", 'phone' => $id === 1 ? '919800000001' : null, 'password_hash' => 'x', 'role' => 'owner', 'last_login_at' => $login === null ? null : $d($login), 'created_at' => $d($joined)]);
        }
        $sub = fn (int $t, string $plan, int $paise, string $status, ?string $end, string $sid) => $db->table('subscriptions')->insert(['tenant_id' => $t, 'razorpay_sub_id' => $sid, 'razorpay_customer_id' => 'c', 'plan' => $plan, 'amount_paise' => $paise, 'billing_cycle' => 'monthly', 'status' => $status, 'current_period_start' => $d(10), 'current_period_end' => $end, 'created_at' => $d(10), 'updated_at' => $d(10)]);
        $sub(1, 'growth', 49900, 'active', $f(20), 'order_1');           // paying, healthy
        $sub(2, 'growth', 49900, 'active', $f(3), 'order_2');            // paying, ends in 3 days
        $sub(3, 'growth', 0, 'active', $f(10), 'admin-1');               // complimentary
        $sub(5, 'growth', 49900, 'halted', null, 'sub_5');               // payment failed
        $sub(6, 'growth', 49900, 'downgraded', $d(5), 'order_6');        // lapsed (paid before, free now)
        $sub(8, 'pro', 99900, 'active', $f(25), 'order_8');              // paying, quiet for 30 days
        foreach ([1, 1, 3, 6] as $i => $t) { $db->table('contacts')->insert(['tenant_id' => $t, 'wa_number' => '9198000000' . $i . $t, 'name' => 'C', 'status' => 'new', 'source' => 'manual', 'created_at' => $d(5)]); }
        $db->table('support_tickets')->insert(['tenant_id' => 1, 'user_id' => 1, 'subject' => 'Help me', 'category' => 'other', 'priority' => 'normal', 'status' => 'open', 'last_from' => 'customer', 'created_at' => $d(1), 'updated_at' => $d(1)]);
    }

    protected function tearDown(): void { db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function svc(): CustomerDirectoryService { return new CustomerDirectoryService($this->now); }
    private function names(array $r): array { return array_column($r['rows'], 'name'); }
    private function row(string $name): array { foreach ($this->svc()->list(['per_page' => 100])['rows'] as $r) { if ($r['name'] === $name) { return $r; } } $this->fail("no row $name"); }

    public function testBillingStates(): void
    {
        $this->assertSame('paying', $this->row('Alpha Travels')['billing']['state']);
        $this->assertSame(49900, $this->row('Alpha Travels')['billing']['amount_paise']);
        $this->assertSame('complimentary', $this->row('Charlie Trips')['billing']['state']);
        $this->assertTrue($this->row('Charlie Trips')['billing']['manual']);
        $this->assertSame('free', $this->row('Delta Holidays')['billing']['state']);
        $this->assertSame('halted', $this->row('Echo Journeys')['billing']['state']);
        $this->assertSame('lapsed', $this->row('Foxtrot Escapes')['billing']['state']);
        $this->assertSame(49900, $this->row('Foxtrot Escapes')['billing']['lifetime_paise']);
    }

    public function testFlagsAndAttention(): void
    {
        $keys = fn (string $n) => array_column($this->row($n)['flags'], 'key');
        $this->assertContains('expiring', $keys('Bravo Tours'));
        $this->assertContains('tickets', $keys('Alpha Travels'));
        $this->assertContains('halted', $keys('Echo Journeys'));
        $this->assertContains('not_activated', $keys('Delta Holidays'));
        $this->assertNotContains('not_activated', $keys('India New Co'), 'a workspace only 2 days old is not "not activated" yet');
        $this->assertContains('suspended', $keys('Golf Voyages'));
        $this->assertNotContains('near_limit', $keys('Delta Holidays'), 'a free workspace with its one user is not "near a limit"');
        $this->assertContains('inactive', $keys('Hotel India Stays'));
        $this->assertTrue($this->row('Hotel India Stays')['attention'], 'a PAYING customer who has gone quiet needs attention');
        $this->assertFalse($this->row('Foxtrot Escapes')['attention'], 'a quiet FREE workspace is just listed, not escalated');
        $this->assertFalse($this->row('Charlie Trips')['attention']);
    }

    public function testSegmentsAndSummaryAgree(): void
    {
        $s = $this->svc(); $sum = $s->list(['per_page' => 100])['summary'];
        foreach (CustomerDirectoryService::SEGMENTS as $seg) { $this->assertSame($sum['segments'][$seg], $s->list(['segment' => $seg, 'per_page' => 100])['total'], "segment $seg count matches its list"); }
        $this->assertSame(9, $sum['segments']['all']);
        $this->assertSame(3, $sum['segments']['paying']);
        $this->assertSame(49900 + 49900 + 99900, $sum['mrr_paise']);
        $this->assertSame(['Bravo Tours'], $this->names($s->list(['segment' => 'expiring'])));
        $this->assertSame(['Charlie Trips'], $this->names($s->list(['segment' => 'referred'])));
        $this->assertSame(['India New Co'], $this->names($s->list(['segment' => 'new'])));
        $this->assertSame(['Golf Voyages'], $this->names($s->list(['segment' => 'suspended'])));
    }

    public function testSearchByOwnerEmailPhoneNameAndId(): void
    {
        $s = $this->svc();
        $this->assertSame(['Alpha Travels'], $this->names($s->list(['q' => 'owner1@example.com'])));
        $this->assertSame(['Alpha Travels'], $this->names($s->list(['q' => '9198000000'])));
        $this->assertSame(['Bravo Tours'], $this->names($s->list(['q' => 'bravo'])));
        $this->assertSame(['Charlie Trips'], $this->names($s->list(['q' => 'Diksha'])), 'finds customers by the partner who referred them');
        $this->assertCount(2, $s->list(['q' => 'india'])['rows']);
    }

    public function testSortAndPaging(): void
    {
        $s = $this->svc();
        $this->assertSame('India New Co', $s->list([])['rows'][0]['name'], 'newest first by default');
        $this->assertSame('Hotel India Stays', $s->list(['sort' => 'revenue'])['rows'][0]['name']);
        $this->assertSame('Alpha Travels', $s->list(['sort' => 'contacts'])['rows'][0]['name']);
        $this->assertSame('Bravo Tours', $s->list(['sort' => 'renewal'])['rows'][0]['name'], 'soonest renewal first');
        $this->assertSame('India New Co', $s->list(['sort' => 'active'])['rows'][0]['name'], 'most recently seen first');
        $this->assertSame('Alpha Travels', $s->list(['sort' => 'name'])['rows'][0]['name']);
        $p = $s->list(['per_page' => 5, 'page' => 2]);
        $this->assertCount(4, $p['rows']); $this->assertSame(9, $p['total']);
    }

    public function testLastPaymentAndMessageCounts(): void
    {
        $alpha = $this->row('Alpha Travels');
        $this->assertSame(49900, $alpha['last_payment']['amount_paise']);
        $this->assertFalse($alpha['last_payment']['offline']);
        $this->assertNull($this->row('Delta Holidays')['last_payment'], 'never paid');
        $this->assertNull($this->row('Charlie Trips')['last_payment'], 'a complimentary period is not a payment');
        $this->assertSame(['sent' => 0, 'received' => 0, 'failed' => 0, 'days' => 30], $this->row('Bravo Tours')['messages']);
    }

    public function testMessageCountsOnlyLookAtTheLastThirtyDays(): void
    {
        $db = db_connect(); $at = fn (int $d) => date('Y-m-d H:i:s', $this->now - $d * 86400);
        $cols = $db->getFieldNames('messages');
        $this->assertContains('direction', $cols);
        $mk = fn (int $t, string $dir, string $status, int $days) => $db->query("INSERT INTO messages (tenant_id, conversation_id, contact_id, direction, status, created_at) VALUES (?, 1, 1, ?, ?, ?)", [$t, $dir, $status, $at($days)]);
        $mk(1, 'out', 'delivered', 2); $mk(1, 'out', 'failed', 3); $mk(1, 'in', 'delivered', 1); $mk(1, 'out', 'read', 45);
        $m = $this->row('Alpha Travels')['messages'];
        $this->assertSame(2, $m['sent'], 'the 45-day-old message is outside the window');
        $this->assertSame(1, $m['failed']); $this->assertSame(1, $m['received']);
        $this->assertSame('Alpha Travels', $this->svc()->list(['sort' => 'messages'])['rows'][0]['name']);
    }

    public function testPickerFieldsStayAvailable(): void
    {
        $r = $this->row('Alpha Travels');
        $this->assertSame('owner1@example.com', $r['owner_email']);
        $this->assertSame('Owner 1', $r['owner_name']);
    }

    public function testFiltersByPlanAndStatus(): void
    {
        $s = $this->svc();
        $this->assertSame(['Hotel India Stays'], $this->names($s->list(['plan' => 'pro'])));
        $this->assertSame(['Golf Voyages'], $this->names($s->list(['status' => 'suspended'])));
    }
}
