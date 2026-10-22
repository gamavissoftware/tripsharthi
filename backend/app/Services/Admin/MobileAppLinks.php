<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Crm\AuditLogger;

/**
 * Where to download the TripSarthi phone app. Nothing is shown anywhere until the team saves a link here: the login screen and the website
 * ask the public endpoint and draw a button only for a link that exists, so "app not ready" simply means "no links saved".
 */
final class MobileAppLinks
{
    public const ANDROID = 'mobile_app_android_url';
    public const IOS     = 'mobile_app_ios_url';

    /** @return array{android:?string, ios:?string} only links that are saved AND still pass the checks */
    public static function links(): array
    {
        $a = PlatformSettings::get(self::ANDROID); $i = PlatformSettings::get(self::IOS);
        return [
            'android' => $a !== null && self::valid($a, false) === null ? $a : null,
            'ios'     => $i !== null && self::valid($i, true) === null ? $i : null,
        ];
    }

    /** What anyone may see: the links and whether the app is available at all. */
    public static function publicView(): array
    {
        $l = self::links();
        return $l + ['available' => $l['android'] !== null || $l['ios'] !== null];
    }

    /** Admin view: same plus how to fill the page in. */
    public static function status(): array { return self::publicView(); }

    /** @return null when fine, else a plain-words reason */
    public static function valid(string $url, bool $ios): ?string
    {
        if (mb_strlen($url) > 300) { return 'That link is too long.'; }
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) { return 'Use a full https:// link (no username or password in it).'; }
        $host = strtolower((string) $p['host']);
        if (! str_contains($host, '.')) { return 'That does not look like a web address.'; }
        if ($ios && ! ($host === 'apple.com' || str_ends_with($host, '.apple.com'))) { return 'The iPhone link must be an Apple link (apps.apple.com or testflight.apple.com).'; }
        return null;
    }

    public static function save(array $in, int $adminId): array
    {
        $before = self::links(); $new = [];
        foreach (['android' => [self::ANDROID, false, 'Android'], 'ios' => [self::IOS, true, 'iPhone']] as $field => [$key, $ios, $label]) {
            if (! array_key_exists($field, $in)) { continue; }
            $v = trim((string) $in[$field]);
            if ($v !== '' && ($why = self::valid($v, $ios)) !== null) { throw new \InvalidArgumentException("{$label}: {$why}"); }
            $new[$key] = $v === '' ? null : $v;
        }
        foreach ($new as $key => $v) { PlatformSettings::set($key, $v, $adminId); }
        $after = self::links();
        if ($before !== $after) { AuditLogger::log('admin.mobile_app.save', 'platform', 0, $before, $after, null, $adminId); }
        return self::status();
    }
}
