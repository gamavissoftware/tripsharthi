<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Billing\PlatformInvoiceService;
use App\Services\Billing\PlatformRazorpay;
use App\Services\Billing\Docs\Gst;
use App\Services\Email\PlatformMail;

/**
 * The admin app's home page: how the business is doing (revenue, MRR, growth, activation), what needs the team today, who joined / paid / wrote
 * in, and whether the platform's own plumbing (payments, mail, invoices) is healthy. Everything is read-only and comes from the same
 * computed customer rows as the Customers page, so the two always agree.
 *
 * Money: revenue and MRR are the PRICE without GST (`COALESCE(taxable_paise, amount_paise)`); GST collected comes from the invoices, net of credit
 * notes. Months are Indian calendar months.
 */
final class AdminDashboardService
{
    public const MONTHS = 12;
    public const WEEKS  = 12;

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(int $t): string { return date('Y-m-d H:i:s', $t); }

    public function build(): array
    {
        $db = db_connect(); $now = $this->ts();
        $dir = new CustomerDirectoryService($this->now);
        $rows = $dir->build(); $sum = $dir->summary($rows); $seg = $sum['segments'];
        $has = static fn (string $t): bool => $db->tableExists($t);
        $one = static fn (string $sql, array $b = []) => $db->query($sql, $b)->getRowArray();

        // ---- months (oldest first), India time ------------------------------------------------------------------------------------------------
        $months = [];
        $first = strtotime(gmdate('Y-m-01', $now + 19800) . ' UTC');
        for ($i = self::MONTHS - 1; $i >= 0; $i--) { $t = strtotime("-{$i} months", $first); $months[gmdate('Y-m', $t)] = ['month' => gmdate('Y-m', $t), 'label' => gmdate('M', $t), 'revenue' => 0, 'gst' => 0, 'signups' => 0, 'payments' => 0]; }
        $since = array_key_first($months) . '-01';
        $paidStatuses = "('active','cancelled','downgraded')";
        foreach ($db->query("SELECT DATE_FORMAT(COALESCE(current_period_start, created_at), '%Y-%m') m, SUM(COALESCE(taxable_paise, amount_paise)) r, COUNT(*) n FROM subscriptions WHERE amount_paise > 0 AND status IN {$paidStatuses} AND COALESCE(current_period_start, created_at) >= ? GROUP BY m", [$since])->getResultArray() as $r) {
            if (isset($months[$r['m']])) { $months[$r['m']]['revenue'] = (int) $r['r']; $months[$r['m']]['payments'] = (int) $r['n']; }
        }
        if ($has('platform_invoices')) {
            foreach ($db->query("SELECT DATE_FORMAT(issue_date, '%Y-%m') m, SUM(cgst + sgst + igst) g FROM platform_invoices WHERE issue_date >= ? GROUP BY m", [$since])->getResultArray() as $r) { if (isset($months[$r['m']])) { $months[$r['m']]['gst'] += (int) $r['g']; } }
            if ($has('platform_credit_notes')) { foreach ($db->query("SELECT DATE_FORMAT(issue_date, '%Y-%m') m, SUM(cgst + sgst + igst) g FROM platform_credit_notes WHERE issue_date >= ? GROUP BY m", [$since])->getResultArray() as $r) { if (isset($months[$r['m']])) { $months[$r['m']]['gst'] -= (int) $r['g']; } } }
        }
        foreach ($db->query("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) n FROM tenants WHERE deleted_at IS NULL AND created_at >= ? GROUP BY m", [$since])->getResultArray() as $r) { if (isset($months[$r['m']])) { $months[$r['m']]['signups'] = (int) $r['n']; } }
        $months = array_values($months);
        $thisM = $months[self::MONTHS - 1]; $lastM = $months[self::MONTHS - 2];

        // ---- weekly signups (Monday weeks) ----------------------------------------------------------------------------------------------------------
        $weeks = [];
        $monday = strtotime('monday this week', $now + 19800);
        for ($i = self::WEEKS - 1; $i >= 0; $i--) { $t = strtotime("-{$i} weeks", $monday); $weeks[gmdate('Y-m-d', $t)] = ['week' => gmdate('Y-m-d', $t), 'label' => gmdate('j M', $t), 'signups' => 0]; }
        foreach ($rows as $r) { $d = strtotime((string) $r['created_at'] . ' UTC') + 19800; $k = gmdate('Y-m-d', strtotime('monday this week', $d)); if (isset($weeks[$k])) { $weeks[$k]['signups']++; } }
        $weeks = array_values($weeks);

        // ---- funnel and plan mix ---------------------------------------------------------------------------------------------------------------------
        $total = count($rows);
        $activated = count(array_filter($rows, fn ($r) => $r['usage']['contacts'] > 0 || $r['usage']['trips'] > 0));
        $waOn = count(array_filter($rows, fn ($r) => $r['whatsapp'] === 'active'));
        $paying = $seg['paying'];
        $funnel = [['key' => 'registered', 'label' => 'Signed up', 'n' => $total], ['key' => 'activated', 'label' => 'Added data', 'n' => $activated], ['key' => 'whatsapp', 'label' => 'WhatsApp connected', 'n' => $waOn], ['key' => 'paying', 'label' => 'Paying', 'n' => $paying]];
        $mix = ['free' => 0, 'starter' => 0, 'growth' => 0, 'pro' => 0]; foreach ($rows as $r) { if (isset($mix[$r['plan']])) { $mix[$r['plan']]++; } }

        // ---- needs attention -------------------------------------------------------------------------------------------------------------------------
        $inbox = ['enquiries' => $has('contact_enquiries') ? (int) $one("SELECT COUNT(*) n FROM contact_enquiries WHERE status = 'new'")['n'] : 0,
            'unread_chats' => $has('chat_sessions') ? (int) $one("SELECT COUNT(*) n FROM chat_sessions WHERE status = 'open' AND unread_agent > 0")['n'] : 0,
            'support' => $has('support_tickets') ? (int) $one("SELECT COUNT(*) n FROM support_tickets WHERE status = 'open' AND last_from = 'customer'")['n'] : 0];
        $inv = new PlatformInvoiceService($this->now);
        $missing = $inv->missing();
        $invProblems = PlatformInvoiceService::validate($inv->settings());
        $notEmailed = $has('platform_invoices') ? (int) $one('SELECT COUNT(*) n FROM platform_invoices WHERE emailed_at IS NULL')['n'] : 0;
        $rz = PlatformRazorpay::status(); $mail = PlatformMail::status();
        $commissions = $has('partner_commissions') ? $one("SELECT COALESCE(SUM(CASE WHEN status = 'pending' AND payable_after > ? THEN amount_paise END), 0) pending, COALESCE(SUM(CASE WHEN status = 'pending' AND payable_after <= ? THEN amount_paise END), 0) available, COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_paise END), 0) paid FROM partner_commissions", [$this->stamp($now), $this->stamp($now)]) : ['pending' => 0, 'available' => 0, 'paid' => 0];
        $partnersToPay = $has('partner_commissions') ? (int) $one("SELECT COUNT(DISTINCT partner_id) n FROM partner_commissions WHERE status = 'pending' AND payable_after <= ?", [$this->stamp($now)])['n'] : 0;
        $count = static fn (string $key) => count(array_filter($rows, fn ($r) => in_array($key, array_column($r['flags'], 'key'), true)));
        $quietPaid = count(array_filter($rows, fn ($r) => in_array('inactive', array_column($r['flags'], 'key'), true) && in_array($r['billing']['state'], ['paying', 'complimentary'], true)));
        $a = [];
        $add = static function (array &$a, string $key, string $tone, string $label, int $n, string $sub, string $to) { if ($n > 0) { $a[] = ['key' => $key, 'tone' => $tone, 'label' => $label, 'count' => $n, 'sub' => $sub, 'to' => $to]; } };
        $add($a, 'halted', 'danger', 'Payment failed', $seg['halted'], 'customers whose plan payment failed', '/admin/customers?segment=halted');
        $add($a, 'expiring', 'warn', 'Plans ending within 7 days', $seg['expiring'], 'reach out before they lapse', '/admin/customers?segment=expiring');
        $add($a, 'support', 'danger', 'Support requests waiting for a reply', $inbox['support'], 'customers are waiting on you', '/admin/support');
        $add($a, 'chats', 'warn', 'Unread live chats', $inbox['unread_chats'], 'website visitors', '/admin/inbox');
        $add($a, 'enquiries', 'info', 'New website enquiries', $inbox['enquiries'], 'from the contact form', '/admin/inbox');
        $add($a, 'wa', 'danger', 'WhatsApp suspended', $count('wa_problem'), 'workspaces Meta has suspended', '/admin/whatsapp');
        $add($a, 'missing_invoices', 'danger', 'Paid, but no GST invoice', count($missing), 'issue them from GST invoices', '/admin/invoices');
        $add($a, 'invoice_mail', 'warn', 'Invoices not e-mailed', $notEmailed, 'resend from GST invoices', '/admin/invoices');
        $add($a, 'quiet', 'warn', 'Paying customers gone quiet', $quietPaid, 'not seen for 14+ days', '/admin/customers?segment=inactive');
        $add($a, 'not_activated', 'info', 'Customers who never set up', $count('not_activated'), 'signed up 3+ days ago, nothing added', '/admin/customers?segment=not_activated');
        try { $uw = AppUpdateWatch::status(); } catch (\Throwable $e) { $uw = ['state' => 'unknown']; }
        $add($a, 'app_update', 'danger', 'Phone app may be broken by an update', $uw['state'] === 'warn' ? (int) $uw['silent'] : 0, 'phones have stopped reporting since the newest update: open the app on your phone', '/admin/mobile-app');
        $add($a, 'payouts', 'info', 'Partners with commission ready to pay', $partnersToPay, '₹' . number_format(((int) $commissions['available']) / 100, 0) . ' is past its hold period', '/admin/partners');
        $setup = [];
        if (! $rz['configured']) { $setup[] = ['key' => 'razorpay', 'label' => 'Razorpay is not set up: customers cannot pay', 'to' => '/admin/payments']; }
        elseif ($rz['mock']) { $setup[] = ['key' => 'razorpay_mock', 'label' => 'Razorpay mock mode is ON: no real money moves', 'to' => '/admin/payments']; }
        elseif (! $rz['webhook_set']) { $setup[] = ['key' => 'webhook', 'label' => 'Razorpay webhook secret missing: a payment made while the page is closed will not open the plan', 'to' => '/admin/payments']; }
        if (! $mail['configured']) { $setup[] = ['key' => 'mail', 'label' => 'No mail server: e-mails (invoices, support, reminders) cannot be delivered', 'to' => '/admin/mail']; }
        if ($invProblems) { $setup[] = ['key' => 'invoice_settings', 'label' => 'Invoice settings incomplete: GST invoices cannot be issued', 'to' => '/admin/invoices']; }

        // ---- who joined / paid / wrote in -----------------------------------------------------------------------------------------------------------
        $signups = array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'owner' => $r['owner']['name'], 'email' => $r['owner']['email'], 'plan' => $r['plan'], 'created_at' => $r['created_at'], 'partner' => $r['partner']], array_slice($rows, 0, 6));   // build() is newest first
        $payments = $has('platform_invoices') ? $db->query('SELECT id, number, buyer_name, tenant_id, plan, total, issue_date FROM platform_invoices ORDER BY id DESC LIMIT 6')->getResultArray() : [];
        foreach ($payments as &$p) { $p['id'] = (int) $p['id']; $p['tenant_id'] = (int) $p['tenant_id']; $p['total'] = (int) $p['total']; } unset($p);
        $tickets = $has('support_tickets') ? $db->query("SELECT st.id, st.subject, st.status, st.priority, st.tenant_id, t.name tenant_name, st.last_from, st.last_message_at FROM support_tickets st LEFT JOIN tenants t ON t.id = st.tenant_id WHERE st.status IN ('open','pending') ORDER BY (st.status = 'open' AND st.last_from = 'customer') DESC, st.last_message_at DESC LIMIT 5")->getResultArray() : [];
        foreach ($tickets as &$tk) { $tk['id'] = (int) $tk['id']; $tk['tenant_id'] = (int) $tk['tenant_id']; $tk['code'] = 'TS-' . str_pad((string) $tk['id'], 5, '0', STR_PAD_LEFT); } unset($tk);
        $access = $has('impersonation_sessions') ? $db->query('SELECT s.id, s.tenant_id, t.name tenant_name, a.name admin_name, s.reason, s.read_only, s.created_at, s.ended_at FROM impersonation_sessions s LEFT JOIN tenants t ON t.id = s.tenant_id LEFT JOIN users a ON a.id = s.admin_user_id ORDER BY s.id DESC LIMIT 4')->getResultArray() : [];
        $liveAccess = $has('impersonation_sessions') ? (int) $one('SELECT COUNT(*) n FROM impersonation_sessions WHERE ended_at IS NULL AND expires_at > ?', [$this->stamp($now)])['n'] : 0;

        // ---- top customers, opportunities ------------------------------------------------------------------------------------------------------------
        $byPaid = $rows; usort($byPaid, fn ($x, $y) => $y['billing']['lifetime_paise'] <=> $x['billing']['lifetime_paise']);
        $top = array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'plan' => $r['plan'], 'lifetime_paise' => $r['billing']['lifetime_paise'], 'contacts' => $r['usage']['contacts']], array_slice(array_filter($byPaid, fn ($r) => $r['billing']['lifetime_paise'] > 0), 0, 5));
        $opps = array_values(array_filter($rows, fn ($r) => in_array('near_limit', array_column($r['flags'], 'key'), true) && $r['status'] === 'active'));
        $opps = array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'plan' => $r['plan'], 'contacts' => $r['usage']['contacts'], 'limit' => $r['usage']['contacts_limit']], array_slice($opps, 0, 5));

        // ---- usage and partners ------------------------------------------------------------------------------------------------------------------------
        $wa = $has('messages') ? $one("SELECT COALESCE(SUM(direction = 'out'), 0) sent, COALESCE(SUM(direction = 'in'), 0) received, COALESCE(SUM(direction = 'out' AND status = 'failed'), 0) failed FROM messages WHERE created_at >= ?", [$this->stamp($now - 30 * 86400)]) : ['sent' => 0, 'received' => 0, 'failed' => 0];
        $partners = $has('partners') ? ['active' => (int) $one("SELECT COUNT(*) n FROM partners WHERE status = 'active'")['n'], 'referred' => (int) $one('SELECT COUNT(*) n FROM tenants WHERE referred_partner_id IS NOT NULL AND deleted_at IS NULL')['n'],
            'pending_paise' => (int) $commissions['pending'], 'available_paise' => (int) $commissions['available'], 'paid_paise' => (int) $commissions['paid']] : ['active' => 0, 'referred' => 0, 'pending_paise' => 0, 'available_paise' => 0, 'paid_paise' => 0];
        $totalUsers = array_sum(array_map(fn ($r) => $r['usage']['users'], $rows));

        $pct = static fn (int $now, int $prev): ?float => $prev > 0 ? round(($now - $prev) * 100 / $prev, 1) : null;
        return [
            'generated_at' => $this->stamp($now),
            'headline' => ['customers' => $total, 'new_7d' => $seg['new'], 'new_30d' => $sum['new_30d'], 'paying' => $paying, 'complimentary' => $seg['complimentary'], 'free' => $seg['free'], 'lapsed' => $seg['lapsed'], 'halted' => $seg['halted'], 'suspended' => $seg['suspended'],
                'mrr_paise' => $sum['mrr_paise'], 'arr_paise' => $sum['mrr_paise'] * 12, 'lifetime_paise' => $sum['lifetime_paise'], 'paying_pct' => $total > 0 ? round($paying * 100 / $total, 1) : 0.0,
                'revenue_month_paise' => $thisM['revenue'], 'revenue_prev_paise' => $lastM['revenue'], 'revenue_change_pct' => $pct($thisM['revenue'], $lastM['revenue']), 'gst_month_paise' => $thisM['gst'], 'attention' => $seg['attention']],
            'months' => $months, 'weeks' => $weeks, 'funnel' => $funnel, 'plan_mix' => $mix,
            'attention' => $a, 'setup' => $setup,
            'recent' => ['signups' => $signups, 'payments' => $payments, 'tickets' => $tickets, 'access' => $access],
            'top_customers' => $top, 'opportunities' => $opps,
            'usage' => ['users' => $totalUsers, 'contacts' => array_sum(array_map(fn ($r) => $r['usage']['contacts'], $rows)), 'trips' => array_sum(array_map(fn ($r) => $r['usage']['trips'], $rows)), 'bookings' => array_sum(array_map(fn ($r) => $r['usage']['bookings'], $rows)),
                'wa_sent' => (int) $wa['sent'], 'wa_received' => (int) $wa['received'], 'wa_failed' => (int) $wa['failed']],
            'partners' => $partners,
            'system' => ['razorpay' => ['configured' => $rz['configured'], 'mode' => $rz['mode'], 'mock' => $rz['mock'], 'webhook_set' => $rz['webhook_set']], 'mail' => ['configured' => $mail['configured'], 'host' => $mail['host'], 'from' => $mail['from_email']],
                'invoices' => ['ok' => $invProblems === [], 'problems' => $invProblems, 'missing' => count($missing), 'gstin' => $inv->settings()['gstin']], 'live_support_sessions' => $liveAccess],
        ];
    }
}
