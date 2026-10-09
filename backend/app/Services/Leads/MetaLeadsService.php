<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * Fetches full lead data from the Meta Graph API.
 *
 * The leadgen webhook only delivers a leadgen_id — this service does the
 * follow-up GET to retrieve the actual form field values.
 *
 * META_LEADS_MOCK_MODE=true (env flag, default false) bypasses the real
 * API call and returns configurable test data so the full pipeline is
 * exercisable locally without an approved Meta App.
 * Logs at alert level when mock mode is active.
 */
class MetaLeadsService
{
    private bool   $mockMode;
    private array  $mockData;

    public function __construct(array $mockDataOverride = [])
    {
        $this->mockMode = filter_var(env('META_LEADS_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
        $this->mockData = $mockDataOverride;

        if ($this->mockMode) {
            log_message('alert',
                'MetaLeadsService: META_LEADS_MOCK_MODE enabled — '
                . 'Graph API lead fetches are mocked. DO NOT use in production.'
            );
        }
    }

    /**
     * Fetch the full lead from the Graph API.
     *
     * @param  string $leadgenId       Meta lead ID from the webhook.
     * @param  string $pageAccessToken Decrypted Page Access Token for the tenant's Page.
     * @return array  Lead data with 'field_data', 'form_id', etc.
     * @throws \RuntimeException On network or API errors (triggers job retry).
     */
    public function fetchLead(string $leadgenId, string $pageAccessToken): array
    {
        if ($this->mockMode) {
            return $this->mockData ?: $this->defaultMockLead($leadgenId);
        }

        // Only fields the Lead node actually exposes. `page_id` is NOT one of
        // them — asking for it fails the whole fetch with
        // "(#100) Tried accessing nonexisting field (page_id)". The page is
        // already known from the webhook that delivered the leadgen_id.
        $version = env('META_GRAPH_VERSION', 'v22.0');
        $url     = "https://graph.facebook.com/{$version}/{$leadgenId}"
                 . '?fields=field_data,created_time,form_id,ad_id,is_organic'
                 . '&access_token=' . urlencode($pageAccessToken);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => 10000,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("MetaLeadsService cURL error: {$err}");
        }

        $data = json_decode($resp, true) ?? [];

        if ($code !== 200 || isset($data['error'])) {
            $errMsg = $data['error']['message'] ?? "HTTP {$code}";
            throw new \RuntimeException("Graph API error fetching lead {$leadgenId}: {$errMsg}");
        }

        return $data;
    }

    // ------------------------------------------------------------------

    private function defaultMockLead(string $leadgenId): array
    {
        return [
            'id'          => $leadgenId,
            'field_data'  => [
                ['name' => 'full_name',    'values' => [env('META_MOCK_LEAD_NAME',  'Mock Lead')]],
                ['name' => 'phone_number', 'values' => [env('META_MOCK_LEAD_PHONE', '+919999900001')]],
                ['name' => 'email',        'values' => ['mock@example.com']],
            ],
            'form_id'     => 'mock_form_id',
            'page_id'     => 'mock_page_id',
            'created_time'=> date('c'),
        ];
    }
}
