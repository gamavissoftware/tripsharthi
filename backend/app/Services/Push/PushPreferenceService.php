<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\PushPreferenceModel;

/** A user's push settings + the rules that apply them. The decision logic is pure and unit-tested. */
final class PushPreferenceService
{
    public function get(int $tenantId, int $userId): array
    {
        $r = (new PushPreferenceModel())->setTenant($tenantId)->where('user_id', $userId)->first();
        $stored = $r && $r['categories'] ? (json_decode((string) $r['categories'], true) ?: []) : [];
        return [
            'enabled'     => (bool) ($r['enabled'] ?? true),
            'categories'  => array_intersect_key($stored + PushCategory::defaults(), PushCategory::ALL),
            'quiet_start' => $r['quiet_start'] ?? null,
            'quiet_end'   => $r['quiet_end'] ?? null,
            'privacy'     => $r['privacy'] ?? 'full',
        ];
    }

    public function save(int $tenantId, int $userId, array $in): array
    {
        $cur = $this->get($tenantId, $userId);
        $cats = $cur['categories'];
        foreach ((array) ($in['categories'] ?? []) as $k => $v) {
            if (PushCategory::valid((string) $k)) { $cats[(string) $k] = (bool) $v; }
        }
        $q = static fn ($v) => (is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) ? $v : null;
        $start = array_key_exists('quiet_start', $in) ? $q($in['quiet_start']) : $cur['quiet_start'];
        $end   = array_key_exists('quiet_end', $in) ? $q($in['quiet_end']) : $cur['quiet_end'];
        if (($start === null) !== ($end === null)) { throw new \InvalidArgumentException('Set both a start and an end for quiet hours, or neither.'); }
        $data = ['enabled' => array_key_exists('enabled', $in) ? (int) (bool) $in['enabled'] : (int) $cur['enabled'], 'categories' => json_encode($cats), 'quiet_start' => $start, 'quiet_end' => $end,
            'privacy' => in_array($in['privacy'] ?? $cur['privacy'], ['full', 'minimal'], true) ? ($in['privacy'] ?? $cur['privacy']) : 'full'];
        $row = (new PushPreferenceModel())->setTenant($tenantId)->where('user_id', $userId)->first();
        $row ? (new PushPreferenceModel())->setTenant($tenantId)->update((int) $row['id'], $data) : (new PushPreferenceModel())->setTenant($tenantId)->insert($data + ['user_id' => $userId]);
        return $this->get($tenantId, $userId);
    }

    /** @return string|null why a push must NOT be sent right now (null = allowed) */
    public static function blockedReason(array $pref, string $category, int $nowTs, string $role = 'agent'): ?string
    {
        if (! $pref['enabled']) { return 'notifications_off'; }
        if (empty($pref['categories'][$category])) { return 'category_off'; }
        if ((PushCategory::ALL[$category]['staff_only'] ?? false) && ! in_array($role, ['owner', 'admin'], true)) { return 'staff_only'; }
        if (self::inQuietHours($pref['quiet_start'], $pref['quiet_end'], $nowTs)) { return 'quiet_hours'; }
        return null;
    }

    /** Quiet hours in IST; a window may wrap midnight (22:00–07:00). */
    public static function inQuietHours(?string $start, ?string $end, int $nowTs): bool
    {
        if ($start === null || $end === null || $start === $end) { return false; }
        $now = (new \DateTimeImmutable('@' . $nowTs))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('H:i');
        return $start < $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }
}
