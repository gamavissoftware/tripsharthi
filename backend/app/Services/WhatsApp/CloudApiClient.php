<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Meta WhatsApp Cloud API client — Meta only, no multi-provider abstraction.
 *
 * Ported and trimmed from EaseMySale's WhatsAppProviderLibrary (Meta methods).
 * Key changes vs. EaseMySale:
 *  - Graph API version comes from META_GRAPH_VERSION env var (default v22.0)
 *  - Token is passed decrypted by the caller — never stored here
 *  - SSL verification enforced (CURLOPT_SSL_VERIFYPEER = true)
 *  - Errors logged at ERROR level
 *  - WHATSAPP_MOCK_MODE: when true, all calls return simulated success responses
 *    so the full pipeline runs locally without hitting Meta. Logs at alert level.
 */
class CloudApiClient
{
    private string $graphVersion;
    private bool   $mockMode;

    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $accessToken, // raw, already decrypted
    ) {
        $this->graphVersion = env('META_GRAPH_VERSION', 'v22.0');
        $this->mockMode     = filter_var(env('WHATSAPP_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);

        if ($this->mockMode) {
            log_message('alert',
                'CloudApiClient: WHATSAPP_MOCK_MODE is enabled — Meta message API calls are simulated. '
                . 'DO NOT use in production.'
            );
        }
    }

    // ------------------------------------------------------------------
    // Send methods
    // ------------------------------------------------------------------

    /**
     * Send a plain-text message (free-form — only allowed inside open window).
     */
    public function sendText(string $to, string $body): array
    {
        if ($this->mockMode) {
            $mockId = 'mock_msg_' . substr(md5(uniqid('', true)), 0, 12);
            log_message('info', "CloudApiClient [MOCK] sendText to={$to}");
            return ['success' => true, 'code' => 200, 'message_id' => $mockId, 'error' => null];
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['body' => $body, 'preview_url' => false],
        ]);
    }

    /**
     * Send an approved template message.
     * $components: Meta-format components array (body params, header media, etc.)
     */
    public function sendTemplate(string $to, string $templateName, string $language, array $components = []): array
    {
        if ($this->mockMode) {
            $mockId = 'mock_msg_' . substr(md5(uniqid('', true)), 0, 12);
            log_message('info', "CloudApiClient [MOCK] sendTemplate to={$to} template={$templateName}");
            return ['success' => true, 'code' => 200, 'message_id' => $mockId, 'error' => null];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $templateName,
                'language'   => ['code' => $language],
                'components' => $components,
            ],
        ];

        return $this->post($payload);
    }

    /**
     * Send a media message using a WhatsApp media_id.
     *
     * $type: image | document | audio | video
     */
    public function sendMedia(string $to, string $type, string $mediaId, string $caption = '', string $filename = ''): array
    {
        $mediaObj = ['id' => $mediaId];
        if ($caption !== '')  $mediaObj['caption']  = $caption;
        if ($filename !== '' && $type === 'document') $mediaObj['filename'] = $filename;

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $type,
            $type               => $mediaObj,
        ]);
    }

    public function sendInteractive(string $to, array $payload): array
    {
        $type = $payload['type'] ?? 'button';

        $interactive = ['type' => $type];

        // Header (optional for all types)
        if (! empty($payload['header'])) {
            $interactive['header'] = ['type' => 'text', 'text' => $payload['header']];
        }

        // Body (required)
        $interactive['body'] = ['text' => $payload['body'] ?? ''];

        // Footer (optional)
        if (! empty($payload['footer'])) {
            $interactive['footer'] = ['text' => $payload['footer']];
        }

        switch ($type) {
            case 'button':
                $buttons = [];
                foreach ($payload['buttons'] ?? [] as $btn) {
                    $buttons[] = [
                        'type'  => 'reply',
                        'reply' => [
                            'id'    => $btn['id']    ?? 'btn_' . count($buttons),
                            'title' => mb_substr($btn['title'] ?? 'Option', 0, 20),
                        ],
                    ];
                }
                $interactive['action'] = ['buttons' => array_slice($buttons, 0, 3)];
                break;

            case 'cta_url':
                $interactive['action'] = [
                    'name'       => 'cta_url',
                    'parameters' => [
                        'display_text' => $payload['cta_text'] ?? 'Open Link',
                        'url'          => $payload['cta_url']  ?? 'https://example.com',
                    ],
                ];
                break;

            case 'list':
                $sections = [];
                foreach ($payload['sections'] ?? [] as $sec) {
                    $rows = [];
                    foreach ($sec['rows'] ?? [] as $row) {
                        $rows[] = array_filter([
                            'id'          => $row['id']          ?? 'row_' . count($rows),
                            'title'       => mb_substr($row['title'] ?? 'Option', 0, 24),
                            'description' => isset($row['description']) ? mb_substr($row['description'], 0, 72) : null,
                        ], static fn($v) => $v !== null);
                    }
                    $sections[] = ['title' => $sec['title'] ?? '', 'rows' => $rows];
                }
                $interactive['action'] = [
                    'button'   => $payload['button_text'] ?? 'View Options',
                    'sections' => $sections,
                ];
                break;
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ]);
    }

    /**
     * Send a single-product message from the tenant's Meta catalog.
     */
    public function sendProduct(string $to, string $catalogId, string $retailerId, string $body = '', string $footer = ''): array
    {
        if ($this->mockMode) {
            log_message('info', "CloudApiClient [MOCK] sendProduct to={$to} sku={$retailerId}");
            return ['success' => true, 'code' => 200, 'message_id' => 'mock_msg_' . substr(md5(uniqid('', true)), 0, 12), 'error' => null];
        }

        $interactive = [
            'type'   => 'product',
            'action' => ['catalog_id' => $catalogId, 'product_retailer_id' => $retailerId],
        ];
        if ($body !== '')   { $interactive['body']   = ['text' => $body]; }
        if ($footer !== '') { $interactive['footer'] = ['text' => $footer]; }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ]);
    }

    /**
     * Send a multi-product (catalog list) message.
     *
     * @param array $sections [{title, product_items:[retailer_id, ...]}]
     */
    public function sendProductList(string $to, string $catalogId, string $headerText, string $body, array $sections): array
    {
        if ($this->mockMode) {
            log_message('info', "CloudApiClient [MOCK] sendProductList to={$to} catalog={$catalogId}");
            return ['success' => true, 'code' => 200, 'message_id' => 'mock_msg_' . substr(md5(uniqid('', true)), 0, 12), 'error' => null];
        }

        $sectionPayload = [];
        foreach ($sections as $sec) {
            $items = [];
            foreach ($sec['product_items'] ?? [] as $sku) {
                $items[] = ['product_retailer_id' => $sku];
            }
            $sectionPayload[] = ['title' => mb_substr($sec['title'] ?? 'Products', 0, 24), 'product_items' => $items];
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'product_list',
                'header' => ['type' => 'text', 'text' => mb_substr($headerText, 0, 60)],
                'body'   => ['text' => $body],
                'action' => ['catalog_id' => $catalogId, 'sections' => $sectionPayload],
            ],
        ]);
    }

    /**
     * Send a published Meta WhatsApp Flow (native in-chat form).
     *
     * @param string $flowToken Caller-generated token echoed back in the submission
     *                          (nfm_reply) so the response can be correlated.
     */
    public function sendFlow(string $to, string $flowId, string $flowToken, string $ctaText, string $body, string $screen = '', array $data = []): array
    {
        if ($this->mockMode) {
            log_message('info', "CloudApiClient [MOCK] sendFlow to={$to} flow={$flowId}");
            return ['success' => true, 'code' => 200, 'message_id' => 'mock_msg_' . substr(md5(uniqid('', true)), 0, 12), 'error' => null];
        }

        $params = [
            'flow_message_version' => '3',
            'flow_token'           => $flowToken,
            'flow_id'              => $flowId,
            'flow_cta'             => mb_substr($ctaText !== '' ? $ctaText : 'Open', 0, 30),
            'flow_action'          => 'navigate',
        ];
        if ($screen !== '') {
            $params['flow_action_payload'] = ['screen' => $screen, 'data' => (object) $data];
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'flow',
                'body'   => ['text' => $body !== '' ? $body : 'Please tap below to continue.'],
                'action' => ['name' => 'flow', 'parameters' => $params],
            ],
        ]);
    }

    /**
     * Upload a media file to WhatsApp and return the media_id.
     */
    public function uploadMedia(string $filePath, string $mimeType): array
    {
        $url = "https://graph.facebook.com/{$this->graphVersion}/{$this->phoneNumberId}/media";
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'messaging_product' => 'whatsapp',
                'type'              => $mimeType,
                'file'              => new \CURLFile($filePath, $mimeType, basename($filePath)),
            ],
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$this->accessToken}"],
            CURLOPT_TIMEOUT_MS     => 30000,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            log_message('error', "CloudApiClient::uploadMedia cURL error: {$err}");
            return ['success' => false, 'media_id' => null, 'error' => $err];
        }

        $body = json_decode($resp, true) ?? [];
        if ($code >= 200 && $code < 300 && isset($body['id'])) {
            return ['success' => true, 'media_id' => $body['id']];
        }

        $errMsg = $body['error']['message'] ?? "HTTP {$code}";
        log_message('error', "CloudApiClient::uploadMedia failed: {$errMsg}");
        return ['success' => false, 'media_id' => null, 'error' => $errMsg];
    }

    /**
     * Verify the phone number connection and return basic info.
     */
    public function testConnection(): array
    {
        if ($this->mockMode) {
            return ['success' => true, 'info' => [
                'display_phone_number' => '+91 99999 00000',
                'verified_name'        => 'Mock Business',
                'quality_rating'       => 'GREEN',
            ]];
        }

        if (empty($this->phoneNumberId) || empty($this->accessToken)) {
            return ['success' => false, 'error' => 'Phone Number ID and Access Token are required.'];
        }

        $url = "https://graph.facebook.com/{$this->graphVersion}/{$this->phoneNumberId}";
        $ch  = curl_init($url . '?fields=display_phone_number,verified_name,quality_rating');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$this->accessToken}"],
            CURLOPT_TIMEOUT_MS     => 8000,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode($resp, true) ?? [];
        if ($code === 200) {
            return ['success' => true, 'info' => [
                'display_phone_number' => $body['display_phone_number'] ?? '',
                'verified_name'        => $body['verified_name']        ?? '',
                'quality_rating'       => $body['quality_rating']       ?? 'UNKNOWN',
            ]];
        }

        $errMsg = $body['error']['message'] ?? "HTTP {$code}";
        return ['success' => false, 'error' => $errMsg];
    }

    // ------------------------------------------------------------------
    // Internal HTTP helper
    // ------------------------------------------------------------------

    private function post(array $payload): array
    {
        $url = "https://graph.facebook.com/{$this->graphVersion}/{$this->phoneNumberId}/messages";
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => json_encode($payload),
            CURLOPT_HTTPHEADER        => [
                'Content-Type: application/json',
                "Authorization: Bearer {$this->accessToken}",
            ],
            CURLOPT_TIMEOUT_MS        => 10000,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_SSL_VERIFYPEER    => true,
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            log_message('error', "CloudApiClient::post cURL error to {$url}: {$err}");
            return ['success' => false, 'code' => 0, 'message_id' => null, 'error' => $err];
        }

        $body  = json_decode($resp, true) ?? [];
        $msgId = $body['messages'][0]['id'] ?? null;

        if ($code < 200 || $code >= 300) {
            $errMsg = $body['error']['message'] ?? "HTTP {$code}";
            log_message('error', "CloudApiClient::post failed [{$code}]: {$errMsg}");
            return ['success' => false, 'code' => $code, 'message_id' => null, 'error' => $errMsg];
        }

        return ['success' => true, 'code' => $code, 'message_id' => $msgId, 'error' => null];
    }
}
