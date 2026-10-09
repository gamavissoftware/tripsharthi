<?php

declare(strict_types=1);

namespace App\Services\Licensing;

/**
 * Immutable result of LicenseService::checkStatus().
 *
 * Callers (LicenseFilter, FeatureGate::isReadOnly) pattern-match on
 * isReadOnly — they never read the database or touch network themselves.
 */
final class LicenseStatus
{
    public function __construct(
        /** True if the license is considered valid (active or within grace). */
        public readonly bool   $isActive,
        /** True if write operations should be blocked (grace expired or no license). */
        public readonly bool   $isReadOnly,
        /** Days remaining in the offline grace window. 0 when not in grace. */
        public readonly int    $graceDaysLeft,
        /** Machine-readable reason string — for logging and the status API. */
        public readonly string $reason,
    ) {}

    // ── Named constructors ───────────────────────────────────────────────

    public static function active(): self
    {
        return new self(true, false, 0, 'ok');
    }

    /**
     * Phone-home failed but we are within the offline grace window.
     * Writes are still allowed; a warning is logged by the cron.
     */
    public static function withinGrace(int $daysLeft): self
    {
        return new self(true, false, $daysLeft, 'within_grace');
    }

    /**
     * Grace period has expired — system must enter read-only mode.
     * GETs still pass; writes return 503.
     */
    public static function graceExpired(): self
    {
        return new self(true, true, 0, 'grace_expired');
    }

    /** No license row, invalid signature, or revoked — hard read-only. */
    public static function readOnly(string $reason): self
    {
        return new self(false, true, 0, $reason);
    }
}
