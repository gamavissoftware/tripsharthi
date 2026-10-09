<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Meta Graph API client for template management.
 *
 * Uses the WABA ID (not phone_number_id) for template endpoints.
 * Supports WHATSAPP_MOCK_MODE for offline/local development.
 *
 * Template endpoints:
 *   POST   /{waba_id}/message_templates   — create
 *   GET    /{meta_template_id}            — get single (sync status)
 *   GET    /{waba_id}/message_templates   — list all (bulk sync)
 *   DELETE /{waba_id}/message_templates   — delete by name+language
 */
class TemplateApiClient
{
    private string $graphVersion;
    private bool   $mockMode;

    public function __construct(
        private readonly string $wabaId,
        private readonly string $accessToken,
    ) {
        $this->graphVersion = env('META_GRAPH_VERSION', 'v22.0');
        $this->mockMode     = filter_var(env('WHATSAPP_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);

        if ($this->mockMode) {
            log_message('alert',
                'TemplateApiClient: WHATSAPP_MOCK_MODE is enabled — Meta template API calls are simulated. '
                . 'DO NOT use in production.'
            );
        }
    }

    // ------------------------------------------------------------------
    // Create template
    // ------------------------------------------------------------------

    /**
     * Submit a new template to Meta for approval.
     *
     * @param  array $templateData  { name, category, language, components }
     * @return array { success, meta_template_id, meta_status, error }
     */
    public function create(array $templateData): array
    {
        if ($this->mockMode) {
            $mockId = 'mock_tpl_' . substr(md5(uniqid('', true)), 0, 12);
            log_message('info', "TemplateApiClient [MOCK] create: fake id={$mockId}");
            return [
                'success'          => true,
                'meta_template_id' => $mockId,
                'meta_status'      => 'pending',
                'error'            => null,
            ];
        }

        $url  = "https://graph.facebook.com/{$this->graphVersion}/{$this->wabaId}/message_templates";
        $resp = $this->post($url, $templateData);

        if (! $resp['success']) {
            return array_merge($resp, ['meta_template_id' => null, 'meta_status' => null]);
        }

        $body = $resp['body'];
        return [
            'success'          => true,
            'meta_template_id' => (string) ($body['id'] ?? ''),
            'meta_status'      => strtolower($body['status'] ?? 'pending'),
            'error'            => null,
        ];
    }

    // ------------------------------------------------------------------
    // Sync single template status
    // ------------------------------------------------------------------

    /**
     * Fetch the current approval status for a specific template.
     *
     * @return array { success, meta_status, rejection_reason, error }
     */
    public function syncStatus(string $metaTemplateId): array
    {
        if ($this->mockMode) {
            log_message('info', "TemplateApiClient [MOCK] syncStatus: id={$metaTemplateId} → approved");
            return [
                'success'          => true,
                'meta_status'      => 'approved',
                'rejection_reason' => null,
                'error'            => null,
            ];
        }

        $url  = "https://graph.facebook.com/{$this->graphVersion}/{$metaTemplateId}";
        $resp = $this->get($url . '?fields=id,name,status,rejected_reason');

        if (! $resp['success']) {
            return ['success' => false, 'meta_status' => null, 'rejection_reason' => null, 'error' => $resp['error']];
        }

        $body = $resp['body'];
        return [
            'success'          => true,
            'meta_status'      => strtolower($body['status'] ?? 'pending'),
            'rejection_reason' => $body['rejected_reason'] ?? null,
            'error'            => null,
        ];
    }

    // ------------------------------------------------------------------
    // List all templates (for bulk sync via spark command)
    // ------------------------------------------------------------------

    /**
     * Fetch all templates registered on the WABA.
     *
     * @return array { success, templates: [{id, name, status, …}], error }
     */
    public function listAll(): array
    {
        if ($this->mockMode) {
            return ['success' => true, 'templates' => [], 'error' => null];
        }

        $url  = "https://graph.facebook.com/{$this->graphVersion}/{$this->wabaId}/message_templates"
              . '?fields=id,name,status,language,category,rejected_reason&limit=200';
        $resp = $this->get($url);

        if (! $resp['success']) {
            return ['success' => false, 'templates' => [], 'error' => $resp['error']];
        }

        return [
            'success'   => true,
            'templates' => $resp['body']['data'] ?? [],
            'error'     => null,
        ];
    }

    // ------------------------------------------------------------------
    // Delete template
    // ------------------------------------------------------------------

    /**
     * Delete a template from Meta by name and language.
     * Note: Meta deletes by name (all languages) or name+language pair.
     */
    public function delete(string $templateName, string $metaTemplateId): array
    {
        if ($this->mockMode) {
            return ['success' => true, 'error' => null];
        }

        $url  = "https://graph.facebook.com/{$this->graphVersion}/{$this->wabaId}/message_templates";
        $resp = $this->request('DELETE', $url, ['name' => $templateName, 'hsm_id' => $metaTemplateId]);

        return ['success' => $resp['success'], 'error' => $resp['error']];
    }

    // ------------------------------------------------------------------
    // HTTP helpers
    // ------------------------------------------------------------------

    private function post(string $url, array $payload): array
    {
        return $this->request('POST', $url, $payload);
    }

    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$this->accessToken}"],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        return $this->execAndParse($ch);
    }

    private function request(string $method, string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CUSTOMREQUEST     => $method,
            CURLOPT_POSTFIELDS        => json_encode($payload),
            CURLOPT_HTTPHEADER        => [
                'Content-Type: application/json',
                "Authorization: Bearer {$this->accessToken}",
            ],
            CURLOPT_TIMEOUT           => 60,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_SSL_VERIFYPEER    => true,
        ]);
        return $this->execAndParse($ch);
    }

    private function execAndParse(\CurlHandle $ch): array
    {
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            log_message('error', "TemplateApiClient cURL error: {$err}");
            return ['success' => false, 'code' => 0, 'body' => [], 'error' => $err];
        }

        $body = json_decode($resp, true) ?? [];
        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'code' => $code, 'body' => $body, 'error' => null];
        }

        $e = $body['error'] ?? [];
        // Surface Meta's specific reason (error_user_msg), not just "Invalid parameter".
        $errMsg = $e['error_user_msg'] ?? $e['error_user_title'] ?? $e['message'] ?? "HTTP {$code}";
        log_message('error', "TemplateApiClient [{$code}]: " . json_encode($e));
        return ['success' => false, 'code' => $code, 'body' => $body, 'error' => $errMsg];
    }
}
