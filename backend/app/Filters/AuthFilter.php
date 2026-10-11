<?php

declare(strict_types=1);

namespace App\Filters;

use App\Models\UserModel;
use App\Services\Admin\ImpersonationService;
use App\Services\Auth\CurrentUser;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Validates the Bearer token on every protected request, then populates
 * CurrentUser so controllers never re-query the DB for the auth user.
 *
 * Returns JSON 401 on failure (no redirects — this is a pure API).
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ResponseInterface|null
    {
        /** @var IncomingRequest $request */
        $authHeader = $request->getHeaderLine('Authorization');

        if (! str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorized('Missing or invalid Authorization header.');
        }

        $rawToken = substr($authHeader, 7);

        if (empty($rawToken)) {
            return $this->unauthorized('Empty token.');
        }

        $userModel = new UserModel();
        $user      = $userModel->findByToken($rawToken);

        // Not a customer token? It may be a "log in as customer" support session opened by a platform admin (separate token, acts as the workspace owner).
        if ($user === null) {
            $imp = (new ImpersonationService())->resolve($rawToken);
            if ($imp === null) {
                return $this->unauthorized('Invalid or expired token.');
            }
            $refusal = ImpersonationService::refusal($request->getMethod(), $request->getUri()->getPath(), (int) $imp['session']['read_only'] === 1);
            if ($refusal !== null) {
                return service('response')->setStatusCode(403)->setContentType('application/json')->setBody(json_encode(['success' => false, 'code' => 'support_session_limited', 'message' => $refusal]));
            }
            CurrentUser::set($imp['user']);
            CurrentUser::setImpersonation($imp['session']);
            if (! in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true) && ! str_ends_with(rtrim($request->getUri()->getPath(), '/'), 'auth/logout')) {
                (new ImpersonationService())->countWrite((int) $imp['session']['id']);
            }
            return null;   // suspended workspaces are deliberately reachable: support often needs to look inside one
        }

        // A suspended or cancelled workspace cannot use the API (platform admins are never locked out). 403, not 401: the SPA logs out on 401.
        $isAdmin = (int) (is_array($user) ? ($user['is_platform_admin'] ?? 0) : ($user->is_platform_admin ?? 0)) === 1;
        if (! $isAdmin) {
            $tid = (int) (is_array($user) ? $user['tenant_id'] : $user->tenant_id);
            $t = db_connect()->table('tenants')->select('status')->where('id', $tid)->get()->getRowArray();
            if ($t !== null && $t['status'] !== 'active') {
                return service('response')->setStatusCode(403)->setContentType('application/json')->setBody(json_encode([
                    'success' => false, 'code' => 'tenant_' . $t['status'],
                    'message' => 'This workspace is ' . $t['status'] . '. Please contact TripSarthi support.',
                ]));
            }
        }

        CurrentUser::set($user);
        return null; // proceed
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface|null
    {
        return null;
    }

    private function unauthorized(string $message): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode(401);
        $response->setContentType('application/json');
        $response->setBody(json_encode([
            'success' => false,
            'message' => $message,
        ]));
        return $response;
    }
}
