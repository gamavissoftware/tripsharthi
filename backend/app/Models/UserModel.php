<?php

declare(strict_types=1);

namespace App\Models;

/**
 * User model — tenant-scoped via BaseModel.
 *
 * Passwords must be hashed before insert; api_token stores the SHA-256
 * hash of the raw token (raw token is returned to the client once at login
 * and never stored in plaintext).
 */
class UserModel extends BaseModel
{
    protected $table      = 'users';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id',
        'name',
        'email',
        'password_hash',
        'role',
        'api_token',
        'api_token_expires_at',
        'reset_token',
        'reset_token_expires_at',
        'last_login_at',
    ];

    protected $hidden = ['password_hash', 'api_token'];

    protected $validationRules = [
        'name'  => 'required|max_length[255]',
        'email' => 'required|valid_email|max_length[255]',
        'role'  => 'in_list[owner,admin,agent]',
    ];

    /**
     * Find user by email, globally (used during login — tenant not yet known).
     * Bypasses tenant scope intentionally.
     */
    public function findByEmail(string $email, int $tenantId = 0): array|object|null
    {
        $this->withoutTenantScope();
        $builder = $this->where('email', $email);
        if ($tenantId > 0) {
            $builder->where('tenant_id', $tenantId);
        }
        return $builder->first();
    }

    /**
     * Find user by raw Bearer token (global — tenant unknown at this point).
     * Bypasses tenant scope intentionally.
     */
    /** Token lifetime — 90 days. Configurable via TOKEN_EXPIRY_DAYS env. */
    private function tokenExpiryDays(): int
    {
        return (int) (env('TOKEN_EXPIRY_DAYS', 90) ?: 90);
    }

    public function findByToken(string $rawToken): array|object|null
    {
        $hashed = hash('sha256', $rawToken);
        $user   = $this->withoutTenantScope()
                       ->where('api_token', $hashed)
                       ->first();

        if ($user === null) {
            return null;
        }

        // Enforce expiry if the column is populated (old tokens without expiry still work)
        $expiresAt = is_array($user) ? ($user['api_token_expires_at'] ?? null) : ($user->api_token_expires_at ?? null);
        if ($expiresAt !== null && strtotime($expiresAt) < time()) {
            // Token expired — clear it and return null
            $userId = is_array($user) ? (int) $user['id'] : (int) $user->id;
            $this->update($userId, ['api_token' => null, 'api_token_expires_at' => null]);
            return null;
        }

        return $user;
    }

    public function setToken(int $userId): string
    {
        $rawToken  = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $this->tokenExpiryDays() . ' days'));
        $this->update($userId, [
            'api_token'            => hash('sha256', $rawToken),
            'api_token_expires_at' => $expiresAt,
            'last_login_at'        => date('Y-m-d H:i:s'),
        ]);
        return $rawToken;
    }

    public function revokeToken(int $userId): void
    {
        $this->update($userId, ['api_token' => null, 'api_token_expires_at' => null]);
    }

    // ── Password reset ────────────────────────────────────────────────

    /** Generate a reset token, store it, and return the RAW token for emailing. */
    public function createResetToken(string $email): ?string
    {
        $user = $this->findByEmail($email);
        if ($user === null) {
            return null; // silently ignore unknown emails (security: no user enumeration)
        }

        $rawToken  = bin2hex(random_bytes(32));
        $userId    = is_array($user) ? (int) $user['id'] : (int) $user->id;
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->update($userId, [
            'reset_token'            => hash('sha256', $rawToken),
            'reset_token_expires_at' => $expiresAt,
        ]);

        return $rawToken;
    }

    /** Validate and consume a reset token. Returns the user row or null. */
    public function consumeResetToken(string $rawToken): array|object|null
    {
        $hashed = hash('sha256', $rawToken);
        $user   = $this->withoutTenantScope()
                       ->where('reset_token', $hashed)
                       ->where('reset_token_expires_at >', date('Y-m-d H:i:s'))
                       ->first();

        if ($user === null) {
            return null;
        }

        // Immediately invalidate so token can't be reused
        $userId = is_array($user) ? (int) $user['id'] : (int) $user->id;
        $this->update($userId, [
            'reset_token'            => null,
            'reset_token_expires_at' => null,
        ]);

        return $user;
    }
}
