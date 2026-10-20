<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Per-IP (and optionally per-token) rate limiter using CI4's Throttler.
 *
 * Limits:
 *   - POST /auth/login  → 10 attempts / 60 s  (brute-force guard)
 *   - All other /api/v1 → 120 requests / 60 s  (general API)
 *
 * Uses the CI4 cache driver (file by default, Redis when configured).
 * Returns HTTP 429 with Retry-After header on breach.
 */
class RateLimitFilter implements FilterInterface
{
    /** General API for anonymous callers (public quote pages, forms, webhooks): requests per window, per IP */
    private const API_LIMIT  = 120;
    /** A signed-in user: requests per window, per USER (one agency's staff share an office IP, so a per-IP cap throttled whole offices) */
    private const USER_LIMIT = 600;
    /** Safety net for ALL signed-in traffic from one IP (a NAT with a large office) */
    private const IP_USER_LIMIT = 3000;
    /** Login endpoint: attempts per window */
    private const LOGIN_LIMIT = 10;
    /** Window in seconds */
    private const WINDOW = 60;

    public function before(RequestInterface $request, $arguments = null)
    {
        // Skip CORS preflight — no token consumed for OPTIONS
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return null;
        }

        $throttler = \Config\Services::throttler();

        $ip   = $request->getIPAddress();
        $path = $request->getUri()->getPath();

        // Tighter limit for auth/login to prevent brute-force
        $isLogin = str_ends_with($path, 'auth/login');
        // Hash the IP so IPv6 colons (reserved chars) don't break the cache key
        $ipHash = md5($ip);

        $bearer = '';
        if (preg_match('/^Bearer\s+([A-Za-z0-9_\-.]{16,200})$/', (string) $request->getHeaderLine('Authorization'), $m)) { $bearer = $m[1]; }
        if (! $isLogin && $bearer !== '') {
            // Signed-in traffic: a per-user bucket plus a generous per-IP ceiling, so one busy office is not throttled as one visitor.
            $key = 'tp_api_u_' . substr(hash('sha256', $bearer), 0, 32); $limit = self::USER_LIMIT;
            if (! $throttler->check('tp_api_ipu_' . $ipHash, self::IP_USER_LIMIT, self::WINDOW)) { return $this->tooMany($throttler->getTokenTime()); }
        } else {
            $limit = $isLogin ? self::LOGIN_LIMIT : self::API_LIMIT;
            $key   = ($isLogin ? 'tp_login_' : 'tp_api_') . $ipHash;
        }

        if (! $throttler->check($key, $limit, self::WINDOW)) {
            return $this->tooMany($throttler->getTokenTime());
        }

        return null; // allow request through
    }

    private function tooMany(int $retryAfter)
    {
        return service('response')
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) $retryAfter)
            ->setContentType('application/json')
            ->setBody(json_encode([
                'success'     => false,
                'message'     => 'Too many requests. Please slow down.',
                'retry_after' => $retryAfter,
            ]));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nothing to do after the response
    }
}
