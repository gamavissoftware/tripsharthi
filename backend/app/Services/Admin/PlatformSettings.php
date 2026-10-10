<?php

declare(strict_types=1);

namespace App\Services\Admin;

/** Tiny key/value store for the TripSarthi team's own settings (not a customer workspace setting). */
final class PlatformSettings
{
    public const MARKETING_TENANT = 'marketing_tenant_id';

    public static function get(string $key, ?string $default = null): ?string
    {
        $r = db_connect()->table('platform_settings')->select('value')->where('setting_key', $key)->get()->getRowArray();
        return $r === null ? $default : $r['value'];
    }

    public static function set(string $key, ?string $value, ?int $adminId = null): void
    {
        $db = db_connect(); $now = date('Y-m-d H:i:s');
        if ($db->table('platform_settings')->where('setting_key', $key)->countAllResults() > 0) {
            $db->table('platform_settings')->where('setting_key', $key)->update(['value' => $value, 'updated_by' => $adminId, 'updated_at' => $now]);
        } else {
            $db->table('platform_settings')->insert(['setting_key' => $key, 'value' => $value, 'updated_by' => $adminId, 'updated_at' => $now]);
        }
    }
}
