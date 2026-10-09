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
    /** General API: requests per window */
    private const API_LIMIT  = 120;
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
        $limit   = $isLogin ? self::LOGIN_LIMIT : self::API_LIMIT;
        // Hash the IP so IPv6 colons (reserved chars) don't break the cache key
        $key = ($isLogin ? 'tp_login_' : 'tp_api_') . md5($ip);

        if (! $throttler->check($key, $limit, self::WINDOW)) {
            $retryAfter = $throttler->getTokenTime();

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

        return null; // allow request through
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nothing to do after the response
    }
}
