<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\UserModel;
use App\Services\Admin\AdminSessionService;
use App\Services\Auth\CurrentUser;
use App\Services\Crm\AuditLogger;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Login for the separate platform-admin app. Only users with is_platform_admin = 1 can sign in; everyone else gets the same
 * "Invalid email or password." as a wrong password (no way to learn who is an admin). Sessions are the admin_sessions table,
 * NOT users.api_token, so this never signs anybody out of the customer app.
 *   POST /admin-auth/login {email,password} -> {token,user}     GET /admin-auth/me      POST /admin-auth/logout
 */
class AdminAuthController extends ResourceController
{
    protected $format = 'json';
    private const DUMMY_HASH = '$2y$10$.JUJsn0BkVTCnjk5Cmd0zeIQSxTL85G1RftwZ4k5Cbf5/Vu3X7VfC';   // burns the same time as a real verify when the account is unknown

    public function login(): ResponseInterface
    {
        $email = strtolower(trim((string) $this->request->getJsonVar('email')));
        $pass  = (string) $this->request->getJsonVar('password');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '' || strlen($pass) > 200) { return $this->fail(['message' => 'Enter your email and password.'], 422); }

        // 5 tries per 15 minutes per IP+email, on top of the general API limiter.
        $key = 'adminlogin_' . md5($this->request->getIPAddress() . '|' . $email);
        if (! service('throttler')->check($key, 5, 900)) { return $this->respond(['success' => false, 'message' => 'Too many attempts. Try again in a few minutes.'], 429); }

        $user = (new UserModel())->findByEmail($email);
        $u    = $user === null ? null : (is_array($user) ? $user : (array) $user);
        $ok   = password_verify($pass, $u['password_hash'] ?? self::DUMMY_HASH) && $u !== null && (int) ($u['is_platform_admin'] ?? 0) === 1;
        if (! $ok) {
            if ($u !== null && (int) ($u['is_platform_admin'] ?? 0) === 1) { AuditLogger::log('admin.login_failed', 'user', (int) $u['id'], null, null, (int) $u['tenant_id'], (int) $u['id']); }
            return $this->respond(['success' => false, 'message' => 'Invalid email or password.'], 401);
        }

        $token = (new AdminSessionService())->create((int) $u['id'], $this->request->getIPAddress(), $this->request->getUserAgent()->getAgentString());
        AuditLogger::log('admin.login', 'user', (int) $u['id'], null, null, (int) $u['tenant_id'], (int) $u['id']);
        return $this->respond(['success' => true, 'token' => $token, 'user' => $this->view($u), 'session_hours' => AdminSessionService::ABSOLUTE_HOURS]);
    }

    public function me(): ResponseInterface
    {
        return $this->respond(['success' => true, 'user' => $this->view((array) CurrentUser::get())]);
    }

    public function logout(): ResponseInterface
    {
        $h = $this->request->getHeaderLine('Authorization');
        if (str_starts_with($h, 'Bearer ')) { (new AdminSessionService())->revoke(substr($h, 7)); }
        AuditLogger::log('admin.logout', 'user', CurrentUser::id());
        return $this->respond(['success' => true]);
    }

    private function view(array $u): array
    {
        return ['id' => (int) $u['id'], 'name' => $u['name'] ?? null, 'email' => $u['email'] ?? null, 'is_platform_admin' => true];
    }
}
