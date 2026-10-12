<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\AdminSessionService;
use App\Services\Admin\MarketingWorkspaceAccess;
use App\Services\Admin\PlatformSettings;
use App\Services\Auth\CurrentUser;
use App\Services\Crm\AuditLogger;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** An admin session working inside TripSarthi's OWN marketing workspace (the admin WhatsApp module): opt-in header, allow-listed areas, nothing else (group "mysql"). */
#[Group('mysql')]
final class MarketingWorkspaceAccessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private string $adminToken = '';
    private string $ownerToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['admin_sessions', 'waba_accounts', 'platform_settings', 'audit_logs', 'tags', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        foreach ([1, 2] as $id) { $db->table('tenants')->insert(['id' => $id, 'name' => "Agency $id", 'slug' => "a$id", 'plan' => 'growth', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $this->ownerToken = bin2hex(random_bytes(32));
        // tenant 1 = TripSarthi's marketing workspace (its owner), tenant 2 = an ordinary customer; user 9 = the platform admin (lives in tenant 2)
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Marketing Owner', 'email' => 'mk@example.com', 'password_hash' => 'x', 'role' => 'owner', 'api_token' => hash('sha256', $this->ownerToken), 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 5, 'tenant_id' => 2, 'name' => 'Customer Owner', 'email' => 'c@example.com', 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 9, 'tenant_id' => 2, 'name' => 'Team Member', 'email' => 'team@example.com', 'password_hash' => 'x', 'role' => 'admin', 'is_platform_admin' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $this->adminToken = (new AdminSessionService())->create(9);
        PlatformSettings::set(PlatformSettings::MARKETING_TENANT, '1', 9);
        CurrentUser::reset();
    }

    protected function tearDown(): void { CurrentUser::reset(); db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function as(string $token, bool $marketing = true): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token] + ($marketing ? [MarketingWorkspaceAccess::HEADER => '1'] : []));
    }

    public function testAdminSessionWithTheHeaderWorksInTheMarketingWorkspaceOnly(): void
    {
        db_connect()->table('tags')->insert(['tenant_id' => 1, 'name' => 'ts-owners', 'color' => '#111111', 'created_at' => '2026-01-01 00:00:00']);
        db_connect()->table('tags')->insert(['tenant_id' => 2, 'name' => 'customer-secret-tag', 'color' => '#222222', 'created_at' => '2026-01-01 00:00:00']);
        $r = $this->as($this->adminToken)->get('api/v1/tags'); $r->assertStatus(200);
        $this->assertStringContainsString('ts-owners', $r->getJSON());
        $this->assertStringNotContainsString('customer-secret-tag', $r->getJSON(), 'the admin\'s own workspace (2) must not be what is shown');
    }

    public function testWithoutTheHeaderAnAdminTokenStillCannotUseTheCustomerApi(): void
    {
        $this->as($this->adminToken, false)->get('api/v1/tags')->assertStatus(401);
    }

    public function testOnlyTheWhatsappAreasAreOpen(): void
    {
        foreach (['api/v1/auth/me', 'api/v1/billing/status', 'api/v1/travel/trips', 'api/v1/crm/audit-logs'] as $path) {
            $r = $this->as($this->adminToken)->get($path);
            $r->assertStatus(403);
            $this->assertStringContainsString('marketing_area', $r->getJSON(), $path);
        }
    }

    public function testNoMarketingWorkspaceChosenIsExplained(): void
    {
        PlatformSettings::set(PlatformSettings::MARKETING_TENANT, null, 9);
        $r = $this->as($this->adminToken)->get('api/v1/tags'); $r->assertStatus(403);
        $this->assertStringContainsString('no_marketing_workspace', $r->getJSON());
    }

    public function testNonAdminOrRevokedOrUnknownTokensGetNothing(): void
    {
        $this->as(bin2hex(random_bytes(32)))->get('api/v1/tags')->assertStatus(401);
        $raw = (new AdminSessionService())->create(9);
        db_connect()->table('users')->where('id', 9)->update(['is_platform_admin' => 0]);   // admin:grant --revoke
        $this->as($raw)->get('api/v1/tags')->assertStatus(401);
        $this->as($this->adminToken)->get('api/v1/tags')->assertStatus(401);
    }

    public function testACustomerTokenIgnoresTheHeader(): void
    {
        db_connect()->table('tags')->insert(['tenant_id' => 1, 'name' => 'own-tag', 'color' => '#111111', 'created_at' => '2026-01-01 00:00:00']);
        $this->as($this->ownerToken)->get('api/v1/auth/me')->assertStatus(200);   // not limited to the allow-list: it is the owner's own login
    }

    public function testMediaProxyAcceptsTheAdminTokenOnlyForTheMarketingWorkspace(): void
    {
        // this route answers with a plain body (it is outside the auth filter): "Unauthorized." versus the next step, "No WABA configured."
        $this->assertStringContainsString('Unauthorized', (string) $this->get('api/v1/inbox/media/abc?token=' . $this->adminToken)->getBody());      // no ws marker: not a customer token
        $this->assertStringContainsString('Unauthorized', (string) $this->get('api/v1/inbox/media/abc?token=' . str_repeat('a', 64) . '&ws=marketing')->getBody());
        $this->assertStringContainsString('No WABA', (string) $this->get('api/v1/inbox/media/abc?token=' . $this->adminToken . '&ws=marketing')->getBody());   // authenticated as the marketing owner
    }

    public function testChangesAreTaggedWithTheAdmin(): void
    {
        $m = MarketingWorkspaceAccess::resolve($this->adminToken);
        $this->assertIsArray($m);
        CurrentUser::set($m['user']); CurrentUser::setImpersonation(MarketingWorkspaceAccess::marker($m['admin']));
        AuditLogger::log('test.change', 'tag', 7, null, ['x' => 1]);
        $row = db_connect()->table('audit_logs')->where('entity_id', 7)->get()->getRowArray();
        $this->assertSame(9, (int) $row['impersonated_by']);
    }
}
