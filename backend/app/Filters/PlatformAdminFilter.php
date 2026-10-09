<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Auth\CurrentUser;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Only the people who run TripSarthi itself (users.is_platform_admin = 1, granted on the server with `php spark admin:grant`).
 * Runs AFTER 'auth'. A workspace owner or admin is NOT a platform admin. Answers 403, never 401 (the SPA logs out on 401).
 */
class PlatformAdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ResponseInterface|null
    {
        $u = CurrentUser::get();
        $flag = is_array($u) ? ($u['is_platform_admin'] ?? 0) : ($u->is_platform_admin ?? 0);
        if ($u !== null && (int) $flag === 1) { return null; }
        return service('response')->setStatusCode(403)->setContentType('application/json')->setBody(json_encode(['success' => false, 'message' => 'This area is for the TripSarthi platform team only.']));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface|null { return null; }
}
