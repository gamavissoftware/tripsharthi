<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Crm\AuditLogger;

/**
 * The TripSarthi team's view of EVERY workspace's WhatsApp: is it connected, what quality rating Meta gives it, how templates are doing,
 * how much it sends, how much fails, and what it costs. Read-only except for one control: pausing a workspace's MARKETING sends
 * (honoured by SendingGate; utility messages such as booking confirmations are never blocked).
 *
 * Nothing here exposes message bodies, contact details or access tokens - only counts, statuses and error TEXT from Meta.
 * Exceptions: \InvalidArgumentException = bad input (422), \OutOfBoundsException = not found (404).
 */
final class WhatsappOversightService
{
    public const WINDOW_DAYS        = 30;
    public const MIN_SENT_FOR_RATE  = 50;        // a failure RATE means nothing on a handful of messages
    public const FAIL_WARN_PCT      = 10.0;
    public const FAIL_CRITICAL_PCT  = 25.0;
    private const RANK = ['critical' => 3, 'warning' => 2, 'info' => 1];

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function since(): string { return date('Y-m-d H:i:s', $this->ts() - self::WINDOW_DAYS * 86400); }

    // ================================================================================================================================
    // overview of all workspaces
    // ================================================================================================================================

    /**
     * @param string $filter '' | 'attention' | 'connected' | 'not_connected' | 'paused'
     * @return array{totals:array,rows:list<array>,total:int}
     */
    public function overview(string $q = '', string $filter = ''): array
    {
        $rows = $this->build($q, null);
        $totals = $this->totals($rows);
        $rows = array_values(array_filter($rows, fn ($r) => match ($filter) {
            'attention' => $r['attention'] !== null, 'connected' => $r['whatsapp'] === 'active', 'not_connected' => $r['whatsapp'] === 'none', 'paused' => $r['paused'], default => true,
        }));
        usort($rows, fn ($a, $b) => (self::RANK[$b['attention'] ?? ''] ?? 0) <=> (self::RANK[$a['attention'] ?? ''] ?? 0) ?: $b['sent'] <=> $a['sent'] ?: strcmp($a['name'], $b['name']));
        return ['totals' => $totals, 'rows' => $rows, 'total' => count($rows)];
    }

    /** @return list<array> one row per workspace (just $only when given), with flags and attention */
    private function build(string $q, ?int $only): array
    {
        $db = db_connect(); $since = $this->since();
        $q = trim($q);
        $t1 = $only !== null ? ' AND tenant_id = ' . (int) $only : '';      // int-cast: safe to inline
        $t0 = $only !== null ? ' WHERE tenant_id = ' . (int) $only : '';
        $sql = 'SELECT id, name, plan, status, wa_marketing_paused, wa_marketing_paused_reason FROM tenants WHERE deleted_at IS NULL' . ($only !== null ? ' AND id = ' . (int) $only : ''); $b = [];
        if ($q !== '') { $sql .= ' AND (name LIKE ? OR id = ?)'; $b[] = '%' . $q . '%'; $b[] = ctype_digit($q) ? (int) $q : 0; }
        $tenants = $db->query($sql . ' ORDER BY name', $b)->getResultArray();

        $waba = [];
        foreach ($db->query('SELECT tenant_id, status, display_name, provider, created_at FROM waba_accounts WHERE deleted_at IS NULL' . $t1 . ' ORDER BY id') ->getResultArray() as $r) { $waba[(int) $r['tenant_id']] = $r; }
        $numbers = [];
        foreach ($db->query('SELECT tenant_id, display_number, quality_rating FROM phone_numbers' . $t0)->getResultArray() as $r) { $numbers[(int) $r['tenant_id']][] = $r; }
        $tpl = [];
        foreach ($db->query('SELECT tenant_id, meta_status, COUNT(*) n FROM templates WHERE deleted_at IS NULL' . $t1 . ' GROUP BY tenant_id, meta_status')->getResultArray() as $r) { $tpl[(int) $r['tenant_id']][$r['meta_status']] = (int) $r['n']; }
        $msg = [];
        foreach ($db->query("SELECT tenant_id,
                                    SUM(direction = 'out') out_n,
                                    SUM(direction = 'out' AND status IN ('delivered','read')) delivered_n,
                                    SUM(direction = 'out' AND status = 'read') read_n,
                                    SUM(direction = 'out' AND status = 'failed') failed_n,
                                    SUM(direction = 'out' AND category = 'marketing') marketing_n,
                                    SUM(direction = 'in') in_n, MAX(created_at) last_at
                               FROM messages WHERE created_at >= ?{$t1} GROUP BY tenant_id", [$since])->getResultArray() as $r) { $msg[(int) $r['tenant_id']] = $r; }
        $cost = [];
        foreach ($db->query('SELECT tenant_id, SUM(cost) c FROM waba_billing WHERE month = ?' . $t1 . ' GROUP BY tenant_id', [date('Y-m', $this->ts())])->getResultArray() as $r) { $cost[(int) $r['tenant_id']] = (float) $r['c']; }
        $camps = [];
        foreach ($db->query('SELECT tenant_id, COUNT(*) n FROM campaigns WHERE created_at >= ? AND deleted_at IS NULL' . $t1 . ' GROUP BY tenant_id', [$since])->getResultArray() as $r) { $camps[(int) $r['tenant_id']] = (int) $r['n']; }

        $rows = [];
        foreach ($tenants as $t) {
            $id = (int) $t['id']; $m = $msg[$id] ?? null;
            $out = (int) ($m['out_n'] ?? 0); $failed = (int) ($m['failed_n'] ?? 0);
            $row = [
                'id' => $id, 'name' => $t['name'], 'plan' => $t['plan'], 'status' => $t['status'],
                'whatsapp' => $this->wabaState($waba[$id] ?? null), 'provider' => $waba[$id]['provider'] ?? null,
                'quality' => $this->worstQuality($numbers[$id] ?? []), 'numbers' => array_map(fn ($n) => ['number' => $n['display_number'], 'quality' => $n['quality_rating']], $numbers[$id] ?? []),
                'templates' => ['approved' => $tpl[$id]['approved'] ?? 0, 'pending' => $tpl[$id]['pending'] ?? 0, 'rejected' => $tpl[$id]['rejected'] ?? 0, 'draft' => $tpl[$id]['draft'] ?? 0, 'paused' => ($tpl[$id]['paused'] ?? 0) + ($tpl[$id]['disabled'] ?? 0)],
                'sent' => $out, 'delivered' => (int) ($m['delivered_n'] ?? 0), 'read' => (int) ($m['read_n'] ?? 0), 'failed' => $failed, 'marketing' => (int) ($m['marketing_n'] ?? 0), 'received' => (int) ($m['in_n'] ?? 0),
                'failure_pct' => $out > 0 ? round($failed * 100 / $out, 1) : null, 'last_message_at' => $m['last_at'] ?? null,
                'campaigns' => $camps[$id] ?? 0, 'cost_this_month' => $cost[$id] ?? null,
                'paused' => (bool) $t['wa_marketing_paused'], 'paused_reason' => $t['wa_marketing_paused_reason'],
            ];
            $row['flags'] = $this->flags($row);
            $row['attention'] = $row['flags'] === [] ? null : array_keys(self::RANK, max(array_map(fn ($f) => self::RANK[$f['severity']], $row['flags'])))[0];
            $rows[] = $row;
        }

        return $rows;
    }

    private function wabaState(?array $w): string
    {
        if ($w === null) { return 'none'; }
        return in_array($w['status'], ['pending', 'active', 'suspended'], true) ? $w['status'] : 'pending';
    }

    /** red > yellow > green > unknown (a number we cannot rate is not "good"); 'none' when no number is connected. */
    private function worstQuality(array $numbers): string
    {
        if ($numbers === []) { return 'none'; }
        $r = array_map(fn ($n) => strtolower((string) $n['quality_rating']), $numbers);
        foreach (['red', 'yellow'] as $bad) { if (in_array($bad, $r, true)) { return $bad; } }
        return in_array('green', $r, true) ? 'green' : 'unknown';
    }

    /** @return list<array{code:string,severity:string,text:string}> */
    private function flags(array $r): array
    {
        $f = [];
        if ($r['quality'] === 'red') { $f[] = ['code' => 'quality_red', 'severity' => 'critical', 'text' => 'Quality rating is RED - Meta may restrict this number']; }
        if ($r['quality'] === 'yellow') { $f[] = ['code' => 'quality_yellow', 'severity' => 'warning', 'text' => 'Quality rating dropped to YELLOW - marketing is paused automatically']; }
        if ($r['whatsapp'] === 'suspended') { $f[] = ['code' => 'waba_suspended', 'severity' => 'critical', 'text' => 'The WhatsApp Business account is suspended']; }
        if ($r['sent'] >= self::MIN_SENT_FOR_RATE && $r['failure_pct'] !== null) {
            if ($r['failure_pct'] >= self::FAIL_CRITICAL_PCT) { $f[] = ['code' => 'high_failures', 'severity' => 'critical', 'text' => "{$r['failure_pct']}% of messages failed in the last " . self::WINDOW_DAYS . ' days']; }
            elseif ($r['failure_pct'] >= self::FAIL_WARN_PCT) { $f[] = ['code' => 'high_failures', 'severity' => 'warning', 'text' => "{$r['failure_pct']}% of messages failed in the last " . self::WINDOW_DAYS . ' days']; }
        }
        if ($r['templates']['rejected'] >= 3) { $f[] = ['code' => 'rejected_templates', 'severity' => 'warning', 'text' => $r['templates']['rejected'] . ' templates were rejected by Meta']; }
        elseif ($r['templates']['rejected'] > 0) { $f[] = ['code' => 'rejected_templates', 'severity' => 'info', 'text' => $r['templates']['rejected'] . ' template(s) rejected by Meta']; }
        if ($r['paused']) { $f[] = ['code' => 'paused', 'severity' => 'info', 'text' => 'Marketing sends are paused by TripSarthi' . ($r['paused_reason'] ? ': ' . $r['paused_reason'] : '')]; }
        return $f;
    }

    private function totals(array $rows): array
    {
        $t = ['workspaces' => count($rows), 'connected' => 0, 'not_connected' => 0, 'suspended' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'marketing' => 0,
              'quality' => ['green' => 0, 'yellow' => 0, 'red' => 0, 'unknown' => 0], 'templates_pending' => 0, 'templates_rejected' => 0, 'cost_this_month' => 0.0,
              'attention' => ['critical' => 0, 'warning' => 0, 'info' => 0], 'paused' => 0];
        foreach ($rows as $r) {
            $r['whatsapp'] === 'none' ? $t['not_connected']++ : ($r['whatsapp'] === 'active' ? $t['connected']++ : null);
            if ($r['whatsapp'] === 'suspended') { $t['suspended']++; }
            foreach (['sent', 'delivered', 'read', 'failed', 'marketing'] as $k) { $t[$k] += $r[$k]; }
            if (isset($t['quality'][$r['quality']])) { $t['quality'][$r['quality']]++; }
            $t['templates_pending'] += $r['templates']['pending']; $t['templates_rejected'] += $r['templates']['rejected']; $t['cost_this_month'] += (float) ($r['cost_this_month'] ?? 0);
            if ($r['attention']) { $t['attention'][$r['attention']]++; }
            if ($r['paused']) { $t['paused']++; }
        }
        $t['delivery_pct'] = $t['sent'] > 0 ? round($t['delivered'] * 100 / $t['sent'], 1) : null;
        $t['failure_pct']  = $t['sent'] > 0 ? round($t['failed'] * 100 / $t['sent'], 1) : null;
        $t['cost_this_month'] = round($t['cost_this_month'], 2);
        return $t;
    }

    // ================================================================================================================================
    // one workspace
    // ================================================================================================================================

    public function workspace(int $tenantId): array
    {
        $db = db_connect(); $since = $this->since();
        $row = $this->build('', $tenantId)[0] ?? throw new \OutOfBoundsException('Workspace not found.');

        $templates = $db->query('SELECT id, name, display_name, language, category, meta_status, rejection_reason, submitted_at, created_at FROM templates WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY (meta_status = "rejected") DESC, id DESC LIMIT 100', [$tenantId])->getResultArray();
        $daily = $db->query("SELECT DATE(created_at) d, SUM(direction = 'out') sent, SUM(direction = 'out' AND status IN ('delivered','read')) delivered, SUM(direction = 'out' AND status = 'failed') failed
                               FROM messages WHERE tenant_id = ? AND created_at >= ? GROUP BY DATE(created_at) ORDER BY d", [$tenantId, $since])->getResultArray();
        $errors = $db->query("SELECT LEFT(error, 160) err, COUNT(*) n FROM messages WHERE tenant_id = ? AND status = 'failed' AND created_at >= ? AND error IS NOT NULL GROUP BY LEFT(error, 160) ORDER BY n DESC LIMIT 8", [$tenantId, $since])->getResultArray();
        $billing = $db->query("SELECT month, category, SUM(volume) volume, SUM(cost) cost, MAX(currency) currency FROM waba_billing WHERE tenant_id = ? GROUP BY month, category ORDER BY month DESC, category LIMIT 40", [$tenantId])->getResultArray();
        $campaigns = $db->query("SELECT c.id, c.name, c.status, c.total_contacts, c.created_at, t.name template,
                                        (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.status IN ('sent','delivered','read')) sent,
                                        (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.status IN ('delivered','read')) delivered,
                                        (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.status = 'failed') failed
                                   FROM campaigns c LEFT JOIN templates t ON t.id = c.template_id WHERE c.tenant_id = ? AND c.deleted_at IS NULL ORDER BY c.id DESC LIMIT 15", [$tenantId])->getResultArray();
        $owner = $db->query("SELECT name, email FROM users WHERE tenant_id = ? AND role = 'owner' AND deleted_at IS NULL ORDER BY id LIMIT 1", [$tenantId])->getRowArray();

        return ['workspace' => $row, 'owner' => $owner ? ['name' => $owner['name'], 'email' => $owner['email']] : null, 'templates' => $templates, 'daily' => $daily, 'errors' => $errors,
                'billing' => $billing, 'campaigns' => $campaigns, 'window_days' => self::WINDOW_DAYS];
    }

    // ================================================================================================================================
    // the one control
    // ================================================================================================================================

    /** Stops (or resumes) MARKETING sends from a workspace. Utility messages are never affected. A reason is required to pause. */
    public function setMarketingPaused(int $tenantId, bool $paused, string $reason, int $adminId): array
    {
        $db = db_connect();
        $t = $db->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->get()->getRowArray() ?: throw new \OutOfBoundsException('Workspace not found.');
        $reason = trim($reason);
        if ($paused && (mb_strlen($reason) < 5 || mb_strlen($reason) > 300)) { throw new \InvalidArgumentException('Give a reason (5-300 characters) - it is shown to the workspace and kept in the audit log.'); }
        $db->table('tenants')->where('id', $tenantId)->update(['wa_marketing_paused' => $paused ? 1 : 0, 'wa_marketing_paused_reason' => $paused ? $reason : null,
            'wa_marketing_paused_at' => $paused ? date('Y-m-d H:i:s', $this->ts()) : null, 'updated_at' => date('Y-m-d H:i:s', $this->ts())]);
        AuditLogger::log($paused ? 'admin.wa_marketing.pause' : 'admin.wa_marketing.resume', 'tenant', $tenantId, ['paused' => (bool) $t['wa_marketing_paused']], ['paused' => $paused, 'reason' => $paused ? $reason : null], $tenantId, $adminId);
        return $this->build('', $tenantId)[0];
    }
}
