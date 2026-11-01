<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\PlatformFacebookConnector;
use App\Services\Admin\PlatformSettings;
use App\Services\Social\GraphClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** A scripted Graph API: the connector must verify with Meta before saving anything (group "mysql"). */
final class ScriptedGraph extends GraphClient
{
    public array $calls = [];
    public function __construct(private array $user = [], private string $meId = 'U1', private bool $leadsOk = true) {}
    protected function request(string $method, string $path, array $params): array
    {
        $this->calls[] = $method . ' ' . $path;
        $tok = (string) ($params['access_token'] ?? '');
        if ($path === 'me') {
            if (! str_starts_with($tok, 'GOOD')) { throw new \RuntimeException('Invalid OAuth access token'); }
            return ['id' => str_starts_with($tok, 'GOODPAGE') ? '1384403011425937' : $this->meId, 'name' => 'Someone'];
        }
        if ($path === 'me/accounts') { return ['data' => $this->user]; }
        if ($path === 'oauth/access_token') { return ['access_token' => 'GOODLONG' . $tok]; }
        if (str_ends_with($path, '/leadgen_forms')) {
            if (! $this->leadsOk) { throw new \RuntimeException('(#200) Requires leads_retrieval permission'); }
            return ['data' => [['id' => 'f1', 'leads_count' => 5], ['id' => 'f2', 'leads_count' => 7]]];
        }
        if (str_ends_with($path, '/subscribed_apps')) { return ['success' => true]; }
        return [];
    }
}

#[Group('mysql')]
final class PlatformFacebookConnectorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private const MK = 9;
    private const PAGE = '1384403011425937';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['platform_settings', 'integrations', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->table('tenants')->insert(['id' => self::MK, 'name' => 'Marketing', 'slug' => 'mk', 'plan' => 'free', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        PlatformSettings::set(PlatformSettings::MARKETING_TENANT, (string) self::MK);
    }

    protected function tearDown(): void { db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function row(): ?array { return db_connect()->table('integrations')->where('tenant_id', self::MK)->where('type', 'meta_lead_ads')->get()->getRowArray(); }

    public function testUserTokenYieldsThePageTokenAndSavesTheRealPageName(): void
    {
        $g = new ScriptedGraph([['id' => '1', 'name' => 'Other', 'access_token' => 'PT-other'], ['id' => self::PAGE, 'name' => 'TripSarthi', 'access_token' => 'PT-trip']]);
        $r = (new PlatformFacebookConnector($g))->connect(self::PAGE, 'GOODUSERTOKEN-aaaaaaaaaaaa', 1);
        $this->assertSame('TripSarthi', $r['page_name']);
        $this->assertSame(12, $r['leads_in_forms']);
        $this->assertTrue($r['subscribed']);
        $row = $this->row();
        $this->assertSame(self::PAGE, $row['page_id']);
        $this->assertSame('TripSarthi', json_decode($row['config'], true)['page_name']);
        $this->assertStringNotContainsString('PT-trip', $row['config'], 'the token is stored encrypted');
        $this->assertStringNotContainsString('GOODUSERTOKEN', $row['config']);
    }

    public function testAnAppIdOrAnyPageTheTokenCannotManageIsRefusedAndNothingIsSaved(): void
    {
        $g = new ScriptedGraph([['id' => '1', 'name' => 'Other', 'access_token' => 'PT-other']]);
        try { (new PlatformFacebookConnector($g))->connect('2057700981604251', 'GOODUSERTOKEN-aaaaaaaaaaaa', 1); $this->fail('should refuse'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('cannot manage Page 2057700981604251', $e->getMessage()); }
        $this->assertNull($this->row());
    }

    public function testABadTokenIsRefusedWithMetasReasonAndNeverEchoed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try { (new PlatformFacebookConnector(new ScriptedGraph()))->connect(self::PAGE, 'BADTOKEN-1234567890123456', 1); }
        finally { $this->assertNull($this->row()); }
    }

    public function testNoLeadAccessMeansNothingIsSaved(): void
    {
        $g = new ScriptedGraph([['id' => self::PAGE, 'name' => 'TripSarthi', 'access_token' => 'PT-trip']], 'U1', false);
        try { (new PlatformFacebookConnector($g))->connect(self::PAGE, 'GOODUSERTOKEN-aaaaaaaaaaaa', 1); $this->fail('should refuse'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('lead forms', $e->getMessage()); }
        $this->assertNull($this->row());
    }

    public function testReconnectingKeepsTheVerifyTokenAlreadyRegisteredInMeta(): void
    {
        $g = new ScriptedGraph([['id' => self::PAGE, 'name' => 'TripSarthi', 'access_token' => 'PT-trip']]);
        $c = new PlatformFacebookConnector($g);
        $c->connect(self::PAGE, 'GOODUSERTOKEN-aaaaaaaaaaaa', 1);
        $first = $this->row()['verify_token'];
        $c->connect(self::PAGE, 'GOODUSERTOKEN-bbbbbbbbbbbb', 1);
        $this->assertSame($first, $this->row()['verify_token']);
        $this->assertSame(1, (int) db_connect()->table('integrations')->where('type', 'meta_lead_ads')->countAllResults());
    }

    public function testAPageTokenForTheSamePageIsAcceptedWithAnExpiryWarning(): void
    {
        $r = (new PlatformFacebookConnector(new ScriptedGraph()))->connect(self::PAGE, 'GOODPAGETOKEN-aaaaaaaaaaaa', 1);
        $this->assertNotNull($r['warning']);
        $this->assertSame(self::PAGE, $this->row()['page_id']);
    }

    public function testBadInputIsRefusedBeforeAskingMeta(): void
    {
        $g = new ScriptedGraph();
        $this->expectException(\InvalidArgumentException::class);
        try { (new PlatformFacebookConnector($g))->connect('abc', 'GOODUSERTOKEN-aaaaaaaaaaaa', 1); }
        finally { $this->assertSame([], $g->calls); }
    }

    public function testRecentEventsListsOnlyTheMarketingWorkspaceWithTheFailureReason(): void
    {
        $db = db_connect();
        $db->table('meta_lead_events')->truncate();
        $db->table('meta_lead_events')->insertBatch([
            ['tenant_id' => self::MK, 'integration_id' => 1, 'leadgen_id' => 'L1', 'status' => 'failed', 'error' => 'dummy phone', 'created_at' => '2026-11-01 10:00:00'],
            ['tenant_id' => 77, 'integration_id' => 2, 'leadgen_id' => 'L2', 'status' => 'processed', 'error' => null, 'created_at' => '2026-11-01 10:01:00'],
        ]);
        $rows = (new PlatformFacebookConnector(new ScriptedGraph()))->recentEvents();
        $this->assertCount(1, $rows);
        $this->assertSame('L1', $rows[0]['leadgen_id']);
        $this->assertSame('dummy phone', $rows[0]['error']);
    }
}
