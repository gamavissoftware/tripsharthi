<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialAccountModel;
use App\Models\SocialOauthStateModel;
use App\Services\Email\EmailService;
use App\Services\WhatsApp\TokenCipher;
use RuntimeException;

/**
 * "Connect with Facebook" — the Facebook Login flow for the Social Planner.
 *
 * Replaces hand-pasted Page Access Tokens. The four steps:
 *
 *   1. start()      — mint a state row, return Meta's consent dialog URL.
 *   2. callback()   — Meta redirects the browser back with ?code&state. The
 *                     code is exchanged for a short-lived user token, that is
 *                     exchanged for a LONG-LIVED one, and /me/accounts gives
 *                     the pages the user administers.
 *   3. pages()      — the picker reads the display list (never tokens).
 *   4. selectPage() — the chosen page's token is fetched fresh, stored
 *                     encrypted, and the state row is consumed.
 *
 * Why long-lived matters: a page token derived from a long-lived user token
 * does not expire, which is the entire reason this flow exists. The Graph API
 * Explorer's default user token lasts an hour or two, and the old paste-a-token
 * flow had no way to tell the difference until posts started failing.
 *
 * Nothing here trusts the browser: the state row carries the tenant and user,
 * so a callback cannot be replayed, used by another tenant, or forged.
 */
class FacebookOAuthService
{
    /**
     * Scopes for a classic Facebook Login app. Social scheduling, plus the two
     * Meta Lead Ads needs from the same Page token: `leads_retrieval` to read a
     * lead's form answers and `pages_manage_metadata` to subscribe the Page to
     * the leadgen webhook. A Facebook Login *for Business* app ignores this
     * list — those permissions must be ticked in the dashboard configuration.
     */
    public const SCOPES = [
        'pages_show_list',
        'pages_manage_posts',
        'pages_read_engagement',
        'pages_manage_metadata',
        'leads_retrieval',
        'ads_read',
        'instagram_basic',
        'instagram_content_publish',
    ];

    /**
     * SPA pages a finished login may bounce back to, keyed by the `return_to`
     * value the caller passes to start(). Anything else falls back to social.
     */
    public const RETURN_ROUTES = [
        'social'       => '/social',
        'integrations' => '/settings/integrations',
        // Meta Ads spend: the same login, but the SPA keeps the USER token
        // (via MetaAdsService::connect) instead of picking a Page.
        'ads'          => '/settings/integrations',
        // Campaign MANAGEMENT: same login, but additionally asks for ads_management + pages_manage_ads
        // (needs Meta App Review / Advanced Access to work for tenants who are not app roles).
        'ads_manage'   => '/settings/ads',
    ];

    /** Extra permissions requested only when a tenant opts in to campaign management. */
    public const MANAGE_SCOPES = ['ads_management', 'pages_manage_ads'];

    /** A connect attempt is abandoned if not completed within this window. */
    private const STATE_TTL_SECONDS = 900; // 15 minutes

    public function __construct(private ?GraphClient $graph = null)
    {
        $this->graph ??= new GraphClient();
    }

    // ── Configuration ───────────────────────────────────────────────────

    public static function appId(): string
    {
        return trim((string) env('META_APP_ID', ''));
    }

    public static function appSecret(): string
    {
        return trim((string) env('META_APP_SECRET', ''));
    }

    /**
     * Facebook Login for Business configuration id, when the Meta app uses that
     * product rather than classic Facebook Login.
     *
     * Empty for a classic app, which is why this is optional rather than part
     * of isConfigured(): both products are legitimate, and which one an app has
     * is not something the server can detect — Meta only tells you by refusing
     * the dialog.
     */
    public static function loginConfigId(): string
    {
        return trim((string) env('META_LOGIN_CONFIG_ID', ''));
    }

    /**
     * Configured means both halves of the app credential are present. Without
     * them the UI hides the button rather than sending the user to a Meta error
     * page — a self-hosted install may legitimately have no Meta app.
     */
    public static function isConfigured(): bool
    {
        return self::appId() !== '' && self::appSecret() !== '';
    }

    /**
     * The redirect URI, which must match an entry in the Meta app's Facebook
     * Login settings byte for byte. Built from the BACKEND base URL, because
     * this endpoint is served by the backend, not the SPA.
     */
    public static function redirectUri(): string
    {
        return rtrim((string) base_url(), '/') . '/api/v1/social/oauth/callback';
    }

    // ── 1. Start ────────────────────────────────────────────────────────

    /**
     * Create a state row and return the consent dialog URL.
     */
    public function start(int $tenantId, int $userId, string $returnTo = 'social'): string
    {
        if (! self::isConfigured()) {
            throw new RuntimeException('Facebook Login is not configured on this server (META_APP_ID / META_APP_SECRET).');
        }

        $state = bin2hex(random_bytes(24));

        (new SocialOauthStateModel())->setTenant($tenantId)->insert([
            'tenant_id'  => $tenantId,
            'user_id'    => $userId,
            'state'      => $state,
            'status'     => 'pending',
            'return_to'  => isset(self::RETURN_ROUTES[$returnTo]) ? $returnTo : 'social',
            'expires_at' => date('Y-m-d H:i:s', time() + self::STATE_TTL_SECONDS),
        ]);

        $params = [
            'client_id'     => self::appId(),
            'redirect_uri'  => self::redirectUri(),
            'state'         => $state,
            'response_type' => 'code',
        ];

        $configId = self::loginConfigId();
        if ($configId !== '') {
            // Facebook Login FOR BUSINESS. Permissions are not requested per
            // call here — they are baked into a configuration created in the
            // app dashboard — and passing `scope` is rejected outright with
            // "Invalid Scopes: …", which is what a business-login app does to
            // the classic parameter list.
            //
            // override_default_response_type is required alongside config_id:
            // a business-login configuration defaults to returning a token,
            // and without this the `response_type=code` above is ignored and
            // no code ever reaches the callback.
            $params['config_id']                     = $configId;
            $params['override_default_response_type'] = 'true';
        } else {
            // Classic Facebook Login: ask for the scopes directly.
            $params['scope'] = implode(',', $returnTo === 'ads_manage' ? array_merge(self::SCOPES, self::MANAGE_SCOPES) : self::SCOPES);
        }

        return 'https://www.facebook.com/v21.0/dialog/oauth?' . http_build_query($params);
    }

    // ── 2. Callback ─────────────────────────────────────────────────────

    /**
     * Exchange the code, stash the long-lived user token + page display list.
     *
     * Returns the state string so the caller can bounce the browser back to the
     * SPA. Throws on anything suspicious — an unknown, expired, already-used
     * state, or a Graph refusal.
     */
    public function handleCallback(string $code, string $state): array
    {
        $row = $this->findUsableState($state);

        try {
            // Short-lived user token from the one-time code.
            $short = $this->graph->get('oauth/access_token', [
                'client_id'     => self::appId(),
                'client_secret' => self::appSecret(),
                'redirect_uri'  => self::redirectUri(),
                'code'          => $code,
            ]);
            $shortToken = (string) ($short['access_token'] ?? '');
            if ($shortToken === '') {
                throw new RuntimeException('Meta returned no access token for the authorisation code.');
            }

            // Exchange for the long-lived one. This is the step the manual
            // flow always skipped, and the reason connections used to die.
            $long = $this->graph->get('oauth/access_token', [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => self::appId(),
                'client_secret'     => self::appSecret(),
                'fb_exchange_token' => $shortToken,
            ]);
            $longToken = (string) ($long['access_token'] ?? $shortToken);

            // The pages this user can publish to.
            $accounts = $this->graph->get('me/accounts', [
                'fields'       => 'id,name,instagram_business_account{id,username}',
                'limit'        => 100,
                'access_token' => $longToken,
            ]);

            $pages = [];
            foreach ((array) ($accounts['data'] ?? []) as $page) {
                $page    = (array) $page;
                $pages[] = [
                    'page_id'     => (string) ($page['id'] ?? ''),
                    'page_name'   => (string) ($page['name'] ?? ''),
                    'ig_user_id'  => $page['instagram_business_account']['id'] ?? null,
                    'ig_username' => $page['instagram_business_account']['username'] ?? null,
                ];
            }

            // An ads connection needs no Page at all — only the user token.
            if ($pages === [] && ! in_array(($row['return_to'] ?? 'social'), ['ads', 'ads_manage'], true)) {
                throw new RuntimeException($this->explainEmptyPageList($longToken));
            }

            (new SocialOauthStateModel())->withoutTenantScope()->update((int) $row['id'], [
                'user_token_enc' => TokenCipher::encrypt($longToken),
                'pages_json'     => json_encode($pages),
                'status'         => 'ready',
            ]);
        } catch (\Throwable $e) {
            (new SocialOauthStateModel())->withoutTenantScope()->update((int) $row['id'], [
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }

        return [
            'state'     => $state,
            'tenant_id' => (int) $row['tenant_id'],
            'return_to' => (string) ($row['return_to'] ?? 'social'),
        ];
    }

    /**
     * Which SPA page a callback for this state should land on. Public because
     * the callback controller needs it even when the exchange itself failed.
     */
    public static function returnToFor(string $state): string
    {
        $row = (new SocialOauthStateModel())->withoutTenantScope()
            ->select('return_to')->where('state', $state)->first();

        return (string) (((array) ($row ?? []))['return_to'] ?? 'social');
    }

    /**
     * /me/accounts came back empty.
     *
     * Taken at face value that means "this account runs no Pages", and the
     * first version of this said exactly that — which is wrong often enough to
     * be actively misleading. Far more common: the Login configuration never
     * asked for `pages_show_list`, or the person did approve but did not tick
     * the Page on Meta's asset-selection step. Both look identical from here.
     *
     * So ask Meta what it actually granted and name the real problem. Wrapped
     * because a diagnostic must never replace the failure it is explaining.
     */
    private function explainEmptyPageList(string $userToken): string
    {
        $granted = [];

        try {
            $perms = $this->graph->get('me/permissions', ['access_token' => $userToken]);
            foreach ((array) ($perms['data'] ?? []) as $perm) {
                $perm = (array) $perm;
                if (($perm['status'] ?? '') === 'granted' && ! empty($perm['permission'])) {
                    $granted[] = (string) $perm['permission'];
                }
            }
        } catch (\Throwable $e) {
            log_message('error', '[social oauth] could not read granted permissions: ' . $e->getMessage());
        }

        if ($granted === []) {
            return 'Facebook granted no permissions at all, so no Pages could be read. '
                 . 'Start the connection again and approve the access it asks for.';
        }

        if (! in_array('pages_show_list', $granted, true)) {
            return 'Facebook did not grant pages_show_list, which is the permission that lists your Pages — '
                 . 'add it to the Login configuration in the Meta app dashboard, then connect again. '
                 . 'Granted this time: ' . implode(', ', $granted) . '.';
        }

        return 'Facebook granted pages_show_list but returned no Pages. That almost always means no Page was '
             . 'ticked on Meta\'s "what do you want to allow" step — run the connection again and select the Page there. '
             . 'Granted: ' . implode(', ', $granted) . '.';
    }

    // ── 3. Pages ────────────────────────────────────────────────────────

    /**
     * Display list for the picker. Tenant-checked: a state belonging to another
     * tenant is indistinguishable from one that does not exist.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pages(string $state, int $tenantId): array
    {
        $row = $this->findState($state, $tenantId);

        if ($row['status'] === 'failed') {
            throw new RuntimeException((string) ($row['error'] ?: 'The Facebook connection attempt failed.'));
        }
        if ($row['status'] !== 'ready') {
            throw new RuntimeException('This connection attempt is not ready yet.');
        }

        return (array) (json_decode((string) $row['pages_json'], true) ?: []);
    }

    // ── 4. Select ───────────────────────────────────────────────────────

    /**
     * Connect the chosen page and consume the state.
     *
     * The page token is fetched fresh here rather than stashed at callback
     * time, so the only credential ever at rest between the two steps is the
     * single user token — not one token per page the user happens to admin.
     *
     * @return array<string,mixed> the connected account row (no ciphertext)
     */
    public function selectPage(string $state, string $pageId, int $tenantId, int $userId): array
    {
        $row = $this->findState($state, $tenantId);
        if ($row['status'] !== 'ready') {
            throw new RuntimeException('This connection attempt is no longer valid. Start again.');
        }

        $userToken = (new SocialOauthStateModel())->getDecryptedUserToken($row);
        if ($userToken === '') {
            throw new RuntimeException('The Facebook session for this attempt has expired. Start again.');
        }

        $accounts = $this->graph->get('me/accounts', [
            'fields'       => 'id,name,access_token,instagram_business_account{id,username}',
            'limit'        => 100,
            'access_token' => $userToken,
        ]);

        $match = null;
        foreach ((array) ($accounts['data'] ?? []) as $page) {
            $page = (array) $page;
            if ((string) ($page['id'] ?? '') === $pageId) {
                $match = $page;
                break;
            }
        }

        if ($match === null) {
            throw new RuntimeException('That Page is no longer available on this Facebook account.');
        }

        $pageToken = (string) ($match['access_token'] ?? '');
        if ($pageToken === '') {
            throw new RuntimeException('Meta did not return a Page access token — check the app has pages_manage_posts.');
        }

        $payload = [
            'page_id'          => $pageId,
            'page_name'        => (string) ($match['name'] ?? $pageId),
            'ig_user_id'       => $match['instagram_business_account']['id'] ?? null,
            'ig_username'      => $match['instagram_business_account']['username'] ?? null,
            'access_token_enc' => TokenCipher::encrypt($pageToken),
            // Derived from a long-lived user token: no expiry to track.
            'token_expires_at' => null,
            'connected_by'     => $userId,
            'connect_method'   => 'oauth',
            'status'           => 'active',
            'last_error'       => null,
        ];

        // upsertPage, not insert: reconnecting a page that was disconnected
        // earlier must revive that row, not collide with it.
        $id = (new SocialAccountModel())->upsertPage($tenantId, $pageId, $payload);

        // Consume: single use, and the user token is wiped now that the page
        // token we actually need is stored.
        (new SocialOauthStateModel())->withoutTenantScope()->update((int) $row['id'], [
            'status'         => 'consumed',
            'user_token_enc' => null,
            'pages_json'     => null,
        ]);

        $account = (array) (new SocialAccountModel())->setTenant($tenantId)->find($id);

        return (new SocialAccountModel())->publicRow($account);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Where the SPA lives, for bouncing the browser back after the callback.
     */
    public static function spaReturnUrl(string $query, string $returnTo = 'social'): string
    {
        $route = self::RETURN_ROUTES[$returnTo] ?? self::RETURN_ROUTES['social'];

        return EmailService::appUrl() . '/#' . $route . '?' . $query;
    }

    /**
     * A state that may still be advanced: exists, pending, unexpired. No tenant
     * filter — the callback has no session, the row itself supplies the tenant.
     *
     * @return array<string,mixed>
     */
    private function findUsableState(string $state): array
    {
        $row = (new SocialOauthStateModel())->withoutTenantScope()
            ->where('state', $state)->first();

        if (! $row) {
            throw new RuntimeException('Unknown or already-used connection attempt.');
        }
        $row = (array) $row;

        if ($row['status'] !== 'pending') {
            throw new RuntimeException('This connection attempt has already been used. Start again.');
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            throw new RuntimeException('This connection attempt expired. Start again.');
        }

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function findState(string $state, int $tenantId): array
    {
        $row = (new SocialOauthStateModel())->setTenant($tenantId)
            ->where('state', $state)->first();

        if (! $row) {
            throw new RuntimeException('Unknown connection attempt.');
        }

        return (array) $row;
    }
}
