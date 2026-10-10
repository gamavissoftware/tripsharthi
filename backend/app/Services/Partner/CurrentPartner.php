<?php

declare(strict_types=1);

namespace App\Services\Partner;

/** The signed-in partner for this request (set by PartnerAuthFilter) - the partner-portal counterpart of CurrentUser. */
final class CurrentPartner
{
    private static ?array $partner = null;

    public static function set(array $p): void { self::$partner = $p; }
    public static function get(): ?array { return self::$partner; }
    public static function id(): int { return (int) (self::$partner['id'] ?? 0); }
    public static function reset(): void { self::$partner = null; }
}
