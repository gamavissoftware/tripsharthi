<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** The Messaging & CRM dashboard: period selector, inbox/consent/connection blocks, tenant isolation (group "mysql"). */
#[Group('mysql')]
final class CrmDashboardPeriodTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['messages', 'conversations', 'contacts', 'tasks', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        foreach ([1, 2] as $id) { $db->table('tenants')->insert(['id' => $id, 'name' => "Agency $id", 'slug' => "a$id", 'plan' => 'growth', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $this->token = bin2hex(random_bytes(32));
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Owner', 'email' => 'o@example.com', 'password_hash' => 'x', 'role' => 'owner', 'api_token' => hash('sha256', $this->token), 'created_at' => '2026-01-01 00:00:00']);
        $recent = gmdate('Y-m-d H:i:s', time() - 2 * 86400); $old = gmdate('Y-m-d H:i:s', time() - 20 * 86400);
        foreach ([[1, '919800000001', 1, $recent], [1, '919800000002', 0, $old], [2, '919800000009', 1, $recent]] as [$t, $wa, $opt, $created]) {
            $db->table('contacts')->insert(['tenant_id' => $t, 'wa_number' => $wa, 'name' => 'C', 'status' => 'new', 'opt_in' => $opt, 'created_at' => $created]);
        }
        $cid = (int) $db->table('contacts')->where('wa_number', '919800000001')->get()->getRow()->id;
        $db->table('conversations')->insert(['tenant_id' => 1, 'contact_id' => $cid, 'wa_number' => '919800000001', 'status' => 'open', 'unread_count' => 2, 'created_at' => $recent]);
        $convId = (int) $db->insertID();
        foreach ([['in', $recent], ['out', $recent], ['out', $old]] as [$dir, $at]) {
            $db->table('messages')->insert(['tenant_id' => 1, 'contact_id' => $cid, 'conversation_id' => $convId, 'direction' => $dir, 'type' => 'text', 'status' => $dir === 'out' ? 'read' : 'delivered', 'created_at' => $at]);
        }
        $db->table('tasks')->insert(['tenant_id' => 1, 'title' => 'Call back', 'status' => 'open', 'due_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'created_at' => $recent]);
    }

    protected function tearDown(): void { db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function dash(string $q = ''): array
    {
        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->get('api/v1/crm/dashboard' . $q);
        $r->assertStatus(200);
        return json_decode($r->getJSON(), true)['data'];
    }

    public function testPeriodChangesTheTotals(): void
    {
        $week = $this->dash('?days=7'); $month = $this->dash('?days=30');
        $this->assertSame(7, $week['period_days']);
        $this->assertSame(1, $week['messages']['outbound']);
        $this->assertSame(2, $month['messages']['outbound'], 'the 20-day-old message only counts in the 30-day view');
        $this->assertSame(1, $week['contacts']['new_period']);
        $this->assertSame(2, $month['contacts']['new_period']);
        $this->assertCount(7, $week['message_volume']);
    }

    public function testAnUnknownPeriodFallsBackToThirtyDays(): void
    {
        $this->assertSame(30, $this->dash('?days=5000')['period_days']);
    }

    public function testInboxConsentUpcomingAndConnectionBlocks(): void
    {
        $d = $this->dash();
        $this->assertSame(['open' => 1, 'unread' => 1, 'unassigned' => 1], $d['inbox']);
        $this->assertSame(2, $d['contacts']['total'], 'the other workspace is never counted');
        $this->assertSame(1, $d['contacts']['opted_in']);
        $this->assertSame(1, $d['contacts']['opted_out']);
        $this->assertSame('Call back', $d['upcoming_tasks'][0]['title']);
        $this->assertFalse($d['connection']['connected']);
        $this->assertSame([], $d['connection']['numbers']);
    }
}
