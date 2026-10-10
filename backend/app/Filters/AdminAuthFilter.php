<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Admin\AdminSessionService;
use App\Services\Auth\CurrentUser;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Guards /api/v1/admin/*: only a live ADMIN SESSION (issued by /admin-auth/login) is accepted. A customer-app token is
 * never valid here, even for a platform admin, so the two apps stay separate. 401 when there is no valid session.
 */
class AdminAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ResponseInterface|null
    {
        $h = $request->getHeaderLine('Authorization');
        $user = str_starts_with($h, 'Bearer ') ? (new AdminSessionService())->user(substr($h, 7)) : null;
        if ($user === null) {
            return service('response')->setStatusCode(401)->setContentType('application/json')->setBody(json_encode(['success' => false, 'message' => 'Admin session missing or expired. Please sign in again.']));
        }
        CurrentUser::set($user);
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface|null { return null; }
}
