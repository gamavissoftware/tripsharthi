<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Crm\AuditLogger;

/**
 * "Log in as customer" for the TripSarthi team (platform admins only; the caller has already passed the admin-session filter).
 *
 * Each entry is its OWN session: a random token (only its hash is stored) that acts as the workspace OWNER. The owner's real login, password and
 * tokens are never touched, so they are not signed out and nothing is sent to them. Transparency instead of a notification: the reason is kept here
 * and written to the WORKSPACE audit log (support.access.start / .end, and every change made during the session is tagged `impersonated_by`), so the
 * customer's own Audit log shows "TripSarthi support" with the reason.
 *
 * Limits: a written reason, 2 h absolute / 30 min idle, 30 entries per admin per hour, never into another platform admin's account, and while
 * inside the session anything that could lock the real owner out or spend their money is refused (password, e-mail/profile, plan payments).
 * "View only" additionally refuses every write.
 */
final class ImpersonationService
{
    public const ABSOLUTE_MINUTES = 120;
    public const IDLE_MINUTES     = 30;
    public const PER_HOUR         = 30;

    /** Refused even in a full-access session: method => list of path regexes (after /api/v1/). */
    private const BLOCKED = [
        'PUT'  => ['#^auth/(change-password|profile)$#'],
        'POST' => ['#^billing/(subscribe|cancel|create-order|verify-payment)$#'],
    ];
    /** POST endpoints that only READ, allowed in view-only mode. */
    private const READ_POSTS = ['#^crm/reports/run(-batch)?$#', '#^billing/quote$#', '#^auth/logout$#'];

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(?int $t = null): string { return date('Y-m-d H:i:s', $t ?? $this->ts()); }

    /** @return array{token:string,session_id:int,expires_at:string,tenant:array,as:array} */
    public function start(int $adminUserId, int $tenantId, string $reason, bool $readOnly, string $ip = ''): array
    {
        $reason = trim(preg_replace('/\s+/u', ' ', strip_tags($reason)) ?? '');
        if (mb_strlen($reason) < 10) { throw new \InvalidArgumentException('Write the reason (at least 10 characters). The customer can see it in their audit log.'); }
        $reason = mb_substr($reason, 0, 300);
        $db = db_connect();

        $recent = $db->table('impersonation_sessions')->where('admin_user_id', $adminUserId)->where('created_at >=', $this->stamp($this->ts() - 3600))->countAllResults();
        if ($recent >= self::PER_HOUR) { throw new \DomainException('Too many customer sign-ins in the last hour. Try again later.'); }

        $t = $db->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->get()->getRowArray() ?: throw new \OutOfBoundsException('Customer not found.');
        $owner = $db->table('users')->where('tenant_id', $tenantId)->where('role', 'owner')->where('deleted_at', null)->orderBy('id', 'ASC')->get()->getRowArray();
        if (! $owner) { throw new \DomainException('This workspace has no owner account to act as.'); }
        if ((int) ($owner['is_platform_admin'] ?? 0) === 1 && (int) $owner['id'] !== $adminUserId) { throw new \DomainException('You cannot enter another TripSarthi admin\'s account this way.'); }

        $raw = bin2hex(random_bytes(32));
        $db->table('impersonation_sessions')->insert([
            'admin_user_id' => $adminUserId, 'tenant_id' => $tenantId, 'user_id' => (int) $owner['id'], 'token_hash' => hash('sha256', $raw), 'reason' => $reason,
            'read_only' => $readOnly ? 1 : 0, 'ip' => mb_substr($ip, 0, 45) ?: null, 'created_at' => $this->stamp(), 'last_seen_at' => $this->stamp(), 'expires_at' => $this->stamp($this->ts() + self::ABSOLUTE_MINUTES * 60),
        ]);
        $id = (int) $db->insertID();
        AuditLogger::log('support.access.start', 'workspace', $tenantId, null, ['reason' => $reason, 'view_only' => $readOnly], $tenantId, (int) $owner['id']);
        $this->tagLast($db, $tenantId, $adminUserId);
        return ['token' => $raw, 'session_id' => $id, 'expires_at' => $this->stamp($this->ts() + self::ABSOLUTE_MINUTES * 60), 'tenant' => ['id' => $tenantId, 'name' => (string) $t['name']], 'as' => ['id' => (int) $owner['id'], 'name' => (string) $owner['name']]];
    }

    /** The audit row just written by start()/end() belongs to the admin, not to the owner: tag it so the customer sees "TripSarthi support". */
    private function tagLast($db, int $tenantId, int $adminUserId): void
    {
        $db->table('audit_logs')->where('tenant_id', $tenantId)->where('impersonated_by', null)->whereIn('action', ['support.access.start', 'support.access.end'])->orderBy('id', 'DESC')->limit(1)->update(['impersonated_by' => $adminUserId]);
    }

    /** @return array{session:array,user:array}|null a live session and the owner user it acts as; null when unknown / ended / expired / idle / owner gone / admin no longer an admin */
    public function resolve(string $raw): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $raw)) { return null; }
        $db = db_connect();
        $s = $db->table('impersonation_sessions')->where('token_hash', hash('sha256', $raw))->get()->getRowArray();
        if (! $s || $s['ended_at'] !== null) { return null; }
        $now = $this->ts();
        if (strtotime($s['expires_at']) <= $now || strtotime($s['last_seen_at']) + self::IDLE_MINUTES * 60 <= $now) { $this->finish((int) $s['id'], 'expired'); return null; }
        $admin = $db->table('users')->select('id, is_platform_admin')->where('id', (int) $s['admin_user_id'])->get()->getRowArray();
        if (! $admin || (int) $admin['is_platform_admin'] !== 1) { $this->finish((int) $s['id'], 'admin'); return null; }      // admin:grant --revoke ends it at once
        $user = $db->table('users')->where('id', (int) $s['user_id'])->where('deleted_at', null)->get()->getRowArray();
        if (! $user) { return null; }
        if ($now - strtotime($s['last_seen_at']) >= 60) { $db->table('impersonation_sessions')->where('id', (int) $s['id'])->update(['last_seen_at' => $this->stamp()]); }
        return ['session' => $s, 'user' => $user];
    }

    /** null = allowed; otherwise the plain-words reason the request is refused inside a support session. */
    public static function refusal(string $method, string $path, bool $readOnly): ?string
    {
        $method = strtoupper($method);
        $path = preg_replace('#^(?:.*?/)?api/v1/#', '', trim($path, '/') . '/') ?? $path; $path = trim($path, '/');
        foreach (self::BLOCKED[$method] ?? [] as $re) {
            if (preg_match($re, $path)) { return 'Not available while signed in as a customer: this could lock the real owner out or spend their money.'; }
        }
        if ($readOnly && ! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            foreach (self::READ_POSTS as $re) { if ($method === 'POST' && preg_match($re, $path)) { return null; } }
            return 'This is a view-only session: changes are switched off.';
        }
        return null;
    }

    public function countWrite(int $sessionId): void
    {
        db_connect()->query('UPDATE impersonation_sessions SET actions = actions + 1 WHERE id = ?', [$sessionId]);
    }

    public function end(int $sessionId, string $by = 'admin'): void { $this->finish($sessionId, $by); }

    public function endByToken(string $raw): void
    {
        $s = db_connect()->table('impersonation_sessions')->select('id')->where('token_hash', hash('sha256', $raw))->get()->getRowArray();
        if ($s) { $this->finish((int) $s['id'], 'logout'); }
    }

    private function finish(int $id, string $by): void
    {
        $db = db_connect();
        $s = $db->table('impersonation_sessions')->where('id', $id)->where('ended_at', null)->get()->getRowArray();
        if (! $s) { return; }
        $db->table('impersonation_sessions')->where('id', $id)->update(['ended_at' => $this->stamp(), 'ended_by' => $by]);
        AuditLogger::log('support.access.end', 'workspace', (int) $s['tenant_id'], null, ['minutes' => max(1, (int) round(($this->ts() - strtotime($s['created_at'])) / 60)), 'changes' => (int) $s['actions']], (int) $s['tenant_id'], (int) $s['user_id']);
        $this->tagLast($db, (int) $s['tenant_id'], (int) $s['admin_user_id']);
    }

    /** Access log for the admin app (newest first), optionally for one customer. */
    public function log(?int $tenantId = null, int $limit = 100): array
    {
        $q = 'SELECT s.id, s.tenant_id, t.name tenant_name, s.admin_user_id, a.name admin_name, a.email admin_email, s.user_id, s.reason, s.read_only, s.ip, s.created_at, s.last_seen_at, s.expires_at, s.ended_at, s.ended_by, s.actions
              FROM impersonation_sessions s LEFT JOIN tenants t ON t.id = s.tenant_id LEFT JOIN users a ON a.id = s.admin_user_id' . ($tenantId ? ' WHERE s.tenant_id = ?' : '') . ' ORDER BY s.id DESC LIMIT ' . max(1, min(500, $limit));
        $rows = db_connect()->query($q, $tenantId ? [$tenantId] : [])->getResultArray();
        $now = $this->ts();
        foreach ($rows as &$r) {
            $r['read_only'] = (bool) $r['read_only']; $r['actions'] = (int) $r['actions'];
            $r['live'] = $r['ended_at'] === null && strtotime($r['expires_at']) > $now && strtotime($r['last_seen_at']) + self::IDLE_MINUTES * 60 > $now;
        }
        return $rows;
    }
}
