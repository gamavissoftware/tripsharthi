<?php

declare(strict_types=1);

namespace App\Services\Ads;

/**
 * Thin Meta Marketing API client. Knows HTTP + error classification, nothing about travel.
 * The version comes from META_GRAPH_VERSION (Meta sunsets versions roughly every 2 years — never hard-code it).
 */
final class MetaMarketingClient
{
    /** @var callable(string,string,array):array{status:int,body:string} */
    private $http;
    private string $version;

    public function __construct(private readonly string $token, ?callable $http = null, ?string $version = null)
    {
        $this->http    = $http ?? [\App\Services\Travel\ConversionFeedbackService::class, 'defaultHttp'];
        $this->version = $version ?? ((string) (env('META_GRAPH_VERSION') ?: 'v25.0'));
    }

    /** @return array<string,mixed> decoded JSON */
    public function request(string $method, string $path, array $params = []): array
    {
        $url = 'https://graph.facebook.com/' . $this->version . '/' . ltrim($path, '/');
        $opt = [];
        if ($method === 'GET') {
            $url .= '?' . http_build_query($this->flatten($params + ['access_token' => $this->token]));
        } else {
            $opt['json'] = $params + ['access_token' => $this->token];
        }
        try {
            $res = ($this->http)($method, $url, $opt);
        } catch (\Throwable $e) {
            throw new AdsApiException('Could not reach Meta: ' . $e->getMessage(), AdsApiException::TRANSIENT);
        }
        $body = json_decode($res['body'], true);
        if ($res['status'] >= 500) {
            throw new AdsApiException('Meta is having trouble right now. Try again shortly.', AdsApiException::TRANSIENT);
        }
        if ($res['status'] >= 400 || isset($body['error'])) {
            throw self::classify(is_array($body) ? $body : []);
        }
        return is_array($body) ? $body : [];
    }

    /** Graph wants nested params (arrays) JSON-encoded on GET. */
    private function flatten(array $p): array
    {
        return array_map(static fn ($v) => is_array($v) ? json_encode($v) : $v, $p);
    }

    public static function classify(array $body): AdsApiException
    {
        $e    = $body['error'] ?? [];
        $code = (int) ($e['code'] ?? 0);
        $msg  = (string) ($e['error_user_msg'] ?? $e['message'] ?? 'Meta rejected the request.');
        $sub  = isset($e['error_subcode']) ? (string) $e['error_subcode'] : null;
        $kind = match (true) {
            $code === 190                                  => AdsApiException::AUTH,
            in_array($code, [4, 17, 32, 613], true) || ($code >= 80000 && $code <= 80014) => AdsApiException::RATE_LIMIT,
            in_array($code, [10, 3], true) || ($code >= 200 && $code <= 299) => AdsApiException::PERMISSION,
            in_array($code, [1, 2], true)                  => AdsApiException::TRANSIENT,
            default                                        => AdsApiException::INVALID,
        };
        $prefix = match ($kind) {
            AdsApiException::AUTH       => 'Your Meta connection has expired or was revoked — reconnect Meta. ',
            AdsApiException::PERMISSION => 'Meta has not granted TravelPilot permission for this (ads_management / Page access). ',
            AdsApiException::RATE_LIMIT => 'Meta is rate-limiting requests; try again in a few minutes. ',
            default                     => '',
        };
        return new AdsApiException($prefix . $msg, $kind, $sub ? "{$code}/{$sub}" : (string) $code, $body);
    }

    // ---- reads ------------------------------------------------------------------------------

    /** @return list<string> granted permissions */
    public function permissions(): array
    {
        $r = $this->request('GET', 'me/permissions');
        return array_values(array_map(static fn ($p) => (string) $p['permission'], array_filter($r['data'] ?? [], static fn ($p) => ($p['status'] ?? '') === 'granted')));
    }

    public function pages(): array
    {
        return $this->request('GET', 'me/accounts', ['fields' => 'id,name,tasks', 'limit' => 100])['data'] ?? [];
    }

    public function leadForms(string $pageId): array
    {
        // Page access token is needed to read forms; the user token can read them for pages the user manages.
        return $this->request('GET', "{$pageId}/leadgen_forms", ['fields' => 'id,name,status', 'limit' => 100])['data'] ?? [];
    }

    public function searchGeo(string $query, string $type = 'city'): array
    {
        return $this->request('GET', 'search', ['type' => 'adgeolocation', 'q' => $query, 'location_types' => [$type], 'country_code' => 'IN', 'limit' => 15])['data'] ?? [];
    }

    public function campaigns(string $account): array
    {
        return $this->paged("{$account}/campaigns", ['fields' => 'id,name,objective,status,effective_status,daily_budget,start_time,stop_time', 'limit' => 200]);
    }

    public function adSets(string $account): array
    {
        return $this->paged("{$account}/adsets", ['fields' => 'id,campaign_id,name,status,effective_status,daily_budget', 'limit' => 200]);
    }

    /** Ads that are disapproved or have issues, with Meta's review feedback. */
    public function problemAds(string $account): array
    {
        return $this->paged("{$account}/ads", ['fields' => 'id,name,campaign_id,effective_status,ad_review_feedback', 'limit' => 200,
            'filtering' => [['field' => 'effective_status', 'operator' => 'IN', 'value' => ['DISAPPROVED', 'WITH_ISSUES']]]]);
    }

    /** Daily campaign-level insights. */
    public function insights(string $account, string $since, string $until): array
    {
        return $this->paged("{$account}/insights", ['level' => 'campaign', 'time_increment' => 1,
            'fields' => 'campaign_id,spend,impressions,clicks,actions', 'time_range' => ['since' => $since, 'until' => $until], 'limit' => 500]);
    }

    private function paged(string $path, array $params): array
    {
        $out = [];
        for ($i = 0; $i < 20; $i++) {                       // hard stop: never loop forever on a bad cursor
            $r = $this->request('GET', $path, $params);
            $out = array_merge($out, $r['data'] ?? []);
            $after = $r['paging']['cursors']['after'] ?? null;
            if (! $after || empty($r['paging']['next'])) { break; }
            $params['after'] = $after;
        }
        return $out;
    }

    // ---- writes -----------------------------------------------------------------------------------

    /** @return string new object id */
    public function create(string $account, string $edge, array $params, bool $validateOnly = false): string
    {
        if ($validateOnly) {
            $params['execution_options'] = ['validate_only'];
        }
        $r = $this->request('POST', "{$account}/{$edge}", $params);
        return (string) ($r['id'] ?? ($validateOnly ? 'validated' : ''));
    }

    // ---- audiences ----------------------------------------------------------------------------------

    public function createAudience(string $account, array $params): string
    {
        return (string) ($this->request('POST', "{$account}/customaudiences", $params)['id'] ?? '');
    }

    /** @param list<string> $schema e.g. ['PHONE_SHA256','EMAIL_SHA256'];  @param list<list<string>> $rows hashed values in schema order ('' = missing) */
    public function addAudienceUsers(string $audienceId, array $schema, array $rows): array
    {
        return $this->request('POST', "{$audienceId}/users", ['payload' => ['schema' => $schema, 'data' => $rows]]);
    }

    public function removeAudienceUsers(string $audienceId, array $schema, array $rows): array
    {
        return $this->request('DELETE', "{$audienceId}/users", ['payload' => ['schema' => $schema, 'data' => $rows]]);
    }

    public function audience(string $audienceId): array
    {
        return $this->request('GET', $audienceId, ['fields' => 'id,name,subtype,approximate_count_lower_bound,approximate_count_upper_bound,operation_status,delivery_status']);
    }

    public function update(string $id, array $params): bool
    {
        return (bool) ($this->request('POST', $id, $params)['success'] ?? false);
    }

    public function delete(string $id): void
    {
        $this->request('DELETE', $id);
    }
}
