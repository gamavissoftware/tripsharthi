<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\TenantModel;
use App\Models\UserModel;
use App\Services\Auth\CurrentUser;
use App\Services\Email\EmailService;
use App\Services\Tenancy\FeatureGate;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Auth endpoints.
 *
 * POST /api/v1/auth/login      — exchange email+password for a Bearer token
 * POST /api/v1/auth/register   — create tenant + owner (saas only, gated)
 * POST /api/v1/auth/logout     — revoke the current token  [requires auth]
 * GET  /api/v1/auth/me         — return the current user   [requires auth]
 */
class AuthController extends ResourceController
{
    protected $format = 'json';

    // ------------------------------------------------------------------
    // POST /api/v1/auth/login
    // ------------------------------------------------------------------

    public function login(): ResponseInterface
    {
        $rules = [
            'email'    => 'required|valid_email',
            'password' => 'required|min_length[6]',
        ];

        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $email    = $this->request->getJsonVar('email');
        $password = $this->request->getJsonVar('password');

        $userModel = new UserModel();
        $user      = $userModel->findByEmail($email);

        if ($user === null || ! password_verify($password, is_array($user) ? $user['password_hash'] : $user->password_hash)) {
            return $this->failUnauthorized('Invalid email or password.');
        }

        $userId   = (int) (is_array($user) ? $user['id'] : $user->id);
        $rawToken = $userModel->setToken($userId);

        return $this->respond([
            'success' => true,
            'token'   => $rawToken,
            'user'    => $this->safeUser($user),
        ]);
    }

    // ------------------------------------------------------------------
    // POST /api/v1/auth/register  (saas only)
    // ------------------------------------------------------------------

    public function register(): ResponseInterface
    {
        if (! FeatureGate::isRegistrationOpen()) {
            return $this->failForbidden('Registration is disabled in self-hosted mode.');
        }

        $rules = [
            'name'     => 'required|max_length[255]',
            'email'    => 'required|valid_email|max_length[255]',
            'password' => 'required|min_length[8]',
            'company'  => 'required|max_length[255]',
        ];

        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $email   = $this->request->getJsonVar('email');
        $name    = $this->request->getJsonVar('name');
        $company = $this->request->getJsonVar('company');
        $password = $this->request->getJsonVar('password');

        $userModel   = new UserModel();
        $tenantModel = new TenantModel();

        // Check if email already exists (globally — email must be unique across all tenants for login)
        if ($userModel->findByEmail($email) !== null) {
            return $this->fail(['email' => 'An account with this email already exists.'], 409);
        }

        // Create tenant
        $slug     = $this->makeSlug($company, $tenantModel);
        $tenantId = $tenantModel->insert([
            'name'   => $company,
            'slug'   => $slug,
            'plan'   => 'free',
            'status' => 'active',
            'mode'   => 'saas',
        ], true);

        if (! $tenantId) {
            return $this->failServerError('Failed to create organisation.');
        }

        // Create owner user
        $userId = $userModel->insert([
            'tenant_id'     => $tenantId,
            'name'          => $name,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => 'owner',
        ], true);

        if (! $userId) {
            $tenantModel->delete($tenantId, true);
            return $this->failServerError('Failed to create user.');
        }

        $rawToken = $userModel->setToken((int) $userId);
        $user     = $userModel->setTenant((int) $tenantId)->find((int) $userId);

        // Send welcome email (fire-and-forget — don't fail registration if email fails)
        $appUrl = EmailService::appUrl();
        EmailService::sendWelcome($email, $name, $appUrl);

        return $this->respondCreated([
            'success' => true,
            'token'   => $rawToken,
            'user'    => $this->safeUser($user),
        ]);
    }

    // ------------------------------------------------------------------
    // POST /api/v1/auth/logout   [auth required]
    // ------------------------------------------------------------------

    public function logout(): ResponseInterface
    {
        $userModel = new UserModel();
        $userModel->revokeToken(CurrentUser::id());
        CurrentUser::reset();

        return $this->respond(['success' => true, 'message' => 'Logged out.']);
    }

    // ------------------------------------------------------------------
    // GET /api/v1/auth/me   [auth required]
    // ------------------------------------------------------------------

    public function me(): ResponseInterface
    {
        return $this->respond([
            'success' => true,
            'user'    => $this->safeUser(CurrentUser::get()),
        ]);
    }

    // ------------------------------------------------------------------
    // PUT /api/v1/auth/profile   [auth required]
    // ------------------------------------------------------------------

    public function updateProfile(): ResponseInterface
    {
        $rules = [
            'name'  => 'permit_empty|max_length[255]',
            'email' => 'permit_empty|valid_email|max_length[255]',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $userId    = CurrentUser::id();
        $userModel = new UserModel();
        $payload   = array_filter([
            'name'  => $this->request->getJsonVar('name'),
            'email' => $this->request->getJsonVar('email'),
        ], static fn($v) => $v !== null && $v !== '');

        if (! empty($payload['email'])) {
            // Ensure email is not already taken by another user
            $existing = $userModel->findByEmail($payload['email']);
            $existingId = $existing ? (int)(is_array($existing) ? $existing['id'] : $existing->id) : 0;
            if ($existingId !== 0 && $existingId !== $userId) {
                return $this->fail(['email' => 'That email is already in use.'], 409);
            }
        }

        if (! empty($payload)) {
            $userModel->withoutTenantScope()->update($userId, $payload);
        }

        // Re-read by the authenticated user's own id (cross-tenant-safe: the id
        // comes from the session, not user input). Matches changePassword().
        return $this->respond([
            'success' => true,
            'user'    => $this->safeUser($userModel->withoutTenantScope()->find($userId)),
        ]);
    }

    // ------------------------------------------------------------------
    // PUT /api/v1/auth/change-password   [auth required]
    // ------------------------------------------------------------------

    public function changePassword(): ResponseInterface
    {
        $rules = [
            'current_password' => 'required',
            'new_password'     => 'required|min_length[8]',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $userId    = CurrentUser::id();
        $userModel = new UserModel();
        $user      = $userModel->withoutTenantScope()->find($userId);

        $currentHash = is_array($user) ? $user['password_hash'] : $user->password_hash;
        if (! password_verify($this->request->getJsonVar('current_password'), $currentHash)) {
            return $this->fail(['current_password' => 'Current password is incorrect.'], 422);
        }

        $userModel->update($userId, [
            'password_hash' => password_hash($this->request->getJsonVar('new_password'), PASSWORD_DEFAULT),
        ]);

        return $this->respond(['success' => true, 'message' => 'Password updated successfully.']);
    }

    // ------------------------------------------------------------------
    // POST /api/v1/auth/forgot-password  (public)
    // ------------------------------------------------------------------

    public function forgotPassword(): ResponseInterface
    {
        $rules = ['email' => 'required|valid_email'];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $email     = trim((string) $this->request->getJsonVar('email'));
        $userModel = new UserModel();
        $rawToken  = $userModel->createResetToken($email);

        // Always respond success — never reveal whether email exists (user enumeration defence)
        if ($rawToken !== null) {
            $user      = $userModel->findByEmail($email);
            $name      = is_array($user) ? ($user['name'] ?? 'there') : ($user->name ?? 'there');
            $appUrl    = EmailService::appUrl();
            $resetUrl  = $appUrl . '/#/reset-password?token=' . urlencode($rawToken);

            EmailService::sendPasswordReset($email, $name, $resetUrl);
        }

        return $this->respond([
            'success' => true,
            'message' => 'If that email exists, a reset link has been sent.',
        ]);
    }

    // ------------------------------------------------------------------
    // POST /api/v1/auth/reset-password  (public)
    // ------------------------------------------------------------------

    public function resetPassword(): ResponseInterface
    {
        $rules = [
            'token'    => 'required|min_length[10]',
            'password' => 'required|min_length[8]',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $rawToken  = trim((string) $this->request->getJsonVar('token'));
        $password  = (string) $this->request->getJsonVar('password');
        $userModel = new UserModel();
        $user      = $userModel->consumeResetToken($rawToken);

        if ($user === null) {
            return $this->fail('Invalid or expired reset link. Please request a new one.', 422);
        }

        $userId = is_array($user) ? (int) $user['id'] : (int) $user->id;
        $userModel->update($userId, [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'api_token'     => null, // invalidate all existing sessions
        ]);

        return $this->respond([
            'success' => true,
            'message' => 'Password updated. You can now log in.',
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function safeUser(array|object|null $user): array
    {
        if ($user === null) {
            return [];
        }

        $u = is_array($user) ? $user : (array) $user;

        return [
            'id'        => $u['id'] ?? null,
            'tenant_id' => $u['tenant_id'] ?? null,
            'name'      => $u['name'] ?? null,
            'email'     => $u['email'] ?? null,
            'role'      => $u['role'] ?? null,
        ];
    }

    private function makeSlug(string $company, TenantModel $tenantModel): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $company));
        $base = trim($base, '-');
        $slug = $base;
        $i    = 1;

        while ($tenantModel->findBySlug($slug) !== null) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
