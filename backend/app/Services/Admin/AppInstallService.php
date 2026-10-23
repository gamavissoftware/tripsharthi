<?php

declare(strict_types=1);

namespace App\Services\Admin;

/** Which versions of the phone app are really in use. The phone says hello when it opens (at most every few hours); the admin app reads the summary. */
final class AppInstallService
{
    /** @param array<string,mixed> $in @return bool false when the hello was refused as junk */
    public static function ping(int $userId, int $tenantId, array $in): bool
    {
        $id = (string) ($in['install_id'] ?? '');
        $ver = (string) ($in['app_version'] ?? '');
        if (! preg_match('/^[A-Za-z0-9-]{12,40}$/', $id) || ! preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $ver)) { return false; }
        $clean = static fn ($v, int $n) => ($s = mb_substr(trim(preg_replace('/[\x00-\x1F]/u', '', (string) $v)), 0, $n)) === '' ? null : $s;
        $row = [
            'tenant_id' => $tenantId, 'platform' => in_array($in['platform'] ?? '', ['android', 'ios'], true) ? $in['platform'] : 'android', 'app_version' => $ver,
            'runtime_version' => preg_match('/^[A-Za-z0-9._-]{1,20}$/', (string) ($in['runtime_version'] ?? '')) ? (string) $in['runtime_version'] : null,
            'update_id' => preg_match('/^[a-f0-9-]{4,12}$/i', (string) ($in['update_id'] ?? '')) ? (string) $in['update_id'] : null,
            'device_name' => $clean($in['device_name'] ?? '', 120), 'last_seen_at' => date('Y-m-d H:i:s'),
        ];
        $db = db_connect();
        $ex = $db->table('app_installs')->select('id')->where('user_id', $userId)->where('install_id', $id)->get()->getRowArray();
        if ($ex) { $db->table('app_installs')->where('id', $ex['id'])->update($row); }
        else { $db->table('app_installs')->insert($row + ['user_id' => $userId, 'install_id' => $id, 'first_seen_at' => $row['last_seen_at']]); }
        return true;
    }

    /**
     * What the admin sees: people who opened the app in the last 30 days, by version, how many are behind the newest, and the latest installs.
     * @return array<string,mixed>
     */
    public static function summary(?string $latest, int $days = 30): array
    {
        $db = db_connect();
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $rows = $db->query('SELECT i.id, i.app_version, i.runtime_version, i.update_id, i.platform, i.device_name, i.first_seen_at, i.last_seen_at, u.name AS user_name, t.name AS workspace
                              FROM app_installs i LEFT JOIN users u ON u.id = i.user_id LEFT JOIN tenants t ON t.id = i.tenant_id
                             WHERE i.last_seen_at >= ? ORDER BY i.last_seen_at DESC LIMIT 500', [$since])->getResultArray();
        $by = []; $behind = 0; $day = 0; $dayCut = date('Y-m-d H:i:s', time() - 86400);
        foreach ($rows as $r) {
            $v = (string) $r['app_version']; $by[$v] = ($by[$v] ?? 0) + 1;
            if ($latest !== null && version_compare($v, $latest, '<')) { $behind++; }
            if ($r['last_seen_at'] >= $dayCut) { $day++; }
        }
        uksort($by, static fn ($a, $b) => version_compare($b, $a));
        $versions = []; foreach ($by as $v => $n) { $versions[] = ['version' => $v, 'phones' => $n, 'behind' => $latest !== null && version_compare($v, $latest, '<')]; }
        return ['days' => $days, 'phones' => count($rows), 'active_24h' => $day, 'behind' => $behind, 'latest' => $latest, 'versions' => $versions, 'recent' => array_slice($rows, 0, 30)];
    }
}
