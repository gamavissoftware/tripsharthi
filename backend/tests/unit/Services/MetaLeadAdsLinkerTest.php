<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Leads\MetaLeadAdsLinker;
use App\Services\Social\GraphClient;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Lead Ads over a Page connected with Facebook Login.
 *
 * What matters: the token moves from social_accounts to integrations as
 * ciphertext (never decrypted, never through the browser); a re-link keeps the
 * verify_token the Meta dashboard was registered with; tenants cannot reach
 * each other's Pages; and a refused leadgen subscription is reported with
 * Meta's reason rather than swallowed.
 *
 * Tables are dropped + recreated in setUp — the shared :memory: DB must not
 * leak schema or rows between suites (random-order safe).
 */
class MetaLeadAdsLinkerTest extends CIUnitTestCase
{
    private ?string $savedMock = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedMock = $_ENV['META_LEADS_MOCK_MODE'] ?? null;
        $_ENV['META_LEADS_MOCK_MODE'] = 'false';

        $db = db_connect();
        $p  = $db->DBPrefix;

        $db->query("DROP TABLE IF EXISTS {$p}integrations");
        $db->query("DROP TABLE IF EXISTS {$p}social_accounts");

        $db->query("CREATE TABLE {$p}integrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            type TEXT DEFAULT 'meta_lead_ads', page_id TEXT, verify_token TEXT,
            config TEXT, status TEXT DEFAULT 'active',
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE {$p}social_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            page_id TEXT, page_name TEXT, ig_user_id TEXT, ig_username TEXT,
            access_token_enc TEXT, token_expires_at TEXT, connected_by INTEGER,
            connect_method TEXT DEFAULT 'manual', status TEXT DEFAULT 'active',
            last_error TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
    }

    protected function tearDown(): void
    {
        if ($this->savedMock === null) {
            unset($_ENV['META_LEADS_MOCK_MODE']);
        } else {
            $_ENV['META_LEADS_MOCK_MODE'] = $this->savedMock;
        }
        parent::tearDown();
    }

    private function seedAccount(int $tenantId, string $pageId, string $token, string $status = 'active'): int
    {
        db_connect()->table('social_accounts')->insert([
            'tenant_id'        => $tenantId,
            'page_id'          => $pageId,
            'page_name'        => 'Page ' . $pageId,
            'access_token_enc' => $token === '' ? '' : TokenCipher::encrypt($token),
            'connect_method'   => 'oauth',
            'status'           => $status,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    private function integration(int $tenantId = 1): ?array
    {
        return db_connect()->table('integrations')
            ->where('tenant_id', $tenantId)->where('type', 'meta_lead_ads')
            ->get()->getRowArray();
    }

    // ── link ────────────────────────────────────────────────────────────

    public function testLinkCopiesTheCiphertextAcrossWithoutDecrypting(): void
    {
        $accountId = $this->seedAccount(1, '105329485290343', 'page-token-abc');

        $link = (new MetaLeadAdsLinker(new FakeLeadGraph()))->linkSocialAccount(1, $accountId, '+91');

        $row = $this->integration();
        $this->assertNotNull($row);
        $this->assertSame($link['id'], (int) $row['id']);
        $this->assertSame('105329485290343', $row['page_id']);
        $this->assertSame('active', $row['status']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $row['verify_token']);
        $this->assertSame($row['verify_token'], $link['verify_token']);

        $account = db_connect()->table('social_accounts')->where('id', $accountId)->get()->getRowArray();
        $config  = json_decode($row['config'], true);
        // Byte-identical ciphertext: the token was copied, not re-encrypted —
        // and certainly not round-tripped through anything.
        $this->assertSame($account['access_token_enc'], $config['page_access_token_enc']);
        $this->assertSame('page-token-abc', TokenCipher::decrypt($config['page_access_token_enc']));
        $this->assertSame('Page 105329485290343', $config['page_name']);
        $this->assertSame('+91', $config['default_country_code']);
        $this->assertSame('facebook_login', $config['connect_method']);
        $this->assertSame($accountId, $config['social_account_id']);
        $this->assertArrayNotHasKey('subscribed', $config, 'Subscription is unknown until subscribe() confirms it.');
    }

    public function testRelinkKeepsVerifyTokenAndCountryCodeButForgetsSubscription(): void
    {
        // The dashboard webhook was registered with this verify_token — a
        // reconnect must not rotate it, or Meta's registration breaks silently.
        db_connect()->table('integrations')->insert([
            'tenant_id'    => 1, 'type' => 'meta_lead_ads',
            'page_id'      => '976236724863344', // wrong id from a past attempt
            'verify_token' => 'keep-me-exactly',
            'config'       => json_encode([
                'page_access_token_enc' => 'stale',
                'default_country_code'  => '+91',
                'subscribed'            => true,
                'subscribed_at'         => '2026-06-05 06:30:41',
            ]),
            'status'     => 'active',
            'created_at' => '2026-06-05 06:30:41', 'updated_at' => '2026-06-05 06:30:41',
        ]);
        $accountId = $this->seedAccount(1, '105329485290343', 'fresh-token');

        (new MetaLeadAdsLinker(new FakeLeadGraph()))->linkSocialAccount(1, $accountId);

        $rows = db_connect()->table('integrations')->where('tenant_id', 1)->get()->getResultArray();
        $this->assertCount(1, $rows, 'UNIQUE(tenant_id, type): must update, not insert.');
        $row    = $rows[0];
        $config = json_decode($row['config'], true);
        $this->assertSame('105329485290343', $row['page_id']);
        $this->assertSame('keep-me-exactly', $row['verify_token']);
        $this->assertSame('+91', $config['default_country_code'], 'Empty country code on re-link keeps the old one.');
        $this->assertSame('fresh-token', TokenCipher::decrypt($config['page_access_token_enc']));
        $this->assertArrayNotHasKey('subscribed', $config);
        $this->assertArrayNotHasKey('subscribed_at', $config);
    }

    public function testLinkRevivesASoftDeletedIntegration(): void
    {
        db_connect()->table('integrations')->insert([
            'tenant_id' => 1, 'type' => 'meta_lead_ads', 'page_id' => 'old',
            'verify_token' => 'vt', 'config' => '{}', 'status' => 'active',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            'deleted_at' => '2026-02-01 00:00:00',
        ]);
        $oldId     = (int) db_connect()->insertID();
        $accountId = $this->seedAccount(1, '105329485290343', 'tok');

        $link = (new MetaLeadAdsLinker(new FakeLeadGraph()))->linkSocialAccount(1, $accountId);

        $this->assertSame($oldId, $link['id']);
        $row = $this->integration();
        $this->assertNull($row['deleted_at']);
        $this->assertSame('105329485290343', $row['page_id']);
    }

    public function testLinkCannotReachAnotherTenantsPage(): void
    {
        $accountId = $this->seedAccount(2, '105329485290343', 'tok');

        $this->expectExceptionMessageMatches('/not found/');
        (new MetaLeadAdsLinker(new FakeLeadGraph()))->linkSocialAccount(1, $accountId);
    }

    public function testLinkRefusesAPageWithoutAStoredToken(): void
    {
        $accountId = $this->seedAccount(1, '105329485290343', '');

        $this->expectExceptionMessageMatches('/connect it with Facebook/');
        (new MetaLeadAdsLinker(new FakeLeadGraph()))->linkSocialAccount(1, $accountId);
    }

    public function testLinkRefusesAPageThatNeedsReauth(): void
    {
        $accountId = $this->seedAccount(1, '105329485290343', 'tok', 'reauth_required');

        $this->expectExceptionMessageMatches('/reconnecting/');
        (new MetaLeadAdsLinker(new FakeLeadGraph()))->linkSocialAccount(1, $accountId);
    }

    // ── subscribe ───────────────────────────────────────────────────────

    public function testSubscribePostsLeadgenWithThePageTokenAndRecordsIt(): void
    {
        $graph     = new FakeLeadGraph();
        $linker    = new MetaLeadAdsLinker($graph);
        $accountId = $this->seedAccount(1, '105329485290343', 'page-token-abc');
        $link      = $linker->linkSocialAccount(1, $accountId);

        $linker->subscribe(1, $link['id']);

        $this->assertCount(1, $graph->posts);
        $this->assertSame('105329485290343/subscribed_apps', $graph->posts[0]['path']);
        $this->assertSame('leadgen', $graph->posts[0]['params']['subscribed_fields']);
        $this->assertSame('page-token-abc', $graph->posts[0]['params']['access_token']);

        $config = json_decode($this->integration()['config'], true);
        $this->assertTrue($config['subscribed']);
        $this->assertNotEmpty($config['subscribed_at']);
    }

    public function testSubscribeSurfacesMetaRefusalAndLeavesTheLinkUnsubscribed(): void
    {
        // The common case: a token minted before pages_manage_metadata was in
        // the Login configuration. Only the user can fix it (reconnect), so the
        // reason must reach them verbatim.
        $graph        = new FakeLeadGraph();
        $graph->error = 'Graph API error 200 (OAuthException): (#200) Requires pages_manage_metadata permission';
        $linker       = new MetaLeadAdsLinker($graph);
        $accountId    = $this->seedAccount(1, '105329485290343', 'tok');
        $link         = $linker->linkSocialAccount(1, $accountId);

        try {
            $linker->subscribe(1, $link['id']);
            $this->fail('expected the refusal to throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pages_manage_metadata', $e->getMessage());
        }

        $config = json_decode($this->integration()['config'], true);
        $this->assertArrayNotHasKey('subscribed', $config);
    }

    public function testSubscribeTreatsAnUnconfirmedResponseAsFailure(): void
    {
        $graph          = new FakeLeadGraph();
        $graph->success = false;
        $linker         = new MetaLeadAdsLinker($graph);
        $link           = $linker->linkSocialAccount(1, $this->seedAccount(1, '1', 'tok'));

        $this->expectExceptionMessageMatches('/did not confirm/');
        $linker->subscribe(1, $link['id']);
    }

    public function testSubscribeIsTenantScoped(): void
    {
        $linker = new MetaLeadAdsLinker(new FakeLeadGraph());
        $link   = $linker->linkSocialAccount(1, $this->seedAccount(1, '1', 'tok'));

        $this->expectExceptionMessageMatches('/not found/');
        $linker->subscribe(2, $link['id']);
    }

    public function testMockModeSkipsGraphButStillMarksSubscribed(): void
    {
        $_ENV['META_LEADS_MOCK_MODE'] = 'true';
        $graph  = new FakeLeadGraph();
        $linker = new MetaLeadAdsLinker($graph);
        $link   = $linker->linkSocialAccount(1, $this->seedAccount(1, '1', 'tok'));

        $linker->subscribe(1, $link['id']);

        $this->assertSame([], $graph->posts);
        $this->assertTrue(json_decode($this->integration()['config'], true)['subscribed']);
    }
}

/**
 * Records POSTs and answers like Graph's /{page}/subscribed_apps. No HTTP.
 */
class FakeLeadGraph extends GraphClient
{
    /** @var array<int,array{path:string,params:array<string,mixed>}> */
    public array $posts = [];

    public bool $success = true;

    public ?string $error = null;

    public function __construct()
    {
        // No HTTP client needed.
    }

    public function post(string $path, array $params = []): array
    {
        $this->posts[] = ['path' => $path, 'params' => $params];

        if ($this->error !== null) {
            throw new \RuntimeException($this->error);
        }

        return ['success' => $this->success];
    }
}
