<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\TenantModel;

/**
 * Currency display helpers (Phase J4). Formatting only — NO live FX, currencies
 * are never converted or mixed. Money is stored in the minor unit (paise/cents).
 */
final class CurrencyService
{
    public const SYMBOL = [
        'INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ',
        'AUD' => 'A$', 'SGD' => 'S$', 'CAD' => 'C$', 'JPY' => '¥',
    ];

    /** Currencies with no minor unit (amount stored ×1, not ×100). */
    private const ZERO_DECIMAL = ['JPY'];

    public static function codes(): array
    {
        return array_keys(self::SYMBOL);
    }

    public static function symbol(string $code): string
    {
        return self::SYMBOL[strtoupper($code)] ?? (strtoupper($code) . ' ');
    }

    /** Format a minor-unit amount in the given currency. */
    public static function format(int $minor, string $code): string
    {
        $code = strtoupper($code);
        $dec  = in_array($code, self::ZERO_DECIMAL, true) ? 0 : 2;
        return self::symbol($code) . number_format($minor / 100, $dec);
    }

    /** The tenant's configured default currency (settings.default_currency), else INR. */
    public static function tenantDefault(int $tenantId): string
    {
        $tenant   = (new TenantModel())->find($tenantId);
        $settings = $tenant ? (json_decode($tenant['settings'] ?? '{}', true) ?: []) : [];
        $code     = strtoupper((string) ($settings['default_currency'] ?? 'INR'));
        return isset(self::SYMBOL[$code]) ? $code : 'INR';
    }
}
