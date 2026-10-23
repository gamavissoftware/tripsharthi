<?php

declare(strict_types=1);

namespace App\Services\Admin;

/**
 * Safety net for over-the-air app updates. A broken update (for example one that cannot reach our server) can never tell us it is broken: the phones
 * that installed it simply stop reporting in. So we compare: phones that were using the app in the week BEFORE the newest update was published
 * against phones that have reported SINCE. If a day after publishing half or more of them are silent, the admin app and the Overview warn.
 * The publish time comes from Expo's public manifest endpoint (the same one the phones ask); if it cannot be read the answer is "unknown", never a false alarm.
 */
final class AppUpdateWatch
{
    public const CHANNELS = ['preview', 'production'];
    private const PROJECT = '2c64be72-530c-41f8-b620-3ec38b6932c7';

    /**
     * Pure rule. $phones = list of ['first' => ts, 'last' => ts] for phones of the runtime the update targets.
     * @param list<array{first:int,last:int}> $phones
     * @return array{state:string,silent:int,reported:int,before:int,age_hours:int,message:string}
     */
    public static function evaluate(array $phones, int $publishedAt, int $now): array
    {
        $before = 0; $reported = 0;
        foreach ($phones as $p) {
            if ($p['first'] >= $publishedAt) { continue; }               // installed after the update: tells us nothing
            if ($p['last'] >= $publishedAt) { $before++; $reported++; }
            elseif ($p['last'] >= $publishedAt - 7 * 86400) { $before++; }
        }
        $silent = $before - $reported; $age = max(0, intdiv($now - $publishedAt, 3600));
        $r = ['silent' => $silent, 'reported' => $reported, 'before' => $before, 'age_hours' => $age];
        if ($before === 0) { return $r + ['state' => 'ok', 'message' => 'No phone was using the app in the week before the newest update, so there is nothing to compare yet.']; }
        if ($silent === 0) { return $r + ['state' => 'ok', 'message' => "All {$before} phone(s) that were using the app before the newest update have reported in since."]; }
        if ($age < 24) { return $r + ['state' => 'watching', 'message' => "The newest update went out {$age} h ago. {$silent} of {$before} phone(s) have not opened the app since; phones usually do within a day, so this is being watched."]; }
        if ($silent * 2 >= $before) {
            return $r + ['state' => 'warn', 'message' => "{$silent} of {$before} phone(s) that used the app before the newest update (published {$age} h ago) have not reported in since. If you published an update, open the app on your own phone now: a broken update cannot connect to us. To undo it run `eas update:rollback` (see mobile/RELEASE.md)."];
        }
        return $r + ['state' => 'ok', 'message' => "{$reported} of {$before} phone(s) reported in since the newest update; {$silent} have not opened the app yet."];
    }

    /** Newest update for a runtime/channel from Expo's public manifest endpoint, or null. @return array{id:string,created_at:int}|null */
    public static function fetchManifest(string $runtime, string $channel): ?array
    {
        $project = (string) (getenv('EXPO_PROJECT_ID') ?: self::PROJECT);
        $ch = curl_init("https://u.expo.dev/{$project}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => [
            "expo-runtime-version: {$runtime}", 'expo-platform: android', 'expo-protocol-version: 1', "expo-channel-name: {$channel}", 'accept: multipart/mixed,application/expo+json']]);
        $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if (! is_string($body) || $code !== 200 || ! preg_match('/"createdAt"\s*:\s*"([^"]+)"/', $body, $c) || ! preg_match('/"id"\s*:\s*"([0-9a-f-]{36})"/', $body, $i)) { return null; }
        $ts = strtotime($c[1]);
        return $ts ? ['id' => $i[1], 'created_at' => $ts] : null;
    }

    /**
     * @param (callable(string,string):?array)|null $fetch injectable for tests
     * @return array<string,mixed> state ok|watching|warn|unknown plus the numbers and a plain-words message
     */
    public static function status(?callable $fetch = null, ?int $now = null): array
    {
        $now ??= time();
        if ($fetch === null && ENVIRONMENT === 'testing') { return ['state' => 'unknown', 'message' => 'Not checked in tests.']; }
        $cache = $fetch === null ? cache('app_update_watch') : null;
        if (is_array($cache)) { return $cache; }
        try {
            $rows = db_connect()->query("SELECT runtime_version, UNIX_TIMESTAMP(first_seen_at) AS f, UNIX_TIMESTAMP(last_seen_at) AS l FROM app_installs WHERE runtime_version IS NOT NULL AND last_seen_at >= ?", [date('Y-m-d H:i:s', $now - 60 * 86400)])->getResultArray();
            $fetch ??= [self::class, 'fetchManifest'];
            $by = [];
            foreach ($rows as $r) { $by[(string) $r['runtime_version']][] = ['first' => (int) $r['f'], 'last' => (int) $r['l']]; }
            $worst = null; $order = ['unknown' => 0, 'ok' => 1, 'watching' => 2, 'warn' => 3];
            foreach ($by as $runtime => $phones) {
                $newest = null;
                foreach (self::CHANNELS as $channel) { $m = $fetch($runtime, $channel); if ($m && (! $newest || $m['created_at'] > $newest['created_at'])) { $newest = $m; } }
                if (! $newest) { continue; }
                $e = self::evaluate($phones, $newest['created_at'], $now) + ['runtime' => $runtime, 'update_id' => substr($newest['id'], 0, 8), 'published_at' => date('Y-m-d H:i:s', $newest['created_at'])];
                if ($worst === null || $order[$e['state']] > $order[$worst['state']]) { $worst = $e; }
            }
            $out = $worst ?? ['state' => 'unknown', 'message' => 'No phone has reported in yet, or the update server could not be read.'];
        } catch (\Throwable $e) {
            log_message('warning', 'app update watch: ' . $e->getMessage());
            $out = ['state' => 'unknown', 'message' => 'Could not be checked right now.'];
        }
        if (func_num_args() === 0) { cache()->save('app_update_watch', $out, 600); }
        return $out;
    }
}
