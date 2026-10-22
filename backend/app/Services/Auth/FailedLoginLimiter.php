<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Brute-force guard for sign-in screens that counts only FAILED attempts and forgets them after a success.
 * The generic throttler counts every request, so a person who signed in successfully five times in a quarter of an hour
 * (phone + laptop + a mistyped password) was locked out of their own account.
 */
final class FailedLoginLimiter
{
    public function __construct(private readonly int $maxFailures = 5, private readonly int $windowSeconds = 900) {}

    private static function k(string $key): string { return 'fl_' . md5($key); }

    public function blocked(string $key): bool
    {
        return (int) (cache()->get(self::k($key)) ?? 0) >= $this->maxFailures;
    }

    public function fail(string $key): void
    {
        $n = (int) (cache()->get(self::k($key)) ?? 0);
        cache()->save(self::k($key), $n + 1, $this->windowSeconds);
    }

    public function clear(string $key): void
    {
        cache()->delete(self::k($key));
    }
}
