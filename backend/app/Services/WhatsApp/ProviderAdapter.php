<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Multi-provider WhatsApp adapter for TravelPilot.
 *
 * Ported and improved from EaseMySale's WhatsAppProviderLibrary.
 * Supports: meta | wati | aisensy | 360dialog | twilio | custom
 *
 * Usage:
 *   $adapter = ProviderAdapter::fromAccount($accountRow, $decryptedToken);
 *   $result  = $adapter->sendText('+919999900000', 'Hello!');
 *
 * Return shape (all methods):
 *   ['success' => bool, 'message_id' => string|null, 'error' => string|null]
 *
 * TravelPilot improvements over EaseMySale:
 *   - PSR-12, strict types
 *   - WHATSAPP_MOCK_MODE support across all providers
 *   - SSL verification enforced by default (configurable)
 *   - Delegates Meta calls to existing CloudApiClient to avoid duplication
 *   - Normalises return shape consistently
 */
class ProviderAdapter
{
    private bool  $mockMode;
    private array $config;

    private function __construct(
        private readonly string $provider,
        array                   $config,
        private readonly ?CloudApiClient $metaClient = null,
    ) {
        $this->config   = $config;
        $this->mockMode = filter_var(env('WHATSAPP_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }

    // ── Factory ────────────────────────────────────────────────────────────────

    /**
     * Build an adapter from a waba_accounts row.
     * $rawToken is only needed for Meta accounts (decrypted by caller).
     */
    public static function fromAccount(array $account, ?string $rawToken = null): self
    {
        $provider = $account['provider'] ?? 'meta';
        $config   = is_string($account['provider_config_json'] ?? null)
            ? (json_decode($account['provider_config_json'], true) ?: [])
            : ($account['provider_config_json'] ?? []);

        // For Meta, augment config with the explicit fields from the account row
        if ($provider === 'meta') {
            $config['phone_number_id'] = $config['phone_number_id'] ?? ($account['phone_number_id'] ?? '');
            $config['access_token']    = $rawToken ?? ($config['access_token'] ?? '');
            $config['waba_id']         = $config['waba_id'] ?? ($account['waba_id'] ?? '');
        }

        $metaClient = null;
        if ($provider === 'meta') {
            $phoneId = $config['phone_number_id'] ?? '';
            $token   = $config['access_token']    ?? '';
            if ($phoneId && $token) {
                $metaClient = new CloudApiClient($phoneId, $token);
            }
        }

        return new self($provider, $config, $metaClient);
    }

    // ── Public API (same shape as CloudApiClient) ──────────────────────────────

    public function sendText(string $to, string $body): array
    {
        if ($this->mockMode) {
            return $this->mockSuccess("sendText to={$to}");
        }

        return match ($this->provider) {
            'meta'      => $this->metaClient
                ? $this->metaClient->sendText($to, $body)
                : $this->err('Meta CloudApiClient not initialised — check phone_number_id and access_token.'),
            'wati'      => $this->watiSendText($to, $body),
            'aisensy'   => $this->aisensySendText($to, $body),
            '360dialog' => $this->dialog360SendText($to, $body),
            'twilio'    => $this->twilioSendText($to, $body),
            'custom'    => $this->customSendText($to, $body),
            default     => $this->err("Unknown provider: {$this->provider}"),
        };
    }

    public function sendTemplate(string $to, string $name, string $language, array $components = []): array
    {
        if ($this->mockMode) {
            return $this->mockSuccess("sendTemplate to={$to} template={$name}");
        }

        return match ($this->provider) {
            'meta'      => $this->metaClient
                ? $this->metaClient->sendTemplate($to, $name, $language, $components)
                : $this->err('Meta CloudApiClient not initialised.'),
            'wati'      => $this->watiSendTemplate($to, $name, $components),
            'aisensy'   => $this->aisensySendTemplate($to, $name, $components),
            '360dialog' => $this->dialog360SendTemplate($to, $name, $language, $components),
            'twilio'    => $this->sendText($to, "[Template: {$name}]"),
            'custom'    => $this->customSendText($to, "[Template: {$name}]"),
            default     => $this->err("Unknown provider: {$this->provider}"),
        };
    }

    public function sendMedia(string $to, string $type, string $mediaId, string $caption = '', string $filename = ''): array
    {
        if ($this->mockMode) {
            return $this->mockSuccess("sendMedia to={$to} type={$type}");
        }

        if ($this->provider === 'meta') {
            return $this->metaClient
                ? $this->metaClient->sendMedia($to, $type, $mediaId, $caption, $filename)
                : $this->err('Meta CloudApiClient not initialised.');
        }

        return $this->err("Media sending is only supported for Meta provider (current: {$this->provider}).");
    }

    /**
     * Send an interactive message (buttons / CTA URL / list) — Meta Cloud API only.
     *
     * $payload shape examples:
     *
     * BUTTON (up to 3 quick-reply buttons):
     *   {
     *     type: 'button',
     *     body: 'Choose an option:',
     *     header: 'Optional header',  // optional
     *     footer: 'Optional footer',  // optional
     *     buttons: [
     *       {id: 'btn_yes', title: 'Yes ✅'},
     *       {id: 'btn_no',  title: 'No ❌'},
     *     ]
     *   }
     *
     * CTA URL (link button):
     *   {
     *     type: 'cta_url',
     *     body: 'Visit our website',
     *     header: 'Optional header',  // optional
     *     footer: 'Optional footer',  // optional
     *     cta_text: 'Open Website',
     *     cta_url: 'https://example.com',
     *   }
     *
     * LIST (menu up to 10 options):
     *   {
     *     type: 'list',
     *     body: 'Select from menu:',
     *     header: 'Optional header',
     *     footer: 'Optional footer',
     *     button_text: 'View Options',
     *     sections: [
     *       {
     *         title: 'Section 1',
     *         rows: [
     *           {id: 'opt_1', title: 'Option 1', description: 'Desc'},
     *         ]
     *       }
     *     ]
     *   }
     */
    public function sendInteractive(string $to, array $payload): array
    {
        if ($this->mockMode) {
            return $this->mockSuccess("sendInteractive to={$to} type=" . ($payload['type'] ?? 'button'));
        }

        if ($this->provider !== 'meta') {
            return $this->err("Interactive messages are only supported for Meta provider (current: {$this->provider}).");
        }

        if (! $this->metaClient) {
            return $this->err('Meta CloudApiClient not initialised.');
        }

        return $this->metaClient->sendInteractive($to, $payload);
    }

    /**
     * Send a single catalog product message — Meta Cloud API only.
     */
    public function sendProduct(string $to, string $catalogId, string $retailerId, string $body = '', string $footer = ''): array
    {
        if ($this->mockMode) {
            return $this->mockSuccess("sendProduct to={$to} sku={$retailerId}");
        }
        if ($this->provider !== 'meta' || ! $this->metaClient) {
            return $this->err("Product messages require the Meta provider (current: {$this->provider}).");
        }
        return $this->metaClient->sendProduct($to, $catalogId, $retailerId, $body, $footer);
    }

    /**
     * Send a multi-product (catalog list) message — Meta Cloud API only.
     */
    public function sendProductList(string $to, string $catalogId, string $headerText, string $body, array $sections): array
    {
        if ($this->mockMode) {
            return $this->mockSuccess("sendProductList to={$to} catalog={$catalogId}");
        }
        if ($this->provider !== 'meta' || ! $this->metaClient) {
            return $this->err("Product messages require the Meta provider (current: {$this->provider}).");
        }
        return $this->metaClient->sendProductList($to, $catalogId, $headerText, $body, $sections);
    }

    /**
     * Send a published WhatsApp Flow (native form) — Meta Cloud API only.
     */
    public function sendFlow(string $to, string $flowId, string $flowToken, string $ctaText, string $body, string $screen = '', array $data = []): array
    {
        if ($this->mockMode) {
            return $this->mockSuccess("sendFlow to={$to} flow={$flowId}");
        }
        if ($this->provider !== 'meta' || ! $this->metaClient) {
            return $this->err("WhatsApp Flows require the Meta provider (current: {$this->provider}).");
        }
        return $this->metaClient->sendFlow($to, $flowId, $flowToken, $ctaText, $body, $screen, $data);
    }

    public function uploadMedia(string $filePath, string $mimeType): array
    {
        if ($this->mockMode) {
            return ['success' => true, 'media_id' => 'mock_media_' . substr(md5($filePath), 0, 12), 'error' => null];
        }

        if ($this->provider === 'meta') {
            return $this->metaClient
                ? $this->metaClient->uploadMedia($filePath, $mimeType)
                : $this->err('Meta CloudApiClient not initialised.');
        }

        return $this->err("Media upload only supported for Meta provider (current: {$this->provider}).");
    }

    public function testConnection(): array
    {
        if ($this->mockMode) {
            return ['success' => true, 'info' => [
                'display_phone_number' => '+91 99999 00000',
                'verified_name'        => 'Mock Business (' . strtoupper($this->provider) . ')',
                'quality_rating'       => 'GREEN',
            ]];
        }

        return match ($this->provider) {
            'meta'      => $this->metaClient
                ? $this->metaClient->testConnection()
                : $this->err('Meta CloudApiClient not initialised.'),
            'wati'      => $this->watiTest(),
            'aisensy'   => $this->aisensyTest(),
            '360dialog' => $this->dialog360Test(),
            'twilio'    => $this->twilioTest(),
            'custom'    => $this->customTest(),
            default     => $this->err("Unknown provider: {$this->provider}"),
        };
    }

    /** Returns the fields required for each provider (used by the frontend form builder). */
    public static function providerFields(): array
    {
        return [
            'meta' => [
                ['key' => 'waba_id',         'label' => 'WABA ID',          'type' => 'text',     'required' => true,  'hint' => 'From Meta Business Manager'],
                ['key' => 'phone_number_id', 'label' => 'Phone Number ID',  'type' => 'text',     'required' => true,  'hint' => 'From WhatsApp > Phone Numbers in Meta'],
                ['key' => 'access_token',    'label' => 'Access Token',     'type' => 'password', 'required' => true,  'hint' => 'Permanent or long-lived system user token'],
                ['key' => 'display_name',    'label' => 'Display Name',     'type' => 'text',     'required' => true,  'hint' => 'e.g. Acme Corp'],
                ['key' => 'business_id',     'label' => 'Business ID',      'type' => 'text',     'required' => false, 'hint' => 'Meta Business Manager ID (optional)'],
            ],
            'wati' => [
                ['key' => 'api_endpoint',    'label' => 'API Endpoint URL', 'type' => 'text',     'required' => true,  'hint' => 'e.g. https://live-server.wati.io'],
                ['key' => 'access_token',    'label' => 'Access Token',     'type' => 'password', 'required' => true,  'hint' => 'From WATI Dashboard > Developer API'],
            ],
            'aisensy' => [
                ['key' => 'api_key',         'label' => 'API Key',          'type' => 'password', 'required' => true,  'hint' => 'From AiSensy Dashboard > Developer'],
                ['key' => 'project_id',      'label' => 'Project ID',       'type' => 'text',     'required' => true,  'hint' => 'From AiSensy Dashboard > Settings'],
            ],
            '360dialog' => [
                ['key' => 'api_key',         'label' => '360dialog API Key','type' => 'password', 'required' => true,  'hint' => 'From 360dialog Hub > API Keys'],
            ],
            'twilio' => [
                ['key' => 'account_sid',     'label' => 'Account SID',      'type' => 'text',     'required' => true,  'hint' => 'From Twilio Console'],
                ['key' => 'auth_token',      'label' => 'Auth Token',       'type' => 'password', 'required' => true,  'hint' => 'From Twilio Console'],
                ['key' => 'from_number',     'label' => 'WhatsApp From',    'type' => 'text',     'required' => true,  'hint' => 'e.g. +14155238886 (your Twilio WhatsApp number)'],
            ],
            'custom' => [
                ['key' => 'base_url',        'label' => 'Base API URL',     'type' => 'text',     'required' => true,  'hint' => 'e.g. https://api.myprovider.com'],
                ['key' => 'send_path',       'label' => 'Send Message Path','type' => 'text',     'required' => true,  'hint' => 'e.g. /v1/messages/send'],
                ['key' => 'auth_type',       'label' => 'Auth Type',        'type' => 'select',   'required' => true,  'options' => ['bearer', 'api_key', 'basic'], 'hint' => ''],
                ['key' => 'auth_value',      'label' => 'Auth Value',       'type' => 'password', 'required' => true,  'hint' => 'Token / API key / base64 credentials'],
            ],
        ];
    }

    // ── WATI ───────────────────────────────────────────────────────────────────

    private function watiSendText(string $to, string $message): array
    {
        $endpoint = rtrim($this->config['api_endpoint'] ?? '', '/');
        $token    = $this->config['access_token'] ?? '';
        $phone    = ltrim($to, '+');

        return $this->httpPost(
            "{$endpoint}/api/v1/sendSessionMessage/{$phone}",
            ['messageText' => $message],
            ["Authorization: Bearer {$token}"]
        );
    }

    private function watiSendTemplate(string $to, string $name, array $components): array
    {
        $endpoint = rtrim($this->config['api_endpoint'] ?? '', '/');
        $token    = $this->config['access_token'] ?? '';
        $phone    = ltrim($to, '+');

        $params = [];
        foreach ($components as $comp) {
            if (($comp['type'] ?? '') === 'body' && isset($comp['parameters'])) {
                foreach ($comp['parameters'] as $p) {
                    $params[] = ['name' => (string)(count($params) + 1), 'value' => $p['text'] ?? ''];
                }
            }
        }

        return $this->httpPost(
            "{$endpoint}/api/v1/sendTemplateMessage?whatsappNumber={$phone}",
            ['template_name' => $name, 'broadcast_name' => $name, 'parameters' => $params],
            ["Authorization: Bearer {$token}"]
        );
    }

    private function watiTest(): array
    {
        $endpoint = rtrim($this->config['api_endpoint'] ?? '', '/');
        $token    = $this->config['access_token'] ?? '';
        if (! $endpoint || ! $token) {
            return $this->err('WATI API Endpoint and Access Token are required.');
        }
        $res = $this->httpGet("{$endpoint}/api/v1/getContacts?pageSize=1", ["Authorization: Bearer {$token}"]);
        if ($res['success']) {
            return ['success' => true, 'info' => ['message' => 'WATI connection verified.']];
        }
        return $res;
    }

    // ── AiSensy ────────────────────────────────────────────────────────────────

    private function aisensySendText(string $to, string $message): array
    {
        return $this->httpPost('https://backend.aisensy.com/campaign/t1/api/v2', [
            'apiKey'          => $this->config['api_key']    ?? '',
            'campaignName'    => 'Direct Message',
            'destination'     => $to,
            'userName'        => 'TravelPilot',
            'templateParams'  => [$message],
            'source'          => 'CRM',
            'media'           => [],
        ]);
    }

    private function aisensySendTemplate(string $to, string $name, array $components): array
    {
        $params = [];
        foreach ($components as $comp) {
            if (($comp['type'] ?? '') === 'body' && isset($comp['parameters'])) {
                foreach ($comp['parameters'] as $p) {
                    $params[] = $p['text'] ?? '';
                }
            }
        }

        return $this->httpPost('https://backend.aisensy.com/campaign/t1/api/v2', [
            'apiKey'         => $this->config['api_key']    ?? '',
            'campaignName'   => $name,
            'destination'    => $to,
            'userName'       => 'TravelPilot',
            'templateParams' => $params,
            'source'         => 'CRM',
            'media'          => [],
        ]);
    }

    private function aisensyTest(): array
    {
        if (empty($this->config['api_key'])) return $this->err('AiSensy API Key is required.');
        if (empty($this->config['project_id'])) return $this->err('AiSensy Project ID is required.');
        return ['success' => true, 'info' => ['message' => 'AiSensy credentials saved. Send a test message to verify.']];
    }

    // ── 360dialog ──────────────────────────────────────────────────────────────

    private function dialog360SendText(string $to, string $message): array
    {
        return $this->httpPost(
            'https://waba-v2.360dialog.io/v1/messages',
            ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $message]],
            ['D360-API-KEY: ' . ($this->config['api_key'] ?? '')]
        );
    }

    private function dialog360SendTemplate(string $to, string $name, string $language, array $components): array
    {
        return $this->httpPost(
            'https://waba-v2.360dialog.io/v1/messages',
            ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template',
             'template' => ['name' => $name, 'language' => ['code' => $language], 'components' => $components]],
            ['D360-API-KEY: ' . ($this->config['api_key'] ?? '')]
        );
    }

    private function dialog360Test(): array
    {
        $apiKey = $this->config['api_key'] ?? '';
        if (! $apiKey) return $this->err('360dialog API Key is required.');
        $res = $this->httpGet('https://waba-v2.360dialog.io/v1/configs/templates', ["D360-API-KEY: {$apiKey}"]);
        return $res['success']
            ? ['success' => true, 'info' => ['message' => '360dialog API verified.']]
            : $res;
    }

    // ── Twilio ─────────────────────────────────────────────────────────────────

    private function twilioSendText(string $to, string $message): array
    {
        $sid   = $this->config['account_sid'] ?? '';
        $token = $this->config['auth_token']  ?? '';
        $from  = $this->config['from_number'] ?? '';

        if (! str_starts_with($from, 'whatsapp:')) $from = 'whatsapp:' . $from;
        $toDest = str_starts_with($to, 'whatsapp:') ? $to : 'whatsapp:' . $to;

        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "{$sid}:{$token}",
            CURLOPT_POSTFIELDS     => http_build_query(['From' => $from, 'To' => $toDest, 'Body' => $message]),
            CURLOPT_TIMEOUT_MS     => 10000,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode($resp, true) ?? [];
        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'message_id' => $body['sid'] ?? null, 'error' => null];
        }
        return $this->err($body['message'] ?? "Twilio HTTP {$code}");
    }

    private function twilioTest(): array
    {
        $sid   = $this->config['account_sid'] ?? '';
        $token = $this->config['auth_token']  ?? '';
        if (! $sid || ! $token) return $this->err('Twilio Account SID and Auth Token are required.');
        if (empty($this->config['from_number'])) return $this->err('Twilio From Number is required.');

        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => "{$sid}:{$token}", CURLOPT_TIMEOUT_MS => 8000, CURLOPT_SSL_VERIFYPEER => true]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode($resp, true) ?? [];
        if ($code === 200) {
            return ['success' => true, 'info' => ['account_name' => $body['friendly_name'] ?? '', 'status' => $body['status'] ?? '']];
        }
        return $this->err($body['message'] ?? "Twilio HTTP {$code}");
    }

    // ── Custom / OpenAPI ───────────────────────────────────────────────────────

    private function customSendText(string $to, string $message): array
    {
        $baseUrl   = rtrim($this->config['base_url']  ?? '', '/');
        $sendPath  = $this->config['send_path'] ?? '/messages/send';
        $authType  = $this->config['auth_type'] ?? 'bearer';
        $authValue = $this->config['auth_value'] ?? '';

        $headers = ['Content-Type: application/json'];
        match ($authType) {
            'bearer'  => $headers[] = "Authorization: Bearer {$authValue}",
            'api_key' => $headers[] = "X-API-Key: {$authValue}",
            'basic'   => $headers[] = 'Authorization: Basic ' . base64_encode($authValue),
            default   => null,
        };

        return $this->httpPost("{$baseUrl}{$sendPath}", [
            'to'      => $to,
            'message' => $message,
            'body'    => $message,
            'type'    => 'text',
        ], $headers);
    }

    private function customTest(): array
    {
        if (empty($this->config['base_url'])) return $this->err('Base API URL is required.');
        if (empty($this->config['send_path'])) return $this->err('Send Message Path is required.');
        return ['success' => true, 'info' => ['message' => 'Custom API config saved. Send a test message to verify.']];
    }

    // ── HTTP helpers ───────────────────────────────────────────────────────────

    private function httpPost(string $url, array $payload, array $extraHeaders = []): array
    {
        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => json_encode($payload),
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_TIMEOUT_MS        => 10000,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_SSL_VERIFYPEER    => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return $this->err($err);
        }

        $body  = json_decode($resp, true) ?? [];
        $msgId = $body['messages'][0]['id'] ?? $body['messageId'] ?? $body['message_id'] ?? $body['sid'] ?? null;

        if ($code < 200 || $code >= 300) {
            return $this->err($body['error']['message'] ?? $body['message'] ?? "HTTP {$code}");
        }

        return ['success' => true, 'message_id' => $msgId, 'error' => null];
    }

    private function httpGet(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT_MS     => 8000,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode($resp, true) ?? [];
        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'data' => $body, 'error' => null];
        }
        return $this->err($body['error']['message'] ?? "HTTP {$code}");
    }

    private function mockSuccess(string $detail): array
    {
        $mockId = 'mock_msg_' . substr(md5(uniqid('', true)), 0, 12);
        log_message('info', "ProviderAdapter [MOCK] {$detail}");
        return ['success' => true, 'message_id' => $mockId, 'error' => null];
    }

    private function err(string $message): array
    {
        log_message('error', "ProviderAdapter[{$this->provider}]: {$message}");
        return ['success' => false, 'message_id' => null, 'error' => $message];
    }
}
