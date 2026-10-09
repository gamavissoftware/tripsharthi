<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\ConversionEventModel;
use App\Models\IntegrationModel;
use App\Models\SocialOauthStateModel;
use App\Services\Email\EmailService;
use App\Services\WhatsApp\TokenCipher;

/**
 * Connect a tenant's own Meta dataset (Conversions API) and Google Ads account.
 *
 * Secrets are encrypted at rest (TokenCipher) under *_enc keys and are NEVER returned to the
 * browser — status() exposes only booleans and non-secret identifiers. Every credential is
 * validated against the platform before it is saved, so a typo fails here, not silently at 2am
 * when the first booking event is delivered.
 *
 * Platform-level settings (one app for all tenants) come from env:
 *   GOOGLE_ADS_CLIENT_ID / GOOGLE_ADS_CLIENT_SECRET   OAuth client for "Connect with Google"
 *   GOOGLE_ADS_DEVELOPER_TOKEN                        optional (Google ignores it under the new access model)
 *   META_GRAPH_VERSION / GOOGLE_ADS_API_VERSION       API versions (they sunset quickly)
 *   CONVERSIONS_MOCK_MODE=true                        simulate both platforms (local dev/tests)
 */
final class AdCredentialsService
{
    public const GOOGLE_SCOPE = 'https://www.googleapis.com/auth/adwords';
    private const STATE_TTL   = 900;

    /** @var callable(string,string,array):array{status:int,body:string} */
    private $http;

    public function __construct(?callable $http = null)
    {
        $this->http = $http ?? [ConversionFeedbackService::class, 'defaultHttp'];
    }

    private static function mock(): bool
    {
        return filter_var(env('CONVERSIONS_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }

    // ---- status -----------------------------------------------------------------------

    public function status(int $tenantId): array
    {
        $meta   = $this->load($tenantId, 'meta_capi');
        $google = $this->load($tenantId, 'google_ads');
        $names  = array_values(ConversionFeedbackService::EVENTS); // Lead, QuoteSent, Purchase

        return [
            'event_names' => $names,
            'meta' => [
                'connected'       => (bool) ($meta['dataset_id'] ?? false) && ! empty($meta['access_token_enc']),
                'dataset_id'      => $meta['dataset_id'] ?? '',
                'dataset_name'    => $meta['dataset_name'] ?? '',
                'waba_id'         => $meta['waba_id'] ?? '',
                'test_event_code' => $meta['test_event_code'] ?? '',
                'has_token'       => ! empty($meta['access_token_enc']),
                'events'          => $meta['events'] ?? $names,
            ],
            'google' => [
                'server_configured' => self::googleConfigured(),
                'connected'         => ! empty($google['refresh_token_enc']),
                'customer_id'       => $google['customer_id'] ?? '',
                'login_customer_id' => $google['login_customer_id'] ?? '',
                'conversion_actions' => $google['conversion_actions'] ?? new \stdClass(),
                'events'            => $google['events'] ?? $names,
            ],
            'redirect_uri' => self::googleRedirectUri(),
        ];
    }

    // ---- Meta Conversions API -------------------------------------------------------------

    /** Validate dataset + token with Meta, then store. Token may be omitted to keep the saved one. */
    public function saveMeta(int $tenantId, array $in): array
    {
        $cfg     = $this->load($tenantId, 'meta_capi');
        $dataset = preg_replace('/\D/', '', (string) ($in['dataset_id'] ?? ($cfg['dataset_id'] ?? '')));
        if ($dataset === '') {
            throw new \InvalidArgumentException('Dataset (Pixel) ID is required.');
        }
        $token = trim((string) ($in['access_token'] ?? ''));
        if ($token === '' && ! empty($cfg['access_token_enc'])) {
            $token = TokenCipher::decrypt($cfg['access_token_enc']);
        }
        if ($token === '') {
            throw new \InvalidArgumentException('Access token is required.');
        }

        $name = 'Mock dataset';
        if (! self::mock()) {
            $res  = ($this->http)('GET', $this->graph("/{$dataset}") . '?fields=id,name&access_token=' . rawurlencode($token), []);
            $body = json_decode($res['body'], true) ?: [];
            if ($res['status'] >= 400 || empty($body['id'])) {
                throw new \InvalidArgumentException('Meta rejected these credentials: ' . ($body['error']['message'] ?? 'unknown error') . ' — check the dataset ID and that the token has access to it.');
            }
            $name = (string) ($body['name'] ?? $dataset);
        }

        $cfg = array_merge($cfg, [
            'dataset_id' => $dataset, 'dataset_name' => $name, 'access_token_enc' => TokenCipher::encrypt($token),
            'waba_id' => preg_replace('/\D/', '', (string) ($in['waba_id'] ?? ($cfg['waba_id'] ?? ''))),
            'test_event_code' => trim((string) ($in['test_event_code'] ?? ($cfg['test_event_code'] ?? ''))),
            'events' => $this->cleanEvents($in['events'] ?? ($cfg['events'] ?? null)),
        ]);
        $this->store($tenantId, 'meta_capi', $cfg);
        return $this->status($tenantId)['meta'];
    }

    /** Send a test event (needs a Test Event Code from Events Manager). @return array{events_received:int,fbtrace_id:?string} */
    public function testMeta(int $tenantId): array
    {
        $cfg = $this->load($tenantId, 'meta_capi');
        if (empty($cfg['dataset_id']) || empty($cfg['access_token_enc'])) {
            throw new \InvalidArgumentException('Connect Meta first.');
        }
        if (empty($cfg['test_event_code'])) {
            throw new \InvalidArgumentException('Add a Test Event Code (Events Manager → Test events) so the test does not count as real data.');
        }
        if (self::mock()) {
            return ['events_received' => 1, 'fbtrace_id' => 'mock'];
        }
        $row = ['event_name' => 'Lead', 'event_id' => 'tp-test-' . bin2hex(random_bytes(4)), 'value_amount' => 0, 'currency' => 'INR', 'event_time' => date('Y-m-d H:i:s')];
        $ev  = ConversionFeedbackService::metaEvent($row, [], ['email' => 'test@example.com']);
        $res = ($this->http)('POST', $this->graph("/{$cfg['dataset_id']}/events"), ['json' => [
            'data' => [$ev], 'test_event_code' => $cfg['test_event_code'], 'access_token' => TokenCipher::decrypt($cfg['access_token_enc']),
        ]]);
        $body = json_decode($res['body'], true) ?: [];
        if ($res['status'] >= 400) {
            throw new \RuntimeException('Meta rejected the test event: ' . ($body['error']['message'] ?? 'HTTP ' . $res['status']));
        }
        return ['events_received' => (int) ($body['events_received'] ?? 0), 'fbtrace_id' => $body['fbtrace_id'] ?? null];
    }

    // ---- Google Ads ---------------------------------------------------------------------------

    public static function googleConfigured(): bool
    {
        return (string) env('GOOGLE_ADS_CLIENT_ID', '') !== '' && (string) env('GOOGLE_ADS_CLIENT_SECRET', '') !== '';
    }

    public static function googleRedirectUri(): string
    {
        return rtrim((string) base_url(), '/') . '/api/v1/google-ads/oauth/callback';
    }

    /** Mint a single-use state and return Google's consent URL. */
    public function googleStart(int $tenantId, int $userId): string
    {
        if (! self::googleConfigured()) {
            throw new \RuntimeException('Google Ads is not configured on this server (GOOGLE_ADS_CLIENT_ID / GOOGLE_ADS_CLIENT_SECRET).');
        }
        $state = bin2hex(random_bytes(24));
        (new SocialOauthStateModel())->setTenant($tenantId)->insert([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'state' => $state, 'status' => 'pending',
            'return_to' => 'google_ads', 'expires_at' => date('Y-m-d H:i:s', time() + self::STATE_TTL),
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => env('GOOGLE_ADS_CLIENT_ID'), 'redirect_uri' => self::googleRedirectUri(), 'response_type' => 'code',
            'scope' => self::GOOGLE_SCOPE, 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
        ]);
    }

    /** Exchange the code, keep ONLY the refresh token (encrypted). Called from the public callback. */
    public function googleCallback(string $code, string $state): void
    {
        $row = (new SocialOauthStateModel())->withoutTenantScope()->where('state', $state)->where('return_to', 'google_ads')->first();
        if (! $row || $row['status'] !== 'pending' || strtotime((string) $row['expires_at']) < time()) {
            throw new \RuntimeException('This connection attempt expired. Please start again.');
        }
        $tid = (int) $row['tenant_id'];
        // Single use: burn the state before doing anything else.
        (new SocialOauthStateModel())->withoutTenantScope()->update((int) $row['id'], ['status' => 'consumed', 'expires_at' => date('Y-m-d H:i:s')]);

        if (self::mock()) {
            $refresh = 'mock_refresh_token';
        } else {
            $res  = ($this->http)('POST', 'https://oauth2.googleapis.com/token', ['form_params' => [
                'code' => $code, 'client_id' => env('GOOGLE_ADS_CLIENT_ID'), 'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
                'redirect_uri' => self::googleRedirectUri(), 'grant_type' => 'authorization_code',
            ]]);
            $body = json_decode($res['body'], true) ?: [];
            $refresh = (string) ($body['refresh_token'] ?? '');
            if ($refresh === '') {
                // Google only returns a refresh token on first consent; prompt=consent normally forces it.
                throw new \RuntimeException('Google did not return a refresh token. Remove TravelPilot at myaccount.google.com/permissions and connect again.');
            }
        }
        $cfg = $this->load($tid, 'google_ads');
        $cfg['refresh_token_enc'] = TokenCipher::encrypt($refresh);
        $this->store($tid, 'google_ads', $cfg);
    }

    /** Ad accounts the connected Google user can access. @return list<array{id:string}> */
    public function googleCustomers(int $tenantId): array
    {
        if (self::mock()) {
            return [['id' => '1234567890'], ['id' => '9876543210']];
        }
        $res  = $this->googleCall($tenantId, 'GET', '/customers:listAccessibleCustomers', null, null);
        $list = json_decode($res['body'], true)['resourceNames'] ?? [];
        return array_map(static fn ($r) => ['id' => preg_replace('/\D/', '', (string) $r)], $list);
    }

    public function googleSelect(int $tenantId, string $customerId, string $loginCustomerId = ''): array
    {
        $cid = preg_replace('/\D/', '', $customerId);
        if ($cid === '') { // "Change account": forget the selection and its mapping, keep the Google connection
            $cfg = $this->load($tenantId, 'google_ads');
            unset($cfg['customer_id'], $cfg['login_customer_id'], $cfg['conversion_actions']);
            $this->store($tenantId, 'google_ads', $cfg);
            return $this->status($tenantId)['google'];
        }
        if (strlen($cid) !== 10) {
            throw new \InvalidArgumentException('Google Ads customer IDs have 10 digits.');
        }
        $cfg = $this->load($tenantId, 'google_ads');
        if (empty($cfg['refresh_token_enc'])) {
            throw new \InvalidArgumentException('Connect Google first.');
        }
        $cfg['customer_id'] = $cid;
        $cfg['login_customer_id'] = preg_replace('/\D/', '', $loginCustomerId);
        unset($cfg['conversion_actions']); // actions belong to the previous account
        $this->store($tenantId, 'google_ads', $cfg);
        return $this->status($tenantId)['google'];
    }

    /** Conversion actions that accept click uploads. @return list<array{resource_name:string,name:string,type:string}> */
    public function googleConversionActions(int $tenantId): array
    {
        $cfg = $this->load($tenantId, 'google_ads');
        if (empty($cfg['customer_id'])) {
            throw new \InvalidArgumentException('Choose a Google Ads account first.');
        }
        if (self::mock()) {
            return [['resource_name' => "customers/{$cfg['customer_id']}/conversionActions/111", 'name' => 'TravelPilot – Lead', 'type' => 'UPLOAD_CLICKS'],
                    ['resource_name' => "customers/{$cfg['customer_id']}/conversionActions/222", 'name' => 'TravelPilot – Purchase', 'type' => 'UPLOAD_CLICKS']];
        }
        $q = "SELECT conversion_action.resource_name, conversion_action.name, conversion_action.type FROM conversion_action WHERE conversion_action.status = 'ENABLED' AND conversion_action.type = 'UPLOAD_CLICKS'";
        $res  = $this->googleCall($tenantId, 'POST', "/customers/{$cfg['customer_id']}/googleAds:search", ['query' => $q], $cfg);
        $rows = json_decode($res['body'], true)['results'] ?? [];
        return array_map(static fn ($r) => ['resource_name' => $r['conversionAction']['resourceName'], 'name' => $r['conversionAction']['name'], 'type' => $r['conversionAction']['type']], $rows);
    }

    /** @param array<string,string> $map event name => conversionAction resource name */
    public function saveGoogleMapping(int $tenantId, array $map, $events = null): array
    {
        $cfg = $this->load($tenantId, 'google_ads');
        if (empty($cfg['customer_id'])) {
            throw new \InvalidArgumentException('Choose a Google Ads account first.');
        }
        $clean = [];
        foreach (ConversionFeedbackService::EVENTS as $name) {
            $v = trim((string) ($map[$name] ?? ''));
            if ($v === '') { continue; }
            if (! preg_match('#^customers/' . $cfg['customer_id'] . '/conversionActions/\d+$#', $v)) {
                throw new \InvalidArgumentException("Conversion action for {$name} does not belong to this Google Ads account.");
            }
            $clean[$name] = $v;
        }
        $cfg['conversion_actions'] = $clean;
        $cfg['events'] = $this->cleanEvents($events ?? ($cfg['events'] ?? null));
        $this->store($tenantId, 'google_ads', $cfg);
        return $this->status($tenantId)['google'];
    }

    // ---- disconnect + delivery log ------------------------------------------------------------

    public function disconnect(int $tenantId, string $platform): void
    {
        $type = $platform === 'meta' ? 'meta_capi' : 'google_ads';
        if ($type === 'google_ads' && ! self::mock()) {
            $cfg = $this->load($tenantId, 'google_ads');
            if (! empty($cfg['refresh_token_enc'])) { // best-effort revoke
                try { ($this->http)('POST', 'https://oauth2.googleapis.com/revoke', ['form_params' => ['token' => TokenCipher::decrypt($cfg['refresh_token_enc'])]]); } catch (\Throwable) {}
            }
        }
        db_connect()->table('integrations')->where('tenant_id', $tenantId)->where('type', $type)->delete();
    }

    /** Recent conversion events + status counts. */
    public function deliveryLog(int $tenantId, int $limit = 50): array
    {
        $rows = (new ConversionEventModel())->setTenant($tenantId)->orderBy('id', 'DESC')->findAll($limit);
        $counts = db_connect()->table('conversion_events')->select('status, COUNT(*) n', false)->where('tenant_id', $tenantId)->groupBy('status')->get()->getResultArray();
        return ['events' => array_map(static fn ($r) => [
            'id' => $r['id'], 'platform' => $r['platform'], 'event_name' => $r['event_name'], 'value_amount' => $r['value_amount'],
            'status' => $r['status'], 'attempts' => $r['attempts'], 'response' => $r['response'], 'event_time' => $r['event_time'], 'sent_at' => $r['sent_at'],
        ], $rows), 'counts' => array_column($counts, 'n', 'status')];
    }

    public function retry(int $tenantId, int $eventId): bool
    {
        $m = (new ConversionEventModel())->setTenant($tenantId);
        $row = $m->find($eventId);
        if (! $row || ! in_array($row['status'], ['failed', 'skipped'], true)) {
            return false;
        }
        (new ConversionEventModel())->setTenant($tenantId)->update($eventId, ['status' => 'pending', 'attempts' => 0, 'response' => null]);
        return true;
    }

    // ---- internals ----------------------------------------------------------------------------

    private function cleanEvents($events): array
    {
        $all = array_values(ConversionFeedbackService::EVENTS);
        return is_array($events) ? array_values(array_intersect($all, $events)) : $all;
    }

    private function graph(string $path): string
    {
        return 'https://graph.facebook.com/' . ((string) (env('META_GRAPH_VERSION') ?: 'v25.0')) . $path;
    }

    /** @return array{status:int,body:string} */
    private function googleCall(int $tenantId, string $method, string $path, ?array $json, ?array $cfg): array
    {
        $cfg ??= $this->load($tenantId, 'google_ads');
        if (empty($cfg['refresh_token_enc'])) {
            throw new \InvalidArgumentException('Connect Google first.');
        }
        $tok = ($this->http)('POST', 'https://oauth2.googleapis.com/token', ['form_params' => [
            'client_id' => env('GOOGLE_ADS_CLIENT_ID'), 'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
            'refresh_token' => TokenCipher::decrypt($cfg['refresh_token_enc']), 'grant_type' => 'refresh_token',
        ]]);
        $access = json_decode($tok['body'], true)['access_token'] ?? null;
        if (! $access) {
            throw new \RuntimeException('Google rejected the saved connection (it may have been revoked). Reconnect Google.');
        }
        $headers = ['Authorization' => "Bearer {$access}"];
        if ($dev = env('GOOGLE_ADS_DEVELOPER_TOKEN')) { $headers['developer-token'] = $dev; }
        if (! empty($cfg['login_customer_id'])) { $headers['login-customer-id'] = $cfg['login_customer_id']; }
        $res = ($this->http)($method, 'https://googleads.googleapis.com/' . ((string) (env('GOOGLE_ADS_API_VERSION') ?: 'v25')) . $path, ['headers' => $headers] + ($json !== null ? ['json' => $json] : []));
        if ($res['status'] >= 400) {
            $msg = json_decode($res['body'], true)['error']['message'] ?? 'HTTP ' . $res['status'];
            throw new \RuntimeException('Google Ads: ' . $msg);
        }
        return $res;
    }

    private function load(int $tenantId, string $type): array
    {
        $row = (new IntegrationModel())->findActiveByType($tenantId, $type);
        return $row ? (json_decode((string) (is_array($row) ? $row['config'] : $row->config), true) ?: []) : [];
    }

    private function store(int $tenantId, string $type, array $cfg): void
    {
        (new IntegrationModel())->saveConfig($tenantId, $type, ['config' => json_encode($cfg)]);
    }

    public static function spaReturnUrl(string $query): string
    {
        return EmailService::appUrl() . '/#/settings/ads?' . $query;
    }
}
