<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Crm\AuditLogger;
use App\Services\Email\EmailService;
use App\Services\Marketing\EmailBody;
use App\Services\Marketing\EmailUnsubscribe;

/**
 * TripSarthi's own E-MAIL marketing to its customers (admin app -> Email marketing).
 *
 * CONSENT FIRST: an address is in a segment only if its owner ticked the (never pre-ticked) "e-mail me product news and offers" box -
 * at sign-up or in Profile (workspace owners) or in the partner dashboard (partners). Every e-mail carries a one-click unsubscribe link
 * (and the List-Unsubscribe headers), and consent is re-checked for each address at the moment it is actually sent.
 *
 * Flow: draft -> "Send" snapshots the recipients (one row each in platform_email_sends, UNIQUE per campaign + address) -> the cron command
 * `platform-email:send` sends a few per minute (EMAIL_MARKETING_PER_MINUTE, default 30) so the mail server's reputation is protected -> sent.
 * No open/click tracking by design (privacy). Errors: InvalidArgumentException = bad input (422), OutOfBoundsException = not found (404),
 * DomainException = not allowed now (409).
 */
final class PlatformEmailService
{
    public const SEGMENTS = [
        'owners'              => ['label' => 'Workspace owners', 'description' => 'Everyone who runs a TripSarthi workspace and agreed to e-mail updates.'],
        'owners_trial_ending' => ['label' => 'Plan ending within 7 days', 'description' => 'Opted-in owners whose paid period or trial ends in the next 7 days.'],
        'owners_free'         => ['label' => 'On the free plan 7+ days', 'description' => 'Opted-in owners still on the free plan a week after signing up (upgrade nudge).'],
        'owners_lapsed'       => ['label' => 'Lapsed customers', 'description' => 'Opted-in owners who paid before but are back on the free plan (win-back).'],
        'partners'            => ['label' => 'Active partners', 'description' => 'Referral partners who agreed to program updates by e-mail.'],
    ];
    public const PER_MINUTE = 30;

    /** @param callable|null $mailer fn(string $to, string $subject, string $html, string $text, array $headers): bool  (test seam) */
    public function __construct(private $mailer = null, private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(?int $t = null): string { return date('Y-m-d H:i:s', $t ?? $this->ts()); }

    // ==== audiences =================================================================================================================================

    /** @return list<array{key:string,label:string,description:string,count:int}> */
    public function segments(): array
    {
        $out = [];
        foreach (self::SEGMENTS as $k => $d) { $out[] = ['key' => $k, 'label' => $d['label'], 'description' => $d['description'], 'count' => count($this->members($k))]; }
        return $out;
    }

    /** @return list<array{email:string,name:string,kind:string,context:string}> consenting members, one per address */
    public function members(string $key): array
    {
        if (! isset(self::SEGMENTS[$key])) { throw new \InvalidArgumentException('Unknown audience.'); }
        $db = db_connect(); $now = $this->stamp();
        $owners = "SELECT u.name, u.email, 'user' kind, t.name context FROM users u JOIN tenants t ON t.id = u.tenant_id AND t.deleted_at IS NULL AND t.status = 'active'
                    WHERE u.role = 'owner' AND u.deleted_at IS NULL AND u.email_marketing_opt_in = 1 AND u.is_platform_admin = 0";
        $rows = [];
        switch ($key) {
            case 'owners': $rows = $db->query($owners)->getResultArray(); break;
            case 'owners_trial_ending':
                $rows = $db->query($owners . " AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.tenant_id = t.id AND s.status = 'active' AND s.current_period_end > ? AND s.current_period_end <= ?)", [$now, $this->stamp($this->ts() + 7 * 86400)])->getResultArray(); break;
            case 'owners_free':
                $rows = $db->query($owners . " AND t.plan = 'free' AND t.created_at <= ?", [$this->stamp($this->ts() - 7 * 86400)])->getResultArray(); break;
            case 'owners_lapsed':
                $rows = $db->query($owners . " AND t.plan = 'free' AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.tenant_id = t.id AND s.status IN ('cancelled','halted','downgraded') AND s.amount_paise > 0 AND s.razorpay_sub_id NOT LIKE 'admin-%')")->getResultArray(); break;
            case 'partners':
                $rows = $db->query("SELECT name, email, 'partner' kind, COALESCE(company, 'Partner') context FROM partners WHERE status = 'active' AND email_marketing_opt_in = 1")->getResultArray(); break;
        }
        $seen = []; $out = [];
        foreach ($rows as $r) {
            $e = strtolower(trim((string) $r['email']));
            if (! filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($e) > 190 || isset($seen[$e])) { continue; }
            $seen[$e] = true;
            $out[] = ['email' => $e, 'name' => mb_substr(trim((string) $r['name']), 0, 120), 'kind' => (string) $r['kind'], 'context' => (string) $r['context']];
        }
        return $out;
    }

    // ==== campaigns ==================================================================================================================================

    public function campaigns(): array
    {
        $rows = db_connect()->query("SELECT c.*, (SELECT COUNT(*) FROM platform_email_sends s WHERE s.campaign_id = c.id AND s.status = 'sent') sent,
              (SELECT COUNT(*) FROM platform_email_sends s WHERE s.campaign_id = c.id AND s.status = 'failed') failed,
              (SELECT COUNT(*) FROM platform_email_sends s WHERE s.campaign_id = c.id AND s.status = 'skipped') skipped,
              (SELECT COUNT(*) FROM platform_email_sends s WHERE s.campaign_id = c.id AND s.status IN ('queued','sending')) waiting
              FROM platform_email_campaigns c ORDER BY c.id DESC LIMIT 100")->getResultArray();
        return array_map(fn ($r) => $this->shape($r), $rows);
    }

    public function campaign(int $id): array
    {
        $r = db_connect()->query("SELECT c.*, (SELECT COUNT(*) FROM platform_email_sends s WHERE s.campaign_id = c.id AND s.status = 'sent') sent,
              (SELECT COUNT(*) FROM platform_email_sends s WHERE s.campaign_id = c.id AND s.status = 'failed') failed,
              (SELECT COUNT(*) FROM platform_email_sends s WHERE s.campaign_id = c.id AND s.status = 'skipped') skipped,
              (SELECT COUNT(*) FROM platform_email_sends s WHERE s.campaign_id = c.id AND s.status IN ('queued','sending')) waiting
              FROM platform_email_campaigns c WHERE c.id = ?", [$id])->getRowArray();
        if (! $r) { throw new \OutOfBoundsException('Campaign not found.'); }
        $fails = db_connect()->table('platform_email_sends')->select('email, error')->where('campaign_id', $id)->where('status', 'failed')->limit(20)->get()->getResultArray();
        return $this->shape($r) + ['body' => $r['body'], 'failures' => $fails];
    }

    public function save(array $in, ?int $id, int $adminId): array
    {
        $db = db_connect();
        $name = trim(mb_substr(trim((string) ($in['name'] ?? '')), 0, 120));
        $subject = trim(mb_substr(str_replace(["\r", "\n"], ' ', (string) ($in['subject'] ?? '')), 0, 150));
        $body = trim(mb_substr(str_replace(["\r\n", "\r"], "\n", (string) ($in['body'] ?? '')), 0, 20000));
        $segment = (string) ($in['segment'] ?? '');
        if ($name === '') { throw new \InvalidArgumentException('Give the campaign a name (only you see it).'); }
        if (mb_strlen($subject) < 3) { throw new \InvalidArgumentException('The subject line is too short.'); }
        if (mb_strlen($body) < 20) { throw new \InvalidArgumentException('Write the e-mail (at least a couple of sentences).'); }
        if (! isset(self::SEGMENTS[$segment])) { throw new \InvalidArgumentException('Choose who it goes to.'); }
        $row = ['name' => $name, 'subject' => $subject, 'body' => $body, 'segment' => $segment, 'updated_at' => $this->stamp()];
        if ($id === null) {
            $db->table('platform_email_campaigns')->insert($row + ['status' => 'draft', 'recipients' => 0, 'created_by' => $adminId, 'created_at' => $this->stamp()]);
            $id = (int) $db->insertID();
            $this->audit('admin.email.create', $id, ['name' => $name, 'segment' => $segment], $adminId);
        } else {
            $c = $this->row($id);
            if ($c['status'] !== 'draft') { throw new \DomainException('Only a draft can be edited. Duplicate it to send a changed version.'); }
            $db->table('platform_email_campaigns')->where('id', $id)->update($row);
        }
        return $this->campaign($id);
    }

    public function delete(int $id, int $adminId): void
    {
        $c = $this->row($id);
        if ($c['status'] !== 'draft') { throw new \DomainException('Only a draft can be deleted.'); }
        db_connect()->table('platform_email_campaigns')->where('id', $id)->delete();
        $this->audit('admin.email.delete', $id, ['name' => $c['name']], $adminId);
    }

    /** What recipients will see (sample first name) + audience size and a masked sample of addresses. */
    public function preview(int $id): array
    {
        $c = $this->row($id); $m = $this->members($c['segment']);
        $r = EmailBody::render($c['body'], 'Priya', EmailService::appUrl() . '/api/v1/public/email/unsubscribe', EmailBody::personalise($c['subject'], 'Priya'));
        return ['subject' => EmailBody::personalise($c['subject'], 'Priya'), 'html' => $r['html'], 'audience' => ['label' => self::SEGMENTS[$c['segment']]['label'] ?? $c['segment'], 'count' => count($m),
            'sample' => array_map(fn ($x) => ['name' => $x['name'], 'email' => $this->mask($x['email']), 'context' => $x['context']], array_slice($m, 0, 5))]];
    }

    /** Send the e-mail to the admin's own address, rendered exactly like the real thing. Does not touch the campaign. */
    public function testSend(int $id, int $adminId): array
    {
        $c = $this->row($id);
        $a = db_connect()->table('users')->select('name, email')->where('id', $adminId)->get()->getRowArray();
        if (! $a || ! filter_var($a['email'], FILTER_VALIDATE_EMAIL)) { throw new \DomainException('Your admin account has no usable e-mail address.'); }
        $ok = $this->deliver($a['email'], $a['name'], $c['subject'], $c['body'], '[TEST] ');
        if (! $ok) { throw new \DomainException('The test e-mail could not be sent - check the mail settings on the server.'); }
        return ['sent_to' => $a['email']];
    }

    /** Snapshot the recipients and start sending. $confirmCount must equal the audience size right now (guards against a surprise). */
    public function send(int $id, int $adminId, int $confirmCount): array
    {
        $db = db_connect(); $c = $this->row($id);
        if ($c['status'] !== 'draft') { throw new \DomainException('This campaign was already sent or is sending.'); }
        $members = $this->members($c['segment']);
        if (count($members) === 0) { throw new \DomainException('Nobody has agreed to receive this yet - there is no one to send to.'); }
        if ($confirmCount !== count($members)) { throw new \DomainException('The audience changed to ' . count($members) . ' people. Check and confirm that number to send.'); }
        $db->transStart();
        $db->table('platform_email_campaigns')->where('id', $id)->where('status', 'draft')->update(['status' => 'sending', 'recipients' => count($members), 'started_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        if ($db->affectedRows() < 1) { $db->transComplete(); throw new \DomainException('This campaign was already sent or is sending.'); }
        foreach ($members as $m) {
            $db->query('INSERT IGNORE INTO platform_email_sends (campaign_id, email, name, kind, status) VALUES (?, ?, ?, ?, ?)', [$id, $m['email'], $m['name'], $m['kind'], 'queued']);
        }
        $db->transComplete();
        $this->audit('admin.email.send', $id, ['segment' => $c['segment'], 'recipients' => count($members)], $adminId);
        return $this->campaign($id);
    }

    public function cancel(int $id, int $adminId): array
    {
        $db = db_connect(); $c = $this->row($id);
        if ($c['status'] !== 'sending') { throw new \DomainException('Only a campaign that is sending can be stopped.'); }
        $db->table('platform_email_sends')->where('campaign_id', $id)->where('status', 'queued')->update(['status' => 'skipped', 'error' => 'stopped by the team']);
        $db->table('platform_email_campaigns')->where('id', $id)->update(['status' => 'cancelled', 'finished_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        $this->audit('admin.email.cancel', $id, null, $adminId);
        return $this->campaign($id);
    }

    // ==== the sender (cron) ===========================================================================================================================

    /** Send up to $limit queued e-mails across sending campaigns. @return array{sent:int,failed:int,skipped:int,finished:int} */
    public function process(?int $limit = null): array
    {
        $db = db_connect(); $limit = $limit ?? max(1, (int) env('EMAIL_MARKETING_PER_MINUTE', self::PER_MINUTE));
        $res = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'finished' => 0];
        // a row left in 'sending' by a crash may or may not have gone out: never risk a duplicate
        $db->table('platform_email_sends')->where('status', 'sending')->where('sent_at <', $this->stamp($this->ts() - 600))->update(['status' => 'failed', 'error' => 'interrupted - not retried to avoid a duplicate']);
        foreach ($db->table('platform_email_campaigns')->where('status', 'sending')->orderBy('id')->get()->getResultArray() as $c) {
            if ($limit <= 0) { break; }
            $rows = $db->table('platform_email_sends')->where('campaign_id', $c['id'])->where('status', 'queued')->orderBy('id')->limit($limit)->get()->getResultArray();
            foreach ($rows as $s) {
                $db->table('platform_email_sends')->where('id', $s['id'])->where('status', 'queued')->update(['status' => 'sending', 'sent_at' => $this->stamp()]);
                if ($db->affectedRows() < 1) { continue; }                                    // another worker took it
                $limit--;
                if (! $this->stillConsents($s['kind'], $s['email'])) { $db->table('platform_email_sends')->where('id', $s['id'])->update(['status' => 'skipped', 'error' => 'no longer opted in']); $res['skipped']++; continue; }
                $ok = false; $err = null;
                try { $ok = $this->deliver($s['email'], (string) $s['name'], $c['subject'], $c['body']); } catch (\Throwable $e) { $err = 'error: ' . mb_substr($e->getMessage(), 0, 150); }
                $db->table('platform_email_sends')->where('id', $s['id'])->update($ok ? ['status' => 'sent', 'sent_at' => $this->stamp(), 'error' => null] : ['status' => 'failed', 'error' => $err ?? 'the mail server refused it']);
                $ok ? $res['sent']++ : $res['failed']++;
            }
            if ($db->table('platform_email_sends')->where('campaign_id', $c['id'])->whereIn('status', ['queued', 'sending'])->countAllResults() === 0) {
                $db->table('platform_email_campaigns')->where('id', $c['id'])->where('status', 'sending')->update(['status' => 'sent', 'finished_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
                $res['finished']++;
            }
        }
        return $res;
    }

    // ==== internals ===================================================================================================================================

    private function stillConsents(string $kind, string $email): bool
    {
        $db = db_connect();
        if ($kind === 'partner') { return $db->table('partners')->where('LOWER(email)', $email)->where('status', 'active')->where('email_marketing_opt_in', 1)->countAllResults() > 0; }
        return $db->query("SELECT COUNT(*) n FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE LOWER(u.email) = ? AND u.email_marketing_opt_in = 1 AND u.deleted_at IS NULL AND t.status = 'active'", [$email])->getRowArray()['n'] > 0;
    }

    private function deliver(string $to, string $name, string $subject, string $body, string $prefix = ''): bool
    {
        $first = EmailBody::firstName($name); $unsub = EmailUnsubscribe::url($to);
        $subj = $prefix . EmailBody::personalise($subject, $first);
        $r = EmailBody::render($body, $first, $unsub, $subj);
        $headers = ['List-Unsubscribe' => '<' . $unsub . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
        return $this->mailer !== null ? (bool) ($this->mailer)($to, $subj, $r['html'], $r['text'], $headers) : EmailService::send($to, $subj, $r['html'], $r['text'], null, $headers);
    }

    private function row(int $id): array
    {
        $r = db_connect()->table('platform_email_campaigns')->where('id', $id)->get()->getRowArray();
        if (! $r) { throw new \OutOfBoundsException('Campaign not found.'); }
        return $r;
    }

    private function shape(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'subject' => $r['subject'], 'segment' => $r['segment'], 'segment_label' => self::SEGMENTS[$r['segment']]['label'] ?? $r['segment'], 'status' => $r['status'],
            'recipients' => (int) $r['recipients'], 'sent' => (int) ($r['sent'] ?? 0), 'failed' => (int) ($r['failed'] ?? 0), 'skipped' => (int) ($r['skipped'] ?? 0), 'waiting' => (int) ($r['waiting'] ?? 0),
            'created_at' => $r['created_at'], 'started_at' => $r['started_at'], 'finished_at' => $r['finished_at']];
    }

    private function mask(string $email): string
    {
        [$u, $d] = explode('@', $email, 2) + [1 => ''];
        return mb_substr($u, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($u) - 1))) . '@' . $d;
    }

    private function audit(string $action, int $id, ?array $after, int $adminId): void
    {
        try {
            $t = db_connect()->table('users')->select('tenant_id')->where('id', $adminId)->get()->getRowArray();
            AuditLogger::log($action, 'platform_email', $id, null, $after, $t ? (int) $t['tenant_id'] : null, $adminId);
        } catch (\Throwable $e) { log_message('error', 'PlatformEmailService audit failed: ' . $e->getMessage()); }
    }
}
