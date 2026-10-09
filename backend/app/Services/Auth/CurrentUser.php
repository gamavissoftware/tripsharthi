<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Request-scoped holder for the authenticated user and their tenant.
 *
 * Set once by AuthFilter at the start of the request; read by controllers
 * and services throughout the same request lifecycle.
 *
 * Safe as static state in PHP's share-nothing request model.
 */
class CurrentUser
{
    private static array|object|null $user   = null;
    private static int $tenantId             = 0;
    private static string $tenantPlan        = 'free';
    private static ?string $recordVisibility = null;

    public static function set(array|object $user): void
    {
        self::$user     = $user;
        self::$tenantId = (int) (is_array($user) ? ($user['tenant_id'] ?? 0) : ($user->tenant_id ?? 0));
        self::$tenantPlan = is_array($user) ? ($user['tenant_plan'] ?? 'free') : ($user->tenant_plan ?? 'free');
        self::$recordVisibility = null; // reload lazily for the new user's tenant
    }

    public static function get(): array|object|null
    {
        return self::$user;
    }

    public static function id(): int
    {
        return (int) (is_array(self::$user) ? (self::$user['id'] ?? 0) : (self::$user->id ?? 0));
    }

    public static function tenantId(): int
    {
        return self::$tenantId;
    }

    public static function tenantPlan(): string
    {
        return self::$tenantPlan;
    }

    /** Role on the users table: owner | admin | agent. Empty when unauthenticated. */
    public static function role(): string
    {
        if (self::$user === null) {
            return '';
        }
        return (string) (is_array(self::$user) ? (self::$user['role'] ?? '') : (self::$user->role ?? ''));
    }

    /** owner/admin bypass record-level visibility; agents are scoped. */
    public static function isPrivileged(): bool
    {
        return in_array(self::role(), ['owner', 'admin'], true);
    }

    /**
     * The acting tenant's record-visibility mode (open|owner|team). Loaded once
     * per request and cached; 'open' when unauthenticated. Reset clears it.
     */
    public static function recordVisibility(): string
    {
        if (self::$recordVisibility === null) {
            if (self::$tenantId <= 0) {
                return 'open';
            }
            $row = db_connect()->table('tenants')->select('record_visibility')
                ->where('id', self::$tenantId)->get()->getRowArray();
            self::$recordVisibility = $row['record_visibility'] ?? 'open';
        }
        return self::$recordVisibility;
    }

    public static function isAuthenticated(): bool
    {
        return self::$user !== null;
    }

    /** Reset — for testing only. */
    public static function reset(): void
    {
        self::$user             = null;
        self::$tenantId         = 0;
        self::$tenantPlan       = 'free';
        self::$recordVisibility = null;
    }
}
