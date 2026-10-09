<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Licensing\LicenseService;
use App\Services\Tenancy\FeatureGate;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Enforces read-only mode when the self_hosted license is invalid or expired.
 *
 * ── No network call in the request path ─────────────────────────────────
 * LicenseService::checkStatus() is a PURE DB READ — it reads the licenses table
 * and verifies the HMAC signature locally.  It does NOT call runPhoneHome() or
 * make any outbound HTTP requests.  The network call lives exclusively in:
 *   - LicenseService::activate()   (one-time activation, synchronous, user is waiting)
 *   - LicenseService::runPhoneHome() (called only by license:check cron, daily)
 *
 * ── GETs always pass ─────────────────────────────────────────────────────
 * Even with a fully expired grace period, read operations always succeed.
 * The owner can log in, see data, and export it — they just cannot write
 * until they renew the license.
 *
 * ── SaaS mode ────────────────────────────────────────────────────────────
 * Returns null immediately (zero cost) when APP_MODE != self_hosted.
 * The filter is registered globally; mode-awareness is self-contained.
 */
class LicenseFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ResponseInterface|null
    {
        // Zero-cost pass-through in SaaS mode — no DB access
        if (! FeatureGate::isLicenseRequired()) {
            return null;
        }

        // Pure DB read — no network, no curl, no outbound calls
        $status = (new LicenseService())->checkStatus();

        if ($status->isReadOnly && strtoupper($request->getMethod()) !== 'GET') {
            return $this->readOnlyResponse($status->graceDaysLeft);
        }

        return null; // proceed
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface|null
    {
        return null;
    }

    // ------------------------------------------------------------------

    private function readOnlyResponse(int $graceDaysLeft): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode(503);
        $response->setContentType('application/json');
        $response->setBody(json_encode([
            'success'         => false,
            'error'           => 'license_read_only',
            'grace_days_left' => $graceDaysLeft,
            'message'         => 'License check failed. System is in read-only mode. '
                               . 'Run `php spark license:check` or contact support@gamavis.com.',
        ]));
        return $response;
    }
}
