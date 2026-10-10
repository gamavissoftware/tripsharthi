<?php

declare(strict_types=1);

namespace App\Services\Partner;

/**
 * Sessions of the partner portal (partners.tripsarthi.com). Same design as the admin sessions: only the SHA-256 of the token is stored,
 * a session is valid while not revoked, within its absolute life (7 days) and used within the idle window (12 h), and the partner must
 * STILL be active on every check - suspending a partner ends their access at once.
 */
final class PartnerSessionService
{
    public const ABSOLUTE_HOURS = 168;
    public const IDLE_MINUTES   = 720;
    public const MAX_LIVE       = 5;

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(?int $t = null): string { return date('Y-m-d H:i:s', $t ?? $this->ts()); }

    public function create(int $partnerId, string $ip = '', string $userAgent = ''): string
    {
        $db = db_connect();
        $db->table('partner_sessions')->where('expires_at <', $this->stamp())->delete();
        $live = $db->table('partner_sessions')->select('id')->where('partner_id', $partnerId)->where('revoked_at', null)->orderBy('id', 'DESC')->get()->getResultArray();
        foreach (array_slice($live, self::MAX_LIVE - 1) as $old) { $db->table('partner_sessions')->where('id', (int) $old['id'])->update(['revoked_at' => $this->stamp()]); }
        $raw = bin2hex(random_bytes(32));
        $db->table('partner_sessions')->insert(['partner_id' => $partnerId, 'token_hash' => hash('sha256', $raw), 'ip' => mb_substr($ip, 0, 45) ?: null, 'user_agent' => mb_substr($userAgent, 0, 200) ?: null,
            'created_at' => $this->stamp(), 'last_seen_at' => $this->stamp(), 'expires_at' => $this->stamp($this->ts() + self::ABSOLUTE_HOURS * 3600)]);
        return $raw;
    }

    /** The signed-in ACTIVE partner (password hash removed), or null. */
    public function partner(string $raw): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $raw)) { return null; }
        $db  = db_connect();
        $row = $db->table('partner_sessions')->where('token_hash', hash('sha256', $raw))->get()->getRowArray();
        if (! $row || $row['revoked_at'] !== null) { return null; }
        $now = $this->ts();
        if (strtotime($row['expires_at']) <= $now || strtotime($row['last_seen_at']) + self::IDLE_MINUTES * 60 <= $now) { return null; }
        $p = $db->table('partners')->where('id', (int) $row['partner_id'])->get()->getRowArray();
        if (! $p || $p['status'] !== 'active') { return null; }
        if ($now - strtotime($row['last_seen_at']) >= 60) { $db->table('partner_sessions')->where('id', (int) $row['id'])->update(['last_seen_at' => $this->stamp()]); }
        unset($p['password_hash'], $p['invite_token_hash'], $p['payout_enc']);
        return $p;
    }

    public function revoke(string $raw): void
    {
        db_connect()->table('partner_sessions')->where('token_hash', hash('sha256', $raw))->where('revoked_at', null)->update(['revoked_at' => $this->stamp()]);
    }

    public function revokeAll(int $partnerId): int
    {
        $db = db_connect();
        $db->table('partner_sessions')->where('partner_id', $partnerId)->where('revoked_at', null)->update(['revoked_at' => $this->stamp()]);
        return $db->affectedRows();
    }
}
