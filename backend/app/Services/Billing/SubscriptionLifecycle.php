<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Services\Crm\NotificationService;
use App\Services\Email\EmailService;

/**
 * What happens at the END of a paid period (checkout is prepaid, so nothing renews by itself):
 *   expire() - a period that has ended puts the workspace back on the Free plan (unless it has already renewed: a newer period is running).
 *   remind() - 7, 3 and 1 day before the end, and once when the plan has ended, the workspace owners get an in-app note and an e-mail.
 * Both are safe to run any number of times: expiry is a state change and reminders are claimed in `subscription_reminders` (UNIQUE).
 */
final class SubscriptionLifecycle
{
    /** Recurring (legacy 'sub_…') rows get a few days' grace in case a charge notice is late; prepaid rows end exactly on time. */
    public const RECURRING_GRACE_DAYS = 3;

    /** @param callable|null $mailer fn(string $to, string $subject, string $html, string $text): bool  (test seam) */
    public function __construct(private $mailer = null, private readonly ?int $now = null) {}

    private function now(): int { return $this->now ?? time(); }

    /** @return int number of workspaces moved to the Free plan */
    public function expire(): int
    {
        $db = db_connect(); $moved = 0;
        $now = date('Y-m-d H:i:s', $this->now());
        $graceCut = date('Y-m-d H:i:s', $this->now() - self::RECURRING_GRACE_DAYS * 86400);
        $rows = $db->table('subscriptions')->where('status', 'active')->where('plan !=', 'free')->where('current_period_end IS NOT NULL')->where('current_period_end <', $now)->get()->getResultArray();
        foreach ($rows as $s) {
            if (str_starts_with((string) $s['razorpay_sub_id'], 'sub_') && (string) $s['current_period_end'] >= $graceCut) { continue; }
            $db->table('subscriptions')->where('id', $s['id'])->where('status', 'active')->update(['status' => 'downgraded', 'updated_at' => $now]);
            if ($db->affectedRows() < 1) { continue; }
            $tid = (int) $s['tenant_id'];
            $still = $db->table('subscriptions')->where('tenant_id', $tid)->where('status', 'active')->where('current_period_end >', $now)->countAllResults();
            if ($still === 0) { $db->table('tenants')->where('id', $tid)->update(['plan' => 'free', 'updated_at' => $now]); $moved++; }
        }
        return $moved;
    }

    /** @return array{sent:int, ended:int} */
    public function remind(): array
    {
        $db = db_connect(); $sent = 0; $ended = 0;
        $now = $this->now(); $nowS = date('Y-m-d H:i:s', $now);

        // 1) coming to an end within 7 days
        $soon = $db->table('subscriptions')->where('status', 'active')->where('plan !=', 'free')->where('current_period_end >', $nowS)->where('current_period_end <=', date('Y-m-d H:i:s', $now + 7 * 86400))->get()->getResultArray();
        foreach ($soon as $s) {
            $end = strtotime((string) $s['current_period_end'] . ' UTC');
            $left = ($end - $now) / 86400;
            $milestone = $left <= 1 ? 'd1' : ($left <= 3 ? 'd3' : 'd7');
            if ($this->renewed((int) $s['tenant_id'], (string) $s['current_period_end']) || ! $this->live((int) $s['tenant_id'])) { continue; }
            if ($this->claim((int) $s['id'], (int) $s['tenant_id'], $milestone)) {
                $this->tell((int) $s['tenant_id'], (int) $s['id'], $milestone, (string) $s['plan'], $end);
                $sent++;
            }
        }

        // 2) ended in the last 3 days and not renewed
        $gone = $db->table('subscriptions')->where('status', 'downgraded')->where('plan !=', 'free')->where('current_period_end <=', $nowS)->where('current_period_end >', date('Y-m-d H:i:s', $now - 3 * 86400))->get()->getResultArray();
        foreach ($gone as $s) {
            if ($this->renewed((int) $s['tenant_id'], (string) $s['current_period_end']) || ! $this->live((int) $s['tenant_id'])) { continue; }
            if ($this->claim((int) $s['id'], (int) $s['tenant_id'], 'ended')) {
                $this->tell((int) $s['tenant_id'], (int) $s['id'], 'ended', (string) $s['plan'], strtotime((string) $s['current_period_end'] . ' UTC'));
                $ended++;
            }
        }
        return ['sent' => $sent, 'ended' => $ended];
    }

    /** Suspended / cancelled workspaces are not nagged. */
    private function live(int $tenantId): bool
    {
        return db_connect()->table('tenants')->where('id', $tenantId)->where('status', 'active')->countAllResults() > 0;
    }

    /** A later period is already running for this workspace -> nothing to remind about. */
    private function renewed(int $tenantId, string $thisEnd): bool
    {
        return db_connect()->table('subscriptions')->where('tenant_id', $tenantId)->where('status', 'active')->where('current_period_end >', $thisEnd)->countAllResults() > 0;
    }

    private function claim(int $subId, int $tenantId, string $milestone): bool
    {
        $db = db_connect();
        $db->query('INSERT IGNORE INTO subscription_reminders (subscription_id, tenant_id, milestone, emailed, created_at) VALUES (?, ?, ?, 0, ?)', [$subId, $tenantId, $milestone, date('Y-m-d H:i:s', $this->now())]);
        return $db->affectedRows() > 0;
    }

    private function tell(int $tenantId, int $subId, string $milestone, string $plan, int $endTs): void
    {
        $db = db_connect();
        $t = $db->table('tenants')->select('name')->where('id', $tenantId)->get()->getRowArray() ?: ['name' => 'Your workspace'];
        $planName = ucfirst($plan);
        $date = (new \DateTime('@' . $endTs))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('j M Y');
        [$subject, $line, $eyebrow, $title, $tone] = match ($milestone) {
            'd1'    => ["Your {$planName} plan ends tomorrow", "Your {$planName} plan ends tomorrow ({$date}). Renew in Billing to keep your features.", 'Plan reminder', 'Your plan ends tomorrow', 'warn'],
            'd3'    => ["Your {$planName} plan ends in 3 days", "Your {$planName} plan ends on {$date}. Renew in Billing to keep your features.", 'Plan reminder', 'Your plan ends in 3 days', 'warn'],
            'd7'    => ["Your {$planName} plan ends on {$date}", "Your {$planName} plan ends on {$date}. Renew in Billing any time before then - the new period starts when this one ends, so no days are lost.", 'Plan reminder', 'Your plan ends in a week', 'info'],
            default => ["Your {$planName} plan has ended", "Your {$planName} plan ended on {$date} and the workspace is now on the Free plan. Your data is safe - renew in Billing to get your features back.", 'Plan ended', 'Your plan has ended', 'warn'],
        };
        $owners = $db->table('users')->select('id, name, email')->where('tenant_id', $tenantId)->where('role', 'owner')->where('deleted_at', null)->get()->getResultArray();
        $url = EmailService::appUrl() . '/#/settings/billing';
        $ended = $milestone === 'ended';
        $emailed = 0;
        foreach ($owners as $o) {
            try { NotificationService::notify($tenantId, (int) $o['id'], 'subscription', $line, '/settings/billing'); } catch (\Throwable) {}
            if (! filter_var($o['email'] ?? '', FILTER_VALIDATE_EMAIL)) { continue; }
            $first = \App\Services\Marketing\EmailBody::firstName((string) $o['name']);
            $m = \App\Services\Email\EmailLayout::build([
                'subject' => $subject, 'preheader' => $ended ? 'Your data is safe. Renew any time to get your features back.' : "Renew before {$date} and no paid days are lost.",
                'eyebrow' => $eyebrow, 'title' => $title,
                'blocks' => [['p', "Hi {$first},"], ['facts', [['Workspace', (string) $t['name']], ['Plan', $planName], [$ended ? 'Ended on' : 'Ends on', $date]]],
                             ['p', $ended ? 'Your workspace is on the Free plan now, and all your data is safe. Renew whenever you are ready to get your Starter, Growth or Pro features back.' : 'To keep every feature running without a break, renew from Billing. If you renew before the end date, the new period simply starts when this one ends, so you never lose a paid day.'],
                             ['button', $ended ? 'Renew my plan' : 'Renew in Billing', $url], ['callout', 'Prefer to talk first? Reply to this e-mail or open Help & support in the app.', $tone === 'warn' ? 'info' : 'info']],
            ]);
            $ok = $this->mailer !== null ? (bool) ($this->mailer)($o['email'], $subject, $m['html'], $m['text']) : EmailService::send($o['email'], $subject, $m['html'], $m['text']);
            if ($ok) { $emailed++; }
        }
        $db->table('subscription_reminders')->where('subscription_id', $subId)->where('milestone', $milestone)->update(['emailed' => $emailed > 0 ? 1 : 0]);
    }
}
