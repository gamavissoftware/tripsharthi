<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\AccountDeletionService;
use App\Services\Admin\PlatformSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class AccountDeletionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/del-test-' . bin2hex(random_bytes(4));
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['contacts', 'tasks', 'invoices', 'users', 'user_tokens', 'subscriptions', 'support_tickets', 'support_messages', 'account_deletions', 'platform_settings', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) {
            $db->table('tenants')->insert(['id' => $t, 'name' => "Agency $t", 'slug' => "agency-$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
            $db->table('users')->insert(['tenant_id' => $t, 'name' => "Owner $t", 'email' => "owner$t@example.com", 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
            $uid = (int) $db->insertID();
            $db->table('users')->insert(['tenant_id' => $t, 'name' => "Agent $t", 'email' => "agent$t@example.com", 'password_hash' => 'x', 'role' => 'agent', 'phone' => '919800000000', 'created_at' => '2026-01-01 00:00:00']);
            $db->table('user_tokens')->insert(['user_id' => $uid, 'token_hash' => hash('sha256', "t$t"), 'client' => 'mobile', 'created_at' => '2026-01-01 00:00:00', 'expires_at' => '2027-01-01 00:00:00']);
            $db->table('contacts')->insert(['tenant_id' => $t, 'name' => "Customer $t", 'wa_number' => "9198765000$t", 'created_at' => '2026-01-01 00:00:00']);
            $db->table('subscriptions')->insert(['tenant_id' => $t, 'razorpay_sub_id' => 'order_t' . $t, 'plan' => 'pro', 'amount_paise' => 100000, 'billing_cycle' => 'monthly', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']);
            $db->table('support_tickets')->insert(['tenant_id' => $t, 'user_id' => $uid, 'subject' => 'Help me', 'status' => 'open', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
            $tk = (int) $db->insertID();
            $db->table('support_messages')->insert(['ticket_id' => $tk, 'author_type' => 'customer', 'body' => 'private words', 'created_at' => '2026-01-01 00:00:00']);
            foreach (['portal', 'invoices', 'ad-assets'] as $f) { @mkdir("{$this->root}/$f/$t", 0777, true); file_put_contents("{$this->root}/$f/$t/file.bin", 'x'); }
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/*/*') ?: [] as $f) { @unlink($f); }
        foreach (glob($this->root . '/*/*') ?: [] as $d) { @rmdir($d); }
        foreach (glob($this->root . '/*') ?: [] as $d) { @rmdir($d); }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function svc(): AccountDeletionService { return new AccountDeletionService($this->root); }
    private function n(string $t, int $tenant): int { return (int) db_connect()->table($t)->where('tenant_id', $tenant)->countAllResults(); }

    public function testDryRunChangesNothing(): void
    {
        $p = $this->svc()->plan(1);
        $this->assertSame(1, $p['tables']['contacts']);
        $this->assertArrayHasKey('subscriptions', $p['kept']);
        $this->assertSame(1, $this->n('contacts', 1));
    }

    public function testDeletesOnlyThatWorkspaceAndKeepsBusinessRecords(): void
    {
        $r = $this->svc()->deleteWorkspace(1, 'agency-1', 'ticket #42', 'tester');
        $this->assertSame(0, $this->n('contacts', 1));
        $this->assertSame(0, $this->n('users', 1));
        $this->assertSame(0, (int) db_connect()->table('user_tokens')->countAllResults() - 1, 'only the OTHER workspace token is left');
        $this->assertSame(0, $this->n('support_tickets', 1));
        $this->assertSame(1, (int) db_connect()->table('support_messages')->countAllResults(), 'the other workspace support message stays');
        $this->assertSame(1, $this->n('subscriptions', 1), 'TripSarthi keeps its own subscription record');
        $row = db_connect()->table('tenants')->where('id', 1)->get()->getRowArray();
        $this->assertSame('deleted-1', $row['slug']);
        $this->assertStringNotContainsString('Agency', (string) $row['name']);
        $this->assertSame(1, $this->n('contacts', 2));
        $this->assertSame(2, $this->n('users', 2));
        $this->assertDirectoryDoesNotExist("{$this->root}/portal/1");
        $this->assertDirectoryExists("{$this->root}/portal/2");
        $this->assertSame(1, (int) db_connect()->table('account_deletions')->where('tenant_id', 1)->where('scope', 'workspace')->countAllResults());
        $this->assertContains('portal/1', $r['folders']);
        try { $this->svc()->deleteWorkspace(1, 'deleted-1', 'again', 'tester'); $this->fail('deleted twice'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('already', $e->getMessage()); }
    }

    public function testRefusesWrongConfirmationProtectedWorkspacesAndMissingReference(): void
    {
        foreach ([['agency-9', 'ref'], ['agency-1', '  ']] as [$slug, $ref]) {
            try { $this->svc()->deleteWorkspace(1, $slug, $ref, 't'); $this->fail('accepted'); } catch (\InvalidArgumentException $e) { $this->assertTrue(true); }
        }
        db_connect()->table('users')->where('tenant_id', 1)->where('role', 'owner')->update(['is_platform_admin' => 1]);
        try { $this->svc()->deleteWorkspace(1, 'agency-1', 'ref', 't'); $this->fail('deleted a workspace with a platform admin'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('platform admin', $e->getMessage()); }
        db_connect()->table('users')->where('tenant_id', 1)->update(['is_platform_admin' => 0]);
        PlatformSettings::set('marketing_tenant_id', '1');
        try { $this->svc()->deleteWorkspace(1, 'agency-1', 'ref', 't'); $this->fail('deleted the marketing workspace'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('marketing', $e->getMessage()); }
        $this->assertSame(1, $this->n('contacts', 1), 'nothing was removed by the refused attempts');
    }

    public function testKeepTaxDocsKeepsInvoicesAndTheirFolder(): void
    {
        db_connect()->table('invoices')->insert(['tenant_id' => 1, 'booking_id' => 1, 'doc_type' => 'tax_invoice', 'number' => 'X/1', 'fy' => '26-27', 'seq' => 1, 'issue_date' => '2026-01-01', 'buyer' => '{}', 'seller' => '{}', 'lines' => '[]', 'share_token' => str_repeat('a', 32), 'created_at' => '2026-01-01 00:00:00']);
        $this->svc()->deleteWorkspace(1, 'agency-1', 'ref', 't', true);
        $this->assertSame(1, $this->n('invoices', 1));
        $this->assertDirectoryExists("{$this->root}/invoices/1");
        $this->assertSame(0, $this->n('contacts', 1));
    }

    public function testDeleteUserAnonymisesAndProtectsTheOwner(): void
    {
        $agent = (int) db_connect()->table('users')->where('email', 'agent1@example.com')->get()->getRowArray()['id'];
        $owner = (int) db_connect()->table('users')->where('email', 'owner1@example.com')->get()->getRowArray()['id'];
        try { $this->svc()->deleteUser($owner, 'owner1@example.com', 'ref', 't'); $this->fail('owner deleted as a user'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('owner', $e->getMessage()); }
        try { $this->svc()->deleteUser($agent, 'wrong@example.com', 'ref', 't'); $this->fail('bad confirmation'); } catch (\InvalidArgumentException $e) { $this->assertTrue(true); }
        $this->svc()->deleteUser($agent, 'AGENT1@example.com', 'e-mail 23 Oct', 't');
        $u = db_connect()->table('users')->where('id', $agent)->get()->getRowArray();
        $this->assertSame("deleted-{$agent}@deleted.invalid", $u['email']);
        $this->assertNull($u['phone']);
        $this->assertSame('Former team member', $u['name']);
        $this->assertSame(1, (int) db_connect()->table('account_deletions')->where('scope', 'user')->countAllResults());
    }
}
