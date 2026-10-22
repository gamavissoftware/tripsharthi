<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Auth\CurrentUser;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A request that carries `Idempotency-Key` is performed ONCE: the first answer (success only) is remembered per person, and a retry with the same
 * key gets that answer back instead of creating a second task / contact / enquiry. The phone app uses it when it sends things it saved offline.
 * Without the header nothing changes. A key reused for a different address is refused (409), not silently mixed up.
 */
class IdempotencyFilter implements FilterInterface
{
    public const KEEP_HOURS = 72;

    private static ?string $pendingKey = null;

    private static function key(RequestInterface $r): ?string
    {
        $k = trim($r->getHeaderLine('Idempotency-Key'));
        return $k !== '' && preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $k) ? $k : null;
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        self::$pendingKey = null;
        $key = self::key($request); $uid = CurrentUser::id();
        if ($key === null || $uid <= 0 || ! in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) { return null; }
        $db = db_connect(); $path = md5(strtoupper($request->getMethod()) . ' ' . $request->getUri()->getPath());
        if (random_int(1, 40) === 1) { $db->table('idempotency_keys')->where('created_at <', date('Y-m-d H:i:s', time() - self::KEEP_HOURS * 3600))->delete(); }
        $row = $db->table('idempotency_keys')->where('user_id', $uid)->where('idem_key', $key)->get()->getRowArray();
        if ($row !== null) {
            if ($row['path_hash'] !== $path) { return service('response')->setStatusCode(409)->setJSON(['success' => false, 'message' => 'That request key was already used for something else.']); }
            if ($row['status'] === null) { return service('response')->setStatusCode(409)->setJSON(['success' => false, 'message' => 'The first attempt is still being processed. Try again in a moment.']); }
            return service('response')->setStatusCode((int) $row['status'])->setHeader('Idempotent-Replay', 'true')->setContentType('application/json')->setBody((string) $row['response']);
        }
        try {
            $db->table('idempotency_keys')->insert(['user_id' => $uid, 'idem_key' => $key, 'method' => strtoupper($request->getMethod()), 'path_hash' => $path, 'status' => null, 'response' => null, 'created_at' => date('Y-m-d H:i:s')]);
            self::$pendingKey = $key;
        } catch (\Throwable $e) {      // two copies of the same request arrived at once: only one may run
            return service('response')->setStatusCode(409)->setJSON(['success' => false, 'message' => 'The first attempt is still being processed. Try again in a moment.']);
        }
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $key = self::$pendingKey; self::$pendingKey = null;
        if ($key === null) { return null; }
        $db = db_connect(); $uid = CurrentUser::id(); $code = $response->getStatusCode();
        if ($code >= 200 && $code < 300) {
            $db->table('idempotency_keys')->where('user_id', $uid)->where('idem_key', $key)->update(['status' => $code, 'response' => (string) $response->getBody()]);
        } else {
            $db->table('idempotency_keys')->where('user_id', $uid)->where('idem_key', $key)->delete();      // a failed attempt may be retried for real
        }
        return null;
    }
}
