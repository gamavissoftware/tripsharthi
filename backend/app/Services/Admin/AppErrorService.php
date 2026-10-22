<?php

declare(strict_types=1);

namespace App\Services\Admin;

/** Stores and lists errors reported by the phone app. Never trusts the sender: sizes are capped and everything is kept as text. */
final class AppErrorService
{
    public const KEEP_DAYS = 60;

    /** @param array<string,mixed> $in @return bool false when the report was refused as junk */
    public static function record(array $in, ?int $userId, ?int $tenantId, string $ip): bool
    {
        $msg = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) ($in['message'] ?? '')));
        if ($msg === '') { return false; }
        $ver = preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', (string) ($in['version'] ?? '')) ? (string) $in['version'] : null;
        $db  = db_connect(); $now = date('Y-m-d H:i:s');
        $db->table('app_errors')->insert([
            'user_id' => $userId, 'tenant_id' => $tenantId,
            'platform' => in_array($in['platform'] ?? '', ['android', 'ios'], true) ? $in['platform'] : 'android',
            'app_version' => $ver, 'screen' => mb_substr(trim((string) ($in['screen'] ?? '')), 0, 60) ?: null,
            'message' => mb_substr($msg, 0, 300), 'stack' => mb_substr((string) ($in['stack'] ?? ''), 0, 4000) ?: null,
            'ip_hash' => $ip !== '' ? substr(hash('sha256', $ip . '|' . (string) env('encryption.key', 'tripsarthi')), 0, 32) : null, 'created_at' => $now,
        ]);
        if (random_int(1, 25) === 1) { $db->table('app_errors')->where('created_at <', date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400))->delete(); }   // housekeeping
        return true;
    }

    /** Newest first, grouped by message + version with a count so a crash seen 40 times is one line. */
    public static function recent(int $limit = 40): array
    {
        $rows = db_connect()->query(
            'SELECT message, app_version, platform, MAX(screen) AS screen, COUNT(*) AS times, COUNT(DISTINCT COALESCE(user_id, 0)) AS people, MAX(created_at) AS last_at, SUBSTRING_INDEX(GROUP_CONCAT(stack ORDER BY id DESC SEPARATOR "\n§§\n"), "\n§§\n", 1) AS stack
             FROM app_errors WHERE created_at >= ? GROUP BY message, app_version, platform ORDER BY last_at DESC LIMIT ' . max(1, min(100, $limit)),
            [date('Y-m-d H:i:s', time() - 14 * 86400)]
        )->getResultArray();
        return array_map(static fn ($r) => array_merge($r, ['times' => (int) $r['times'], 'people' => (int) $r['people']]), $rows);
    }
}
