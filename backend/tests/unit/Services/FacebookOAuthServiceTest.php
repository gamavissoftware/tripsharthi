<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SocialAccountModel;
use App\Services\Social\FacebookOAuthService;
use App\Services\Social\GraphClient;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Facebook Login for the Social Planner.
 *
 * The rules worth pinning down are the ones that stop a browser redirect from
 * being a way in: a state is single-use, expires, belongs to exactly one
 * tenant, and no access token ever leaves the server. The happy path also has
 * to prove the short→long token exchange actually happens, since skipping it
 * is what made the old hand-pasted connections die after an hour.
 *
 * FakeGraph records every call and returns canned payloads — no HTTP.
 * Tables are dropped + recreated in setUp so the shared :memory: DB cannot leak
 * schema or rows between suites (random-order safe).
 */
class FacebookOAuthServiceTest extends CIUnitTestCase
{
    /** @var array<string,string|null> real .env values, restored in tearDown */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        // env() reads $_ENV before getenv(), and the developer's real .env has
        // these set — so putenv() alone would be silently ignored here.
        foreach (['META_APP_ID', 'META_APP_SECRET', 'META_LOGIN_CONFIG_ID'] as $k) {
            $this->savedEnv[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
        $this->setEnv('META_APP_ID', 'test-app-id');
        $this->setEnv('META_APP_SECRET', 'test-app-secret');

        $db = db_connect();
        $p  = $db->DBPrefix;

        $db->query("DROP TABLE IF EXISTS {$p}social_oauth_states");
        $db->query("DROP TABLE IF EXISTS {$p}social_posts");
        $db->query("DROP TABLE IF EXISTS {$p}social_accounts");

        $db->query("CREATE TABLE {$p}social_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            page_id TEXT, page_name TEXT, ig_user_id TEXT, ig_username TEXT,
            access_token_enc TEXT, token_expires_at TEXT, connected_by INTEGER,
            connect_method TEXT DEFAULT 'manual', status TEXT DEFAULT 'active',
            last_error TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE {$p}social_oauth_states (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER,
            state TEXT, user_token_enc TEXT, pages_json TEXT,
            status TEXT DEFAULT 'pending', return_to TEXT DEFAULT 'social',
            error TEXT, expires_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
            if ($v !== null) {
                $_ENV[$k] = $v;
            }
        }
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    private function clearEnv(string $key): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    private function service(?FakeGraph $graph = null): FacebookOAuthService
    {
        return new FacebookOAuthService($graph ?? new FakeGraph());
    }

    /** Drive a full start → callback and return the state string. */
    private function startAndCallback(FakeGraph $graph, int $tenantId = 1, int $userId = 7): string
    {
        $svc = $this->service($graph);
        $url = $svc->start($tenantId, $userId);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $svc->handleCallback('the-code', $q['state']);

        return $q['state'];
    }

    // ── start ───────────────────────────────────────────────────────────

    public function testStartCreatesPendingStateAndBuildsDialogUrl(): void
    {
        $url = $this->service()->start(1, 7);

        $this->assertStringStartsWith('https://www.facebook.com/', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertSame('test-app-id', $q['client_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertStringContainsString('pages_manage_posts', $q['scope']);
        $this->assertStringContainsString('/api/v1/social/oauth/callback', $q['redirect_uri']);

        $row = db_connect()->table('social_oauth_states')->where('state', $q['state'])->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int) $row['tenant_id']);
        $this->assertSame(7, (int) $row['user_id']);
        // No credential is stored before the user has approved anything.
        $this->assertEmpty($row['user_token_enc']);
    }

    public function testStartSendsScopesWhenNoBusinessConfigurationIsSet(): void
    {
        parse_str((string) parse_url($this->service()->start(1, 7), PHP_URL_QUERY), $q);

        $this->assertStringContainsString('pages_manage_posts', $q['scope']);
        $this->assertArrayNotHasKey('config_id', $q);
        $this->assertArrayNotHasKey('override_default_response_type', $q);
    }

    public function testStartUsesConfigIdAndDropsScopeForFacebookLoginForBusiness(): void
    {
        // A business-login app rejects the classic parameter outright:
        // "Invalid Scopes: pages_show_list, pages_manage_posts, …". The
        // permissions live in the dashboard configuration instead.
        $this->setEnv('META_LOGIN_CONFIG_ID', '1122334455');

        parse_str((string) parse_url($this->service()->start(1, 7), PHP_URL_QUERY), $q);

        $this->assertSame('1122334455', $q['config_id']);
        $this->assertArrayNotHasKey('scope', $q, 'scope must not be sent alongside config_id.');
        // Without this the configuration's default response type wins and the
        // callback never receives a code to exchange.
        $this->assertSame('true', $q['override_default_response_type']);
        $this->assertSame('code', $q['response_type']);
        // The rest of the flow is unchanged.
        $this->assertSame('test-app-id', $q['client_id']);
        $this->assertStringContainsString('/api/v1/social/oauth/callback', $q['redirect_uri']);
        $this->assertNotEmpty($q['state']);
    }

    public function testScopesCoverLeadAdsAsWellAsPublishing(): void
    {
        // One Page token serves both the Social Planner and Meta Lead Ads, so a
        // classic-login app must ask for the two Lead Ads permissions up front:
        // reading a lead's answers, and subscribing the Page to leadgen.
        parse_str((string) parse_url($this->service()->start(1, 7), PHP_URL_QUERY), $q);

        $this->assertStringContainsString('leads_retrieval', $q['scope']);
        $this->assertStringContainsString('pages_manage_metadata', $q['scope']);
    }

    public function testStartRecordsWhereToReturnAndTheCallbackBouncesThere(): void
    {
        $graph = new FakeGraph();
        $url   = $this->service($graph)->start(1, 7, 'integrations');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $row = db_connect()->table('social_oauth_states')->where('state', $q['state'])->get()->getRowArray();
        $this->assertSame('integrations', $row['return_to']);

        $result = $this->service($graph)->handleCallback('the-code', $q['state']);
        $this->assertSame('integrations', $result['return_to']);
        $this->assertSame('integrations', FacebookOAuthService::returnToFor($q['state']));

        $this->assertStringContainsString('/#/settings/integrations?', FacebookOAuthService::spaReturnUrl('a=1', 'integrations'));
        $this->assertStringContainsString('/#/social?', FacebookOAuthService::spaReturnUrl('a=1'));
    }

    public function testAnAdsConnectionSucceedsWithNoPagesAndKeepsTheUserToken(): void
    {
        // Ad spend needs only the user token; an account with no Pages must
        // still get through, and the callback must bounce to Integrations.
        $graph        = new FakeGraph();
        $graph->pages = [];
        $url          = $this->service($graph)->start(1, 7, 'ads');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $result = $this->service($graph)->handleCallback('the-code', $q['state']);
        $this->assertSame('ads', $result['return_to']);

        $row = db_connect()->table('social_oauth_states')->where('state', $q['state'])->get()->getRowArray();
        $this->assertSame('ready', $row['status']);
        $this->assertSame('long-lived-token', TokenCipher::decrypt($row['user_token_enc']));
        $this->assertStringContainsString('/#/settings/integrations?', FacebookOAuthService::spaReturnUrl('a=1', 'ads'));
    }

    public function testClassicScopesIncludeAdsRead(): void
    {
        parse_str((string) parse_url($this->service()->start(1, 7), PHP_URL_QUERY), $q);
        $this->assertStringContainsString('ads_read', $q['scope']);
    }

    public function testUnknownReturnToFallsBackToSocial(): void
    {
        // A tampered or stale value must not turn the bounce into an open redirect.
        $url = $this->service()->start(1, 7, '../../evil');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $row = db_connect()->table('social_oauth_states')->where('state', $q['state'])->get()->getRowArray();
        $this->assertSame('social', $row['return_to']);
        $this->assertStringContainsString('/#/social?', FacebookOAuthService::spaReturnUrl('x=1', '../../evil'));
        $this->assertSame('social', FacebookOAuthService::returnToFor('no-such-state'));
    }

    public function testStartRefusesWhenAppCredentialsAreMissing(): void
    {
        $this->clearEnv('META_APP_ID');

        $this->expectException(\RuntimeException::class);
        $this->service()->start(1, 7);
    }

    public function testIsConfiguredNeedsBothHalves(): void
    {
        $this->assertTrue(FacebookOAuthService::isConfigured());

        $this->clearEnv('META_APP_SECRET');
        $this->assertFalse(FacebookOAuthService::isConfigured());
    }

    // ── callback ────────────────────────────────────────────────────────

    public function testCallbackExchangesForLongLivedTokenAndStoresPages(): void
    {
        $graph = new FakeGraph();
        $state = $this->startAndCallback($graph);

        // The short→long exchange is the whole point: assert it happened.
        $grants = array_column($graph->calls, 'params');
        $this->assertSame('the-code', $grants[0]['code']);
        $this->assertSame('fb_exchange_token', $grants[1]['grant_type']);
        $this->assertSame('short-lived-token', $grants[1]['fb_exchange_token']);
        // and that the page list was fetched with the LONG one.
        $this->assertSame('long-lived-token', $grants[2]['access_token']);

        $row = db_connect()->table('social_oauth_states')->where('state', $state)->get()->getRowArray();
        $this->assertSame('ready', $row['status']);
        $this->assertSame('long-lived-token', TokenCipher::decrypt($row['user_token_enc']));

        // The stashed list is display-only — a page token must never sit here.
        $this->assertStringNotContainsString('access_token', (string) $row['pages_json']);
        $pages = json_decode((string) $row['pages_json'], true);
        $this->assertCount(2, $pages);
        $this->assertSame('Easemysale', $pages[0]['page_name']);
        $this->assertSame('17840000000', $pages[0]['ig_user_id']);
    }

    public function testCallbackRejectsUnknownState(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service()->handleCallback('the-code', 'never-issued');
    }

    public function testCallbackCannotBeReplayed(): void
    {
        $graph = new FakeGraph();
        $state = $this->startAndCallback($graph);

        // Second delivery of the same state — a refresh, or an attacker
        // replaying the redirect — must not start another exchange.
        $before = count($graph->calls);
        try {
            $this->service($graph)->handleCallback('the-code', $state);
            $this->fail('Replayed state was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already been used', $e->getMessage());
        }
        $this->assertCount($before, $graph->calls);
    }

    public function testCallbackRejectsExpiredState(): void
    {
        $svc = $this->service();
        $url = $svc->start(1, 7);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        db_connect()->table('social_oauth_states')->where('state', $q['state'])
            ->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);

        $this->expectExceptionMessageMatches('/expired/i');
        $svc->handleCallback('the-code', $q['state']);
    }

    public function testCallbackRecordsFailureWhenGraphRefuses(): void
    {
        $graph = new FakeGraph();
        $graph->failOn = 'oauth/access_token';

        $svc = $this->service($graph);
        $url = $svc->start(1, 7);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        try {
            $svc->handleCallback('bad-code', $q['state']);
            $this->fail('Graph failure was swallowed.');
        } catch (\RuntimeException $e) {
            // expected — the caller redirects with the message
        }

        $row = db_connect()->table('social_oauth_states')->where('state', $q['state'])->get()->getRowArray();
        $this->assertSame('failed', $row['status']);
        $this->assertNotEmpty($row['error']);
    }

    public function testEmptyPageListBlamesTheMissingPermissionWhenThatIsTheCause(): void
    {
        // The honest diagnosis: pages_show_list was never granted, so Meta had
        // nothing to list. Saying "you have no Pages" here sends the user
        // hunting for a problem that does not exist.
        $graph                     = new FakeGraph();
        $graph->pages              = [];
        $graph->grantedPermissions = ['public_profile', 'pages_manage_posts'];

        $svc = $this->service($graph);
        $url = $svc->start(1, 7);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        try {
            $svc->handleCallback('the-code', $q['state']);
            $this->fail('Empty page list was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not grant pages_show_list', $e->getMessage());
            $this->assertStringContainsString('pages_manage_posts', $e->getMessage(), 'lists what WAS granted');
        }
    }

    public function testEmptyPageListPointsAtTheAssetStepWhenThePermissionWasGranted(): void
    {
        $graph                     = new FakeGraph();
        $graph->pages              = [];
        $graph->grantedPermissions = ['public_profile', 'pages_show_list', 'pages_manage_posts'];

        $svc = $this->service($graph);
        $url = $svc->start(1, 7);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->expectExceptionMessageMatches('/no Page was ticked/');
        $svc->handleCallback('the-code', $q['state']);
    }

    public function testEmptyPageListWithNoPermissionsAtAllAsksForAReRun(): void
    {
        $graph                     = new FakeGraph();
        $graph->pages              = [];
        $graph->grantedPermissions = [];

        $svc = $this->service($graph);
        $url = $svc->start(1, 7);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->expectExceptionMessageMatches('/granted no permissions at all/');
        $svc->handleCallback('the-code', $q['state']);
    }

    // ── pages ───────────────────────────────────────────────────────────

    public function testPagesIsScopedToTheTenantThatStartedTheFlow(): void
    {
        $state = $this->startAndCallback(new FakeGraph(), 1);

        $this->assertCount(2, $this->service()->pages($state, 1));

        // Tenant 2 holding tenant 1's state gets nothing.
        $this->expectExceptionMessageMatches('/Unknown connection attempt/');
        $this->service()->pages($state, 2);
    }

    // ── selectPage ──────────────────────────────────────────────────────

    public function testSelectPageStoresEncryptedTokenAndConsumesState(): void
    {
        $graph = new FakeGraph();
        $state = $this->startAndCallback($graph, 1, 7);

        $account = $this->service($graph)->selectPage($state, '111222333', 1, 7);

        // Response must never carry the ciphertext, let alone the token.
        $this->assertArrayNotHasKey('access_token_enc', $account);
        $this->assertSame('Easemysale', $account['page_name']);
        $this->assertSame('oauth', $account['connect_method']);
        $this->assertSame(7, (int) $account['connected_by']);
        // Derived from a long-lived user token, so there is no expiry to chase.
        $this->assertNull($account['token_expires_at']);

        $stored = db_connect()->table('social_accounts')->where('page_id', '111222333')->get()->getRowArray();
        $this->assertSame('page-token-111222333', TokenCipher::decrypt($stored['access_token_enc']));
        $this->assertSame('17840000000', $stored['ig_user_id']);

        // State consumed and the user token wiped now it is no longer needed.
        $row = db_connect()->table('social_oauth_states')->where('state', $state)->get()->getRowArray();
        $this->assertSame('consumed', $row['status']);
        $this->assertEmpty($row['user_token_enc']);
        $this->assertEmpty($row['pages_json']);
    }

    public function testSelectPageRejectsAPageTheUserDoesNotAdminister(): void
    {
        $graph = new FakeGraph();
        $state = $this->startAndCallback($graph);

        $this->expectExceptionMessageMatches('/no longer available/');
        $this->service($graph)->selectPage($state, '999999999', 1, 7);
    }

    public function testSelectPageCannotBeReusedAfterConsuming(): void
    {
        $graph = new FakeGraph();
        $state = $this->startAndCallback($graph);
        $this->service($graph)->selectPage($state, '111222333', 1, 7);

        $this->expectExceptionMessageMatches('/no longer valid/');
        $this->service($graph)->selectPage($state, '444555666', 1, 7);
    }

    public function testReconnectingAPreviouslyDisconnectedPageRevivesTheRow(): void
    {
        // Disconnect is a SOFT delete and (tenant_id, page_id) is UNIQUE, so a
        // naive insert here would die with "Duplicate entry" — the row is still
        // present, just invisible to find().
        $accounts = new SocialAccountModel();
        $accounts->setTenant(1)->insert([
            'tenant_id'        => 1,
            'page_id'          => '111222333',
            'page_name'        => 'Easemysale',
            'access_token_enc' => TokenCipher::encrypt('old-token'),
        ]);
        $id = db_connect()->insertID();
        $accounts->setTenant(1)->delete($id);
        $this->assertNull($accounts->setTenant(1)->find($id), 'precondition: soft-deleted');

        $graph = new FakeGraph();
        $state = $this->startAndCallback($graph);
        $this->service($graph)->selectPage($state, '111222333', 1, 7);

        $rows = db_connect()->table('social_accounts')->where('page_id', '111222333')->get()->getResultArray();
        $this->assertCount(1, $rows, 'Reconnect must revive the row, not add a second.');
        $this->assertEmpty($rows[0]['deleted_at'], 'Reconnect must clear deleted_at.');
        $this->assertSame('page-token-111222333', TokenCipher::decrypt($rows[0]['access_token_enc']));
    }

    public function testReconnectingClearsAPreviousReauthFlag(): void
    {
        // A page that died and was flagged by the dispatcher…
        (new SocialAccountModel())->setTenant(1)->insert([
            'tenant_id'        => 1,
            'page_id'          => '111222333',
            'page_name'        => 'Easemysale',
            'access_token_enc' => TokenCipher::encrypt('dead-token'),
            'connect_method'   => 'manual',
            'status'           => 'reauth_required',
            'last_error'       => 'Graph API error 190 (OAuthException): token expired',
        ]);

        $graph = new FakeGraph();
        $state = $this->startAndCallback($graph);
        $this->service($graph)->selectPage($state, '111222333', 1, 7);

        $row = db_connect()->table('social_accounts')->where('page_id', '111222333')->get()->getResultArray();
        $this->assertCount(1, $row, 'Reconnecting must update the existing row, not add a second one.');
        $this->assertSame('active', $row[0]['status']);
        $this->assertSame('oauth', $row[0]['connect_method']);
        $this->assertEmpty($row[0]['last_error']);
        $this->assertSame('page-token-111222333', TokenCipher::decrypt($row[0]['access_token_enc']));
    }
}

/**
 * Canned Graph API. Records every call so the tests can assert on the exchange
 * sequence, not just the end state.
 */
class FakeGraph extends GraphClient
{
    /** @var array<int,array{path:string,params:array<string,mixed>}> */
    public array $calls = [];

    public ?string $failOn = null;

    /** @var array<int,string> permissions /me/permissions reports as granted */
    public array $grantedPermissions = ['public_profile', 'pages_show_list', 'pages_manage_posts'];

    /** @var array<int,array<string,mixed>> */
    public array $pages = [
        [
            'id'                         => '111222333',
            'name'                       => 'Easemysale',
            'access_token'               => 'page-token-111222333',
            'instagram_business_account' => ['id' => '17840000000', 'username' => 'easemysale'],
        ],
        [
            'id'           => '444555666',
            'name'         => 'Second Page',
            'access_token' => 'page-token-444555666',
        ],
    ];

    public function __construct()
    {
        // Deliberately does not call parent::__construct — no HTTP client needed.
    }

    public function get(string $path, array $params = []): array
    {
        $this->calls[] = ['path' => $path, 'params' => $params];

        if ($this->failOn !== null && $path === $this->failOn) {
            throw new \RuntimeException('Graph API error 100 (OAuthException): bad code');
        }

        if ($path === 'oauth/access_token') {
            return ['access_token' => ($params['grant_type'] ?? '') === 'fb_exchange_token'
                ? 'long-lived-token'
                : 'short-lived-token'];
        }

        if ($path === 'me/permissions') {
            return ['data' => array_map(
                static fn (string $p): array => ['permission' => $p, 'status' => 'granted'],
                $this->grantedPermissions
            )];
        }

        if ($path === 'me/accounts') {
            // Mirror Graph: access_token comes back only when asked for.
            $wantsToken = str_contains((string) ($params['fields'] ?? ''), 'access_token');

            return ['data' => array_map(static function (array $p) use ($wantsToken): array {
                if (! $wantsToken) {
                    unset($p['access_token']);
                }

                return $p;
            }, $this->pages)];
        }

        return [];
    }

    public function post(string $path, array $params = []): array
    {
        $this->calls[] = ['path' => $path, 'params' => $params];

        return ['id' => 'unused'];
    }
}
