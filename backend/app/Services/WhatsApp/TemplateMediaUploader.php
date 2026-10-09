<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Converts an image into a Meta "header_handle" via the Resumable Upload API,
 * which is what a media/carousel template needs at submission time.
 *
 *   1. POST /{API_VERSION}/{app_id}/uploads?file_name&file_length&file_type
 *        → { "id": "upload:<session>" }
 *   2. POST /{API_VERSION}/<session>   (header file_offset:0, body = bytes)
 *        → { "h": "<handle>" }
 *
 * https://developers.facebook.com/docs/graph-api/guides/upload
 *
 * Honors WHATSAPP_MOCK_MODE (returns a fake handle for local testing).
 */
class TemplateMediaUploader
{
    private string $graphVersion;
    private bool   $mockMode;

    public function __construct(
        private readonly string $appId,
        private readonly string $accessToken,
    ) {
        $this->graphVersion = (string) env('META_GRAPH_VERSION', 'v22.0');
        $this->mockMode     = filter_var(env('WHATSAPP_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Resolve a publicly-reachable image URL to a Meta header_handle.
     *
     * @return array{success:bool, handle:string, error:?string}
     */
    public function handleForUrl(string $imageUrl): array
    {
        if ($this->mockMode) {
            return ['success' => true, 'handle' => 'mock_handle_' . substr(md5($imageUrl), 0, 16), 'error' => null];
        }

        if ($this->appId === '') {
            return ['success' => false, 'handle' => '', 'error' => 'Meta App ID is not configured for this WABA.'];
        }

        // ── Fetch the image bytes ─────────────────────────────────────
        $bytes = $this->fetch($imageUrl);
        if ($bytes === null) {
            return ['success' => false, 'handle' => '', 'error' => "Could not download image: {$imageUrl}"];
        }
        $len  = strlen($bytes);
        $mime = $this->guessMime($imageUrl);
        $name = basename(parse_url($imageUrl, PHP_URL_PATH) ?: 'image.jpg');

        // ── Step 1: create the upload session ─────────────────────────
        $sessionUrl = "https://graph.facebook.com/{$this->graphVersion}/{$this->appId}/uploads"
            . '?' . http_build_query(['file_name' => $name, 'file_length' => $len, 'file_type' => $mime]);
        $session = $this->post($sessionUrl, '', ["Authorization: OAuth {$this->accessToken}"]);
        $sessionId = $session['id'] ?? null;
        if ($sessionId === null) {
            return ['success' => false, 'handle' => '', 'error' => $this->err($session, 'create upload session')];
        }

        // ── Step 2: upload the bytes → handle ─────────────────────────
        $uploadUrl = "https://graph.facebook.com/{$this->graphVersion}/{$sessionId}";
        $result    = $this->post($uploadUrl, $bytes, [
            "Authorization: OAuth {$this->accessToken}",
            'file_offset: 0',
        ]);
        $handle = $result['h'] ?? null;
        if ($handle === null) {
            return ['success' => false, 'handle' => '', 'error' => $this->err($result, 'upload image bytes')];
        }

        return ['success' => true, 'handle' => $handle, 'error' => null];
    }

    // ------------------------------------------------------------------

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_FOLLOWLOCATION    => true,
            CURLOPT_TIMEOUT           => 20,
            CURLOPT_SSL_VERIFYPEER    => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200 && $body !== '') ? $body : null;
    }

    private function post(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $body,
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_TIMEOUT           => 30,
            CURLOPT_SSL_VERIFYPEER    => true,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return is_string($resp) ? (json_decode($resp, true) ?: []) : [];
    }

    private function err(array $resp, string $step): string
    {
        $msg = $resp['error']['message'] ?? 'unknown error';
        return "Meta resumable upload failed ({$step}): {$msg}";
    }

    private function guessMime(string $url): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        return match ($ext) {
            'png'        => 'image/png',
            'webp'       => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default      => 'image/jpeg',
        };
    }
}
