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
        'phone',
        'wa_marketing_opt_in',
        'wa_opt_in_at',
        'email_marketing_opt_in',
        'email_opt_in_at',
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

    /** Live sign-ins kept per person (one per device). The oldest is dropped when an 11th is opened. */
    public const MAX_TOKENS = 10;

    public function findByToken(string $rawToken): array|object|null
    {
        $hashed = hash('sha256', $rawToken);
        $db     = db_connect();

        // 1. a per-device token
        $row = $db->table('user_tokens')->select('id, user_id, expires_at, last_used_at')->where('token_hash', $hashed)->get()->getRowArray();
        if ($row !== null) {
            if (strtotime((string) $row['expires_at']) < time()) {
                $db->table('user_tokens')->where('id', (int) $row['id'])->delete();
                return null;
            }
            $user = $this->withoutTenantScope()->find((int) $row['user_id']);
            if ($user === null) { return null; }
            if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at']) < time() - 600) {      // at most one write per 10 minutes
                $db->table('user_tokens')->where('id', (int) $row['id'])->update(['last_used_at' => date('Y-m-d H:i:s')]);
            }
            return $user;
        }

        // 2. a token issued before per-device tokens existed (single users.api_token): still honoured until it expires
        $user = $this->withoutTenantScope()->where('api_token', $hashed)->first();
        if ($user === null) { return null; }
        $expiresAt = is_array($user) ? ($user['api_token_expires_at'] ?? null) : ($user->api_token_expires_at ?? null);
        if ($expiresAt !== null && strtotime($expiresAt) < time()) {
            $userId = is_array($user) ? (int) $user['id'] : (int) $user->id;
            $this->update($userId, ['api_token' => null, 'api_token_expires_at' => null]);
            return null;
        }
        return $user;
    }

    /** Opens a NEW sign-in for this person; their other devices stay signed in. $client: 'web' | 'mobile'. */
    public function setToken(int $userId, string $client = 'web', string $ip = '', string $userAgent = ''): string
    {
        $rawToken  = bin2hex(random_bytes(32));
        $now       = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $this->tokenExpiryDays() . ' days'));
        $db        = db_connect();

        $db->table('user_tokens')->where('expires_at <', $now)->delete();                                  // housekeeping
        $db->table('user_tokens')->insert([
            'user_id' => $userId, 'token_hash' => hash('sha256', $rawToken), 'client' => in_array($client, ['web', 'mobile'], true) ? $client : 'web',
            'ip' => $ip !== '' ? mb_substr($ip, 0, 45) : null, 'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 200) : null,
            'created_at' => $now, 'last_used_at' => $now, 'expires_at' => $expiresAt,
        ]);
        $keep = array_column($db->table('user_tokens')->select('id')->where('user_id', $userId)->orderBy('id', 'DESC')->limit(self::MAX_TOKENS)->get()->getResultArray(), 'id');
        if ($keep) { $db->table('user_tokens')->where('user_id', $userId)->whereNotIn('id', $keep)->delete(); }

        $this->update($userId, ['last_login_at' => $now]);
        return $rawToken;
    }

    /** Signs out ONE device (pass its token) or, with no token, every device of this person (also the pre-migration single token). */
    public function revokeToken(int $userId, ?string $rawToken = null): void
    {
        $db = db_connect();
        if ($rawToken !== null && $rawToken !== '') {
            $hashed = hash('sha256', $rawToken);
            $db->table('user_tokens')->where('user_id', $userId)->where('token_hash', $hashed)->delete();
            $this->builder()->where('id', $userId)->where('api_token', $hashed)->update(['api_token' => null, 'api_token_expires_at' => null]);
            return;
        }
        $db->table('user_tokens')->where('user_id', $userId)->delete();
        $this->update($userId, ['api_token' => null, 'api_token_expires_at' => null]);
    }

    /** Signs out every device of this person EXCEPT the one presented (used after a password change). */
    public function revokeOthers(int $userId, string $keepRawToken): void
    {
        $keep = hash('sha256', $keepRawToken);
        db_connect()->table('user_tokens')->where('user_id', $userId)->where('token_hash !=', $keep)->delete();
        $this->builder()->where('id', $userId)->where('api_token IS NOT NULL', null, false)->where('api_token !=', $keep)->update(['api_token' => null, 'api_token_expires_at' => null]);
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
