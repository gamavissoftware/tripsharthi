<?php

declare(strict_types=1);

namespace App\Services\Push;

/**
 * Expo Push API (https://exp.host/--/api/v2/push/*). Max 100 messages per send and 1,000 ids per receipts call.
 * With PUSH_MOCK_MODE=true nothing leaves this machine: sends succeed with fake tickets (local dev / tests).
 * EXPO_ACCESS_TOKEN (optional) enables Expo's "enhanced security" mode.
 */
final class ExpoPushClient
{
    public const SEND_URL = 'https://exp.host/--/api/v2/push/send';
    public const RECEIPTS_URL = 'https://exp.host/--/api/v2/push/getReceipts';

    /** @var callable(string,string,array):array{status:int,body:string} */
    private $http;

    public function __construct(?callable $http = null)
    {
        $this->http = $http ?? [\App\Services\Travel\ConversionFeedbackService::class, 'defaultHttp'];
    }

    public static function mock(): bool
    {
        return filter_var(env('PUSH_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function validToken(string $t): bool
    {
        return (bool) preg_match('/^Expo(nent)?PushToken\[[A-Za-z0-9_\-]{8,80}\]$/', $t);
    }

    /**
     * @param list<array<string,mixed>> $messages ≤100, each {to,title,body,data,...}
     * @return list<array{status:string,id?:string,error?:string,message?:string}> one ticket per message, same order
     * @throws PushTransportException when Expo is unreachable / rate-limiting / 5xx: callers keep the rows queued and retry
     */
    public function send(array $messages): array
    {
        if ($messages === []) { return []; }
        if (count($messages) > 100) { throw new \InvalidArgumentException('Expo accepts at most 100 messages per request.'); }
        if (self::mock()) {
            return array_map(static fn ($m) => ['status' => 'ok', 'id' => 'mock-' . bin2hex(random_bytes(6))], $messages);
        }
        $res = $this->call(self::SEND_URL, $messages);
        $data = $res['data'] ?? null;
        if (! is_array($data) || count($data) !== count($messages)) {
            throw new PushTransportException('Unexpected response from Expo (ticket count mismatch).');
        }
        return array_map(static fn ($t) => [
            'status' => (string) ($t['status'] ?? 'error'), 'id' => $t['id'] ?? null,
            'error' => $t['details']['error'] ?? null, 'message' => $t['message'] ?? null,
        ], array_values($data));
    }

    /** @param list<string> $ids @return array<string,array{status:string,error?:string,message?:string}> */
    public function receipts(array $ids): array
    {
        if ($ids === []) { return []; }
        if (self::mock()) { return array_fill_keys($ids, ['status' => 'ok']); }
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $data = $this->call(self::RECEIPTS_URL, ['ids' => $chunk])['data'] ?? [];
            foreach ($data as $id => $r) {
                $out[(string) $id] = ['status' => (string) ($r['status'] ?? 'error'), 'error' => $r['details']['error'] ?? null, 'message' => $r['message'] ?? null];
            }
        }
        return $out;
    }

    private function call(string $url, array $payload): array
    {
        $headers = ['Accept' => 'application/json', 'Accept-Encoding' => 'gzip, deflate', 'Content-Type' => 'application/json'];
        if ($t = env('EXPO_ACCESS_TOKEN')) { $headers['Authorization'] = 'Bearer ' . $t; }
        try {
            $res = ($this->http)('POST', $url, ['headers' => $headers, 'json' => $payload, 'timeout' => 4]);
        } catch (\Throwable $e) {
            throw new PushTransportException('Could not reach Expo: ' . $e->getMessage());
        }
        if ($res['status'] === 429 || $res['status'] >= 500) { throw new PushTransportException("Expo returned HTTP {$res['status']}."); }
        $body = json_decode($res['body'], true);
        if ($res['status'] >= 400) {
            $msg = $body['errors'][0]['message'] ?? "HTTP {$res['status']}";
            throw new \RuntimeException('Expo rejected the request: ' . $msg);   // 4xx: our payload is wrong; retrying will not help
        }
        return is_array($body) ? $body : [];
    }

    /** Error codes after which the device token is dead and must be disabled. */
    public static function isDeadToken(?string $error): bool
    {
        return $error === 'DeviceNotRegistered';
    }
}
