<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Normalises an optional foreign key arriving from a JSON request body.
 *
 * A <select> whose empty option means "Unassigned" posts '' — not null. Guards
 * written as `$v === null` or `array_filter($a, fn($v) => $v !== null)` let that
 * '' through, and MySQL then coerces it to 0 on an INT column. There is no user
 * or account #0, so the foreign key fails and the request dies as a bare 500.
 *
 * That is exactly what made "save contact with no agent assigned" return 500.
 * The same shape exists wherever an optional owner, account or parent record is
 * taken straight from the request, which is why this lives in one place.
 */
final class OptionalId
{
    /**
     * @return int|null null when the caller meant "not set"
     */
    public static function from(mixed $value): ?int
    {
        if ($value === null || $value === 0 || $value === '0') {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        // A negative or zero id can only be junk; treat it as absent rather
        // than letting the database reject the whole write.
        return $id > 0 ? $id : null;
    }

    /**
     * Optional decimal/amount: '' means "not provided", not zero.
     */
    public static function amount(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '' || ! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }
}
