<?php

declare(strict_types=1);

namespace App\Services\Social;

use RuntimeException;

/**
 * Thin Facebook Graph API HTTP client for social publishing.
 *
 * Kept deliberately dumb (one GET, one POST) so SocialPublisher holds all the
 * platform logic and tests can stub this class.
 */
class GraphClient
{
    public const GRAPH_BASE = 'https://graph.facebook.com/v21.0/';

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed> decoded JSON
     *
     * @throws RuntimeException on transport failure or Graph error payload
     */
    public function get(string $path, array $params): array
    {
        return $this->request('GET', $path, $params);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed> decoded JSON
     *
     * @throws RuntimeException on transport failure or Graph error payload
     */
    public function post(string $path, array $params): array
    {
        return $this->request('POST', $path, $params);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed> decoded JSON
     *
     * @throws RuntimeException on transport failure or Graph error payload
     */
    public function delete(string $path, array $params): array
    {
        return $this->request('DELETE', $path, $params);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    protected function request(string $method, string $path, array $params): array
    {
        $client = \Config\Services::curlrequest([
            'baseURI' => 'https://graph.facebook.com/' . ((string) (env('META_GRAPH_VERSION') ?: 'v21.0')) . '/',
            'timeout' => 30,
        ]);

        try {
            $response = match ($method) {
                'GET'    => $client->get($path, ['query' => $params, 'http_errors' => false]),
                'DELETE' => $client->delete($path, ['query' => $params, 'http_errors' => false]),
                default  => $client->post($path, ['form_params' => $params, 'http_errors' => false]),
            };
        } catch (\Throwable $e) {
            throw new RuntimeException('Graph API request failed: ' . $e->getMessage(), 0, $e);
        }

        $body = json_decode((string) $response->getBody(), true) ?? [];

        if (isset($body['error'])) {
            $err = $body['error'];
            throw new GraphApiException(
                sprintf(
                    'Graph API error %s (%s): %s',
                    $err['code'] ?? '?',
                    $err['type'] ?? 'unknown',
                    $err['message'] ?? 'no message'
                ),
                (int) ($err['code'] ?? 0),
                (int) ($err['error_subcode'] ?? 0),
                (string) ($err['type'] ?? '')
            );
        }

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Graph API HTTP ' . $response->getStatusCode());
        }

        return $body;
    }
}
