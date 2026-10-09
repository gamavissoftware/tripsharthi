<?php

declare(strict_types=1);

namespace App\Services\Einvoice;

/**
 * Talks to an IRP through a GSP / e-invoice API provider that accepts the standard INV-01 JSON over REST and answers with NIC-style fields
 * (Irn, AckNo, AckDt, SignedQRCode). Endpoints and authentication are CONFIGURED per provider because every GSP differs; the direct NIC protocol
 * (RSA/AES-ECB session encryption) is NOT implemented here. Nothing in this class can be proven without a provider account — use the provider's
 * sandbox first.
 *
 * config: base_url, generate_path, cancel_path, find_path, auth {type: headers|bearer|basic, headers{}, token, username, password}, timeout
 */
final class GspIrpClient implements IrpClient
{
    /** @param null|callable(string,string,array):array{status:int,body:string} $http */
    public function __construct(private readonly array $cfg, private readonly mixed $http = null) {}

    private function headers(): array
    {
        $a = (array) ($this->cfg['auth'] ?? []);
        $h = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        return $h + match ($a['type'] ?? 'headers') {
            'bearer' => ['Authorization' => 'Bearer ' . ($a['token'] ?? '')],
            'basic'  => ['Authorization' => 'Basic ' . base64_encode(($a['username'] ?? '') . ':' . ($a['password'] ?? ''))],
            default  => array_filter((array) ($a['headers'] ?? []), static fn ($v, $k) => is_string($k) && $k !== '' && $v !== '', ARRAY_FILTER_USE_BOTH),
        };
    }

    private function call(string $method, string $path, array $json = [], array $query = []): array
    {
        $base = rtrim((string) ($this->cfg['base_url'] ?? ''), '/');
        if (! preg_match('#^https://#', $base)) { throw new EinvoiceException('The e-invoice provider address must start with https://.', EinvoiceException::INVALID); }
        $url = $base . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
        $opt = ['headers' => $this->headers(), 'timeout' => (int) ($this->cfg['timeout'] ?? 30)] + ($method === 'GET' ? [] : ['json' => $json]);
        try { $res = ($this->http ?? [\App\Services\Travel\ConversionFeedbackService::class, 'defaultHttp'])($method, $url, $opt); }
        catch (\Throwable $e) { throw new EinvoiceException('Could not reach the e-invoice provider: ' . $e->getMessage(), EinvoiceException::TRANSIENT); }
        $body = json_decode((string) $res['body'], true);
        $body = is_array($body) ? $body : [];
        if ($res['status'] === 401 || $res['status'] === 403) { throw new EinvoiceException('The e-invoice provider rejected your credentials. Check them in Settings → E-invoicing.', EinvoiceException::AUTH, (string) $res['status']); }
        if ($res['status'] >= 500 || $res['status'] === 429 || $res['status'] === 408) { throw new EinvoiceException('The e-invoice service is not responding. Nothing was issued — try again in a moment.', EinvoiceException::TRANSIENT, (string) $res['status']); }
        return ['status' => $res['status'], 'body' => $body];
    }

    /** The data object lives at the top level or under data/Data/result/Result depending on the provider. */
    private static function data(array $b): array
    {
        foreach (['data', 'Data', 'result', 'Result', 'response', 'Response'] as $k) { if (isset($b[$k]) && is_array($b[$k])) { return $b[$k] + $b; } }
        return $b;
    }

    private static function error(array $b, int $http): ?array
    {
        $d = self::data($b);
        $code = $d['ErrorCode'] ?? $d['error_code'] ?? $d['errorCode'] ?? ($b['error']['code'] ?? null);
        $msg = $d['ErrorMessage'] ?? $d['error_message'] ?? $d['errorMessage'] ?? $d['message'] ?? ($b['error']['message'] ?? null);
        if ($code === null && $msg === null && $http < 400 && (isset($d['Irn']) || isset($d['irn']))) { return null; }
        if ($code === null && $msg === null && $http < 400 && (($b['status'] ?? $b['Status'] ?? 1) == 1 || ($b['success'] ?? true) === true)) { return null; }
        $info = $d['InfoDtls'] ?? $d['info'] ?? null;
        return ['code' => $code !== null ? (string) $code : null, 'message' => is_string($msg) && $msg !== '' ? $msg : 'The e-invoice provider refused the request.', 'info' => $info];
    }

    private static function ack(array $d): ?array
    {
        $irn = (string) ($d['Irn'] ?? $d['irn'] ?? '');
        if ($irn === '') { return null; }
        $dt = (string) ($d['AckDt'] ?? $d['ack_dt'] ?? $d['ackDt'] ?? '');
        $ts = strtotime($dt);
        return ['irn' => $irn, 'ack_no' => (string) ($d['AckNo'] ?? $d['ack_no'] ?? $d['ackNo'] ?? ''), 'ack_dt' => $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s'),
            'signed_qr' => (string) ($d['SignedQRCode'] ?? $d['signed_qr'] ?? $d['signedQRCode'] ?? '')];
    }

    public function generate(array $payload): array
    {
        $r = $this->call('POST', (string) ($this->cfg['generate_path'] ?? '/einvoice/generate'), $payload);
        if ($err = self::error($r['body'], $r['status'])) {
            if ($err['code'] === '2150') {                                                    // duplicate: the provider usually tells us the existing IRN
                $existing = self::ack(is_array($err['info']) ? (isset($err['info'][0]) ? (array) $err['info'][0] : $err['info']) : []) ?? $this->findByDocument((string) $payload['DocDtls']['Typ'], (string) $payload['DocDtls']['No'], (string) $payload['DocDtls']['Dt']);
                throw new EinvoiceException($err['message'], EinvoiceException::DUPLICATE, '2150', $existing ? ['existing' => $existing] : null);
            }
            throw new EinvoiceException($err['message'], EinvoiceException::REJECTED, $err['code']);
        }
        $a = self::ack(self::data($r['body']));
        if (! $a || $a['signed_qr'] === '') { throw new EinvoiceException('The provider answered without an IRN or signed QR code. Nothing was issued; check the provider log before retrying.', EinvoiceException::REJECTED); }
        return $a;
    }

    public function cancel(string $irn, int $reasonCode, string $remark): array
    {
        $r = $this->call('POST', (string) ($this->cfg['cancel_path'] ?? '/einvoice/cancel'), ['Irn' => $irn, 'CnlRsn' => (string) $reasonCode, 'CnlRem' => mb_substr($remark, 0, 100)]);
        if ($err = self::error($r['body'], $r['status'])) { throw new EinvoiceException($err['message'], in_array($err['code'], ['2162', '2270'], true) ? EinvoiceException::WINDOW : EinvoiceException::REJECTED, $err['code']); }
        $d = self::data($r['body']);
        return ['irn' => (string) ($d['Irn'] ?? $irn), 'cancel_dt' => date('Y-m-d H:i:s', strtotime((string) ($d['CancelDate'] ?? $d['cancel_dt'] ?? 'now')) ?: time())];
    }

    public function findByDocument(string $docType, string $docNo, string $docDate): ?array
    {
        $r = $this->call('GET', (string) ($this->cfg['find_path'] ?? '/einvoice/irn'), [], ['docType' => $docType, 'docNum' => $docNo, 'docDate' => $docDate]);
        return self::error($r['body'], $r['status']) ? null : self::ack(self::data($r['body']));
    }
}
