<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Models\IntegrationModel;
use App\Services\WhatsApp\TokenCipher;

/** Google Ads REST client for one tenant's connected account. Version from GOOGLE_ADS_API_VERSION (pinned, upgraded twice a year). */
final class GoogleAdsClient
{
    /** @var callable(string,string,array):array{status:int,body:string} */
    private $http;
    private ?string $access = null;

    public function __construct(private readonly array $cfg, ?callable $http = null)
    {
        $this->http = $http ?? [\App\Services\Travel\ConversionFeedbackService::class, 'defaultHttp'];
    }

    /** @param array $cfg decrypted: refresh_token, customer_id, login_customer_id */
    public static function forTenant(int $tenantId, ?callable $http = null): ?self
    {
        $row = (new IntegrationModel())->findActiveByType($tenantId, 'google_ads');
        $cfg = $row ? (json_decode((string) ((array) $row)['config'], true) ?: []) : [];
        if (AdsMockHttp::enabled() && (empty($cfg['refresh_token_enc']) || empty($cfg['customer_id']))) {   // local demo
            return new self(['refresh_token' => 'mock', 'customer_id' => '1234567890', 'login_customer_id' => ''], $http ?? AdsMockHttp::handler($tenantId));
        }
        if (empty($cfg['refresh_token_enc']) || empty($cfg['customer_id'])) { return null; }
        return new self(['refresh_token' => TokenCipher::decrypt($cfg['refresh_token_enc']), 'customer_id' => $cfg['customer_id'],
            'login_customer_id' => $cfg['login_customer_id'] ?? ''], $http);
    }

    public function customerId(): string { return (string) $this->cfg['customer_id']; }

    private function token(): string
    {
        if ($this->access) { return $this->access; }
        $res = ($this->http)('POST', 'https://oauth2.googleapis.com/token', ['form_params' => [
            'client_id' => env('GOOGLE_ADS_CLIENT_ID'), 'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
            'refresh_token' => $this->cfg['refresh_token'], 'grant_type' => 'refresh_token']]);
        $t = json_decode($res['body'], true)['access_token'] ?? null;
        if (! $t) { throw new AdsApiException('Your Google connection has expired or was revoked — reconnect Google.', AdsApiException::AUTH); }
        return $this->access = $t;
    }

    /** @return array<string,mixed> */
    public function request(string $path, array $json): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token()];
        if ($dev = env('GOOGLE_ADS_DEVELOPER_TOKEN')) { $headers['developer-token'] = $dev; }
        if (! empty($this->cfg['login_customer_id'])) { $headers['login-customer-id'] = $this->cfg['login_customer_id']; }
        $url = 'https://googleads.googleapis.com/' . ((string) (env('GOOGLE_ADS_API_VERSION') ?: 'v25')) . '/' . ltrim($path, '/');
        try {
            $res = ($this->http)('POST', $url, ['headers' => $headers, 'json' => $json]);
        } catch (\Throwable $e) {
            throw new AdsApiException('Could not reach Google Ads: ' . $e->getMessage(), AdsApiException::TRANSIENT);
        }
        $body = json_decode($res['body'], true);
        if ($res['status'] >= 400) { throw self::classify($res['status'], is_array($body) ? $body : []); }
        return is_array($body) ? $body : [];
    }

    public static function classify(int $http, array $body): AdsApiException
    {
        $e = $body['error'] ?? [];
        $status = (string) ($e['status'] ?? '');
        $detail = '';
        foreach ((array) ($e['details'] ?? []) as $d) {
            foreach ((array) ($d['errors'] ?? []) as $err) { $detail = (string) ($err['message'] ?? ''); if ($detail !== '') { break 2; } }
        }
        $msg = $detail ?: (string) ($e['message'] ?? 'Google Ads rejected the request.');
        [$kind, $prefix] = match (true) {
            $http === 401 || $status === 'UNAUTHENTICATED' => [AdsApiException::AUTH, 'Your Google connection has expired or was revoked — reconnect Google. '],
            $http === 403 || $status === 'PERMISSION_DENIED' => [AdsApiException::PERMISSION, 'Google has not granted this access (check the API access level, developer token and account permissions). '],
            $http === 429 || $status === 'RESOURCE_EXHAUSTED' => [AdsApiException::RATE_LIMIT, 'Google Ads is rate-limiting requests; try again later. '],
            $http >= 500 => [AdsApiException::TRANSIENT, 'Google Ads is having trouble right now. '],
            default => [AdsApiException::INVALID, ''],
        };
        return new AdsApiException($prefix . $msg, $kind, $status ?: (string) $http, $body);
    }

    /** GAQL, following pages (hard-capped). @return list<array> */
    public function search(string $gaql): array
    {
        $out = []; $page = null;
        for ($i = 0; $i < 20; $i++) {
            $r = $this->request("customers/{$this->customerId()}/googleAds:search", array_filter(['query' => $gaql, 'pageSize' => 1000, 'pageToken' => $page]));
            $out = array_merge($out, $r['results'] ?? []);
            $page = $r['nextPageToken'] ?? null;
            if (! $page) { break; }
        }
        return $out;
    }

    /** Atomic multi-resource mutate. $validateOnly = dry run: nothing is created. */
    public function mutate(array $operations, bool $validateOnly = false): array
    {
        return $this->request("customers/{$this->customerId()}/googleAds:mutate", ['mutateOperations' => $operations, 'partialFailure' => false, 'validateOnly' => $validateOnly]);
    }

    public function mutateResource(string $collection, array $operations): array
    {
        return $this->request("customers/{$this->customerId()}/{$collection}:mutate", ['operations' => $operations]);
    }

    /** City/state suggestions for targeting. */
    public function suggestGeo(string $query): array
    {
        $r = $this->request('geoTargetConstants:suggest', ['locale' => 'en', 'countryCode' => 'IN', 'locationNames' => ['names' => [$query]]]);
        return array_map(static fn ($s) => ['id' => preg_replace('/\D/', '', (string) $s['geoTargetConstant']['resourceName']), 'name' => $s['geoTargetConstant']['canonicalName'] ?? $s['geoTargetConstant']['name'], 'type' => $s['geoTargetConstant']['targetType'] ?? ''], $r['geoTargetConstantSuggestions'] ?? []);
    }
}
