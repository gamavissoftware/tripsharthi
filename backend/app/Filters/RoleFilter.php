<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Auth\CurrentUser;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Role-based access control filter.
 *
 * Usage in Routes.php:
 *   ['filter' => 'role:owner']          — only owners
 *   ['filter' => 'role:owner,admin']    — owners and admins
 *
 * Role hierarchy: owner > admin > agent
 * Must be applied AFTER 'auth' filter (requires CurrentUser to be populated).
 */
class RoleFilter implements FilterInterface
{
    /** Role levels for hierarchy checks */
    private const LEVEL = [
        'owner' => 3,
        'admin' => 2,
        'agent' => 1,
    ];

    public function before(RequestInterface $request, $arguments = null): ResponseInterface|null
    {
        $user = CurrentUser::get();

        if ($user === null) {
            return $this->forbidden('Authentication required.');
        }

        $userRole = is_array($user) ? ($user['role'] ?? 'agent') : ($user->role ?? 'agent');

        // $arguments contains the roles passed to the filter, e.g. ['owner'] or ['owner', 'admin']
        $allowedRoles = $arguments ?? ['owner'];

        foreach ($allowedRoles as $role) {
            if ($userRole === $role) {
                return null; // allowed
            }
        }

        return $this->forbidden(
            "Access denied. Required role: " . implode(' or ', $allowedRoles) . ". Your role: {$userRole}."
        );
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface|null
    {
        return null;
    }

    private function forbidden(string $message): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode(403);
        $response->setContentType('application/json');
        $response->setBody(json_encode([
            'success' => false,
            'message' => $message,
            'code'    => 'FORBIDDEN',
        ]));
        return $response;
    }
}
