<?php

declare(strict_types=1);

namespace App\Services\Admin;

/**
 * Session store for the platform-admin app. A session is valid while: not revoked, before its absolute expiry
 * (12 h) and used within the idle window (2 h). The owning user must STILL be a platform admin on every check, so
 * `php spark admin:grant --revoke` ends access immediately even for sessions that have not expired.
 */
final class AdminSessionService
{
    public const ABSOLUTE_HOURS = 12;
    public const IDLE_MINUTES   = 120;
    public const MAX_LIVE       = 5;     // per person: signing in on a sixth device ends the oldest session

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(?int $t = null): string { return date('Y-m-d H:i:s', $t ?? $this->ts()); }

    /** Opens a session and returns the RAW token (shown once; only its hash is stored). */
    public function create(int $userId, string $ip = '', string $userAgent = ''): string
    {
        $db = db_connect();
        $db->table('admin_sessions')->where('expires_at <', $this->stamp())->delete();                           // housekeeping
        $live = $db->table('admin_sessions')->select('id')->where('user_id', $userId)->where('revoked_at', null)->orderBy('id', 'DESC')->get()->getResultArray();
        foreach (array_slice($live, self::MAX_LIVE - 1) as $old) {
            $db->table('admin_sessions')->where('id', (int) $old['id'])->update(['revoked_at' => $this->stamp()]);
        }
        $raw = bin2hex(random_bytes(32));
        $db->table('admin_sessions')->insert([
            'user_id' => $userId, 'token_hash' => hash('sha256', $raw), 'ip' => mb_substr($ip, 0, 45) ?: null, 'user_agent' => mb_substr($userAgent, 0, 200) ?: null,
            'created_at' => $this->stamp(), 'last_seen_at' => $this->stamp(), 'expires_at' => $this->stamp($this->ts() + self::ABSOLUTE_HOURS * 3600),
        ]);
        return $raw;
    }

    /** The signed-in platform admin for a raw token, or null (unknown / revoked / expired / idle / no longer an admin). */
    public function user(string $raw): ?array
    {
        if ($raw === '' || ! preg_match('/^[a-f0-9]{64}$/', $raw)) { return null; }
        $db  = db_connect();
        $row = $db->table('admin_sessions')->where('token_hash', hash('sha256', $raw))->get()->getRowArray();
        if (! $row || $row['revoked_at'] !== null) { return null; }
        $now = $this->ts();
        if (strtotime($row['expires_at']) <= $now || strtotime($row['last_seen_at']) + self::IDLE_MINUTES * 60 <= $now) { return null; }
        $user = $db->table('users')->where('id', (int) $row['user_id'])->get()->getRowArray();
        if (! $user || (int) ($user['is_platform_admin'] ?? 0) !== 1) { return null; }
        if ($now - strtotime($row['last_seen_at']) >= 60) { $db->table('admin_sessions')->where('id', (int) $row['id'])->update(['last_seen_at' => $this->stamp()]); }   // sliding idle window, one write a minute
        return $user;
    }

    public function revoke(string $raw): void
    {
        db_connect()->table('admin_sessions')->where('token_hash', hash('sha256', $raw))->where('revoked_at', null)->update(['revoked_at' => $this->stamp()]);
    }

    public function revokeAll(int $userId): int
    {
        $db = db_connect();
        $db->table('admin_sessions')->where('user_id', $userId)->where('revoked_at', null)->update(['revoked_at' => $this->stamp()]);
        return $db->affectedRows();
    }
}
