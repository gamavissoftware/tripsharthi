<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** The headline numbers on the Contacts page: workspace-wide, tenant-scoped, deleted contacts excluded (group "mysql"). */
#[Group('mysql')]
final class ContactsSummaryTest extends CIUnitTestCase
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
        foreach (['contact_tags', 'contacts', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        foreach ([1, 2] as $id) { $db->table('tenants')->insert(['id' => $id, 'name' => "Agency $id", 'slug' => "a$id", 'plan' => 'growth', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $this->token = bin2hex(random_bytes(32));
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Owner', 'email' => 'o@example.com', 'password_hash' => 'x', 'role' => 'owner', 'api_token' => hash('sha256', $this->token), 'created_at' => '2026-01-01 00:00:00']);
        $now = gmdate('Y-m-d H:i:s'); $old = gmdate('Y-m-d H:i:s', time() - 90 * 86400);
        $rows = [
            // tenant 1: new+opted-in+replied, contacted+opted-out, won (old), qualified without a number, deleted one
            [1, '919800000001', 'new', 1, $now, $now, null],
            [1, '919800000002', 'contacted', 0, $old, null, null],
            [1, '919800000003', 'won', 1, $old, null, null],
            [1, null, 'qualified', 1, $now, null, null],
            [1, '919800000005', 'new', 1, $now, null, $now],
            [2, '919800000009', 'new', 1, $now, $now, null],      // another workspace: never counted
        ];
        foreach ($rows as [$t, $wa, $st, $opt, $created, $inbound, $deleted]) {
            $db->table('contacts')->insert(['tenant_id' => $t, 'wa_number' => $wa, 'name' => 'C', 'status' => $st, 'opt_in' => $opt, 'created_at' => $created, 'last_inbound_at' => $inbound, 'deleted_at' => $deleted]);
        }
    }

    protected function tearDown(): void { db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    public function testSummaryCountsThisWorkspaceOnly(): void
    {
        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token])->get('api/v1/contacts/summary');
        $r->assertStatus(200);
        $d = json_decode($r->getJSON(), true)['data'];
        $this->assertSame(4, $d['total'], 'the deleted contact and the other workspace are excluded');
        $this->assertSame(2, $d['opted_in'], 'opted in AND has a number');
        $this->assertSame(2, $d['new_30d']);
        $this->assertSame(3, $d['open_leads']);
        $this->assertSame(1, $d['won']);
        $this->assertSame(1, $d['replied_30d']);
    }

    public function testNeedsALogin(): void
    {
        $this->get('api/v1/contacts/summary')->assertStatus(401);
    }
}
