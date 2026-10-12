<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Services\Email\EmailService;

/**
 * Customer -> TripSarthi team support tickets.
 *
 * Who sees what: a customer sees only their OWN workspace's tickets (owners/admins all of them, other roles only the ones they raised),
 * never `note` messages (the team's internal notes) and never another workspace's tickets - every customer query is keyed by tenant.
 * Status: open (team's turn) -> pending (waiting on the customer) -> resolved -> closed. A customer reply reopens open/pending/resolved;
 * a closed ticket takes no more replies (open a new one).
 * E-mails: the team is told about a new ticket and about customer replies (at most once per 10 minutes per ticket); the customer is e-mailed
 * every team reply. They are queued and sent AFTER the HTTP response (see deliverPending) so a slow mail server never slows the request.
 * Errors: InvalidArgumentException = bad input (422), OutOfBoundsException = not found / not yours (404), DomainException = not allowed now (409).
 */
final class SupportService
{
    public const CATEGORIES = ['billing' => 'Billing & plans', 'whatsapp' => 'WhatsApp', 'technical' => 'Something is not working', 'account' => 'Account & login', 'howto' => 'How do I…?', 'feature' => 'Feature request', 'other' => 'Other'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES   = ['open', 'pending', 'resolved', 'closed'];
    /** The reply target: a customer waiting on us for longer than this is "past target" (shown red in the admin app). */
    public const TARGET_HOURS = 24;

    private array $pending = [];

    /** @param callable|null $mailer fn(string $to, string $subject, string $html, string $text, ?string $replyTo): bool  (test seam) */
    public function __construct(private $mailer = null, private readonly ?int $now = null) {}

    private function stamp(): string { return date('Y-m-d H:i:s', $this->now ?? time()); }
    public static function number(int $id): string { return 'TS-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT); }

    // ==== customer side ===========================================================================================================================

    public function create(int $tenantId, int $userId, array $in): array
    {
        $db = db_connect();
        $subject = $this->clean((string) ($in['subject'] ?? ''), 160);
        $message = $this->text((string) ($in['message'] ?? ''), 5000);
        $category = (string) ($in['category'] ?? 'other');
        if (! isset(self::CATEGORIES[$category])) { $category = 'other'; }
        if (mb_strlen($subject) < 5) { throw new \InvalidArgumentException('Give the request a short title (at least 5 characters).'); }
        if (mb_strlen($message) < 10) { throw new \InvalidArgumentException('Please describe the problem in a few words (at least 10 characters).'); }

        $since = date('Y-m-d H:i:s', ($this->now ?? time()) - 600);
        $dup = $db->table('support_tickets')->select('support_tickets.id')->join('support_messages m', 'm.ticket_id = support_tickets.id')
            ->where('support_tickets.tenant_id', $tenantId)->where('support_tickets.user_id', $userId)->where('support_tickets.subject', $subject)->where('m.body', $message)->where('support_tickets.created_at >=', $since)->get()->getRowArray();
        if ($dup) { return $this->detailFor($tenantId, $userId, 'owner', (int) $dup['id']); }   // double click: same ticket, not a second one
        if ($db->table('support_tickets')->where('user_id', $userId)->where('created_at >=', $since)->countAllResults() >= 3) { throw new \DomainException('You have just sent a few requests. Please wait a few minutes, or reply on an existing one.'); }
        if ($db->table('support_tickets')->where('tenant_id', $tenantId)->where('created_at >=', date('Y-m-d H:i:s', ($this->now ?? time()) - 86400))->countAllResults() >= 15) { throw new \DomainException('Daily limit of support requests reached for your workspace. Reply on an existing request instead.'); }

        $now = $this->stamp();
        $db->table('support_tickets')->insert(['tenant_id' => $tenantId, 'user_id' => $userId, 'subject' => $subject, 'category' => $category, 'priority' => 'normal', 'status' => 'open',
            'last_from' => 'customer', 'last_message_at' => $now, 'team_unread' => 1, 'customer_unread' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $db->insertID();
        $this->addMessage($id, 'customer', $userId, $message);
        $this->queueTeamMail($id, 'New support request', $message, true);
        return $this->detailFor($tenantId, $userId, 'owner', $id);
    }

    /** @return list<array> */
    public function listFor(int $tenantId, int $userId, string $role, string $filter = 'all'): array
    {
        $db = db_connect();
        $b = $db->table('support_tickets')->where('tenant_id', $tenantId);
        if (! $this->manager($role)) { $b->where('user_id', $userId); }
        if ($filter === 'open') { $b->whereIn('status', ['open', 'pending', 'resolved']); }
        if ($filter === 'closed') { $b->where('status', 'closed'); }
        $rows = $b->orderBy('last_message_at', 'DESC')->limit(200)->get()->getResultArray();
        return array_map(fn (array $r) => $this->customerRow($r), $rows);
    }

    public function detailFor(int $tenantId, int $userId, string $role, int $id): array
    {
        $t = $this->owned($tenantId, $userId, $role, $id);
        if ((int) $t['customer_unread'] === 1) { db_connect()->table('support_tickets')->where('id', $id)->update(['customer_unread' => 0]); }
        $msgs = db_connect()->table('support_messages')->where('ticket_id', $id)->where('author_type !=', 'note')->orderBy('id')->get()->getResultArray();
        return $this->customerRow($t) + ['messages' => array_map(static fn ($m) => ['id' => (int) $m['id'], 'from' => $m['author_type'], 'body' => $m['body'], 'at' => $m['created_at']], $msgs)];
    }

    public function reply(int $tenantId, int $userId, string $role, int $id, string $body): array
    {
        $t = $this->owned($tenantId, $userId, $role, $id);
        $body = $this->text($body, 5000);
        if (mb_strlen($body) < 1) { throw new \InvalidArgumentException('Write your reply first.'); }
        if ($t['status'] === 'closed') { throw new \DomainException('This request is closed. Please open a new one.'); }
        $db = db_connect();
        if ($db->table('support_messages')->where('author_type', 'customer')->where('author_id', $userId)->where('created_at >=', date('Y-m-d H:i:s', ($this->now ?? time()) - 3600))->countAllResults() >= 20) { throw new \DomainException('You are sending messages very quickly. Please wait a little.'); }
        $this->addMessage($id, 'customer', $userId, $body);
        $db->table('support_tickets')->where('id', $id)->update(['status' => 'open', 'last_from' => 'customer', 'last_message_at' => $this->stamp(), 'team_unread' => 1, 'closed_at' => null, 'updated_at' => $this->stamp()]);
        $this->queueTeamMail($id, 'Customer replied', $body, false);
        return $this->detailFor($tenantId, $userId, $role, $id);
    }

    public function close(int $tenantId, int $userId, string $role, int $id): array
    {
        $this->owned($tenantId, $userId, $role, $id);
        $this->setStatus($id, 'closed', 'customer');
        return $this->detailFor($tenantId, $userId, $role, $id);
    }

    /** Unread-by-the-customer count for the menu badge. */
    public function unreadFor(int $tenantId, int $userId, string $role): int
    {
        $b = db_connect()->table('support_tickets')->where('tenant_id', $tenantId)->where('customer_unread', 1);
        if (! $this->manager($role)) { $b->where('user_id', $userId); }
        return $b->countAllResults();
    }

    // ==== team (admin app) ========================================================================================================================

    /** @return array{rows:list<array>,total:int,page:int,per_page:int,counts:array} */
    public function adminList(string $status = '', string $q = '', string $assigned = '', int $page = 1, int $perPage = 30): array
    {
        $db = db_connect(); $page = max(1, $page); $perPage = max(5, min(100, $perPage));
        $w = ['1=1']; $b = [];
        if (in_array($status, self::STATUSES, true)) { $w[] = 't.status = ?'; $b[] = $status; }
        elseif ($status === 'active') { $w[] = "t.status IN ('open','pending')"; }
        elseif ($status === 'unanswered') { $w[] = "t.status = 'open' AND t.last_from = 'customer'"; }
        if ($assigned !== '' && ctype_digit($assigned)) { $w[] = 't.assigned_to = ?'; $b[] = (int) $assigned; }
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $w[] = '(t.subject LIKE ? OR tn.name LIKE ? OR u.email LIKE ? OR u.name LIKE ? OR CONCAT("TS-", LPAD(t.id, 5, "0")) LIKE ?)'; array_push($b, $like, $like, $like, $like, $like);
        }
        $where = implode(' AND ', $w);
        $from = 'FROM support_tickets t JOIN tenants tn ON tn.id = t.tenant_id JOIN users u ON u.id = t.user_id';
        $total = (int) $db->query("SELECT COUNT(*) n {$from} WHERE {$where}", $b)->getRowArray()['n'];
        $rows = $db->query("SELECT t.*, tn.name tenant_name, tn.plan tenant_plan, u.name user_name, u.email user_email, a.name assignee_name {$from} LEFT JOIN users a ON a.id = t.assigned_to WHERE {$where}
            ORDER BY (t.status = 'open' AND t.last_from = 'customer') DESC, FIELD(t.priority,'urgent','high','normal','low'), t.last_message_at DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $b)->getResultArray();
        return ['rows' => array_map(fn ($r) => $this->teamRow($r), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'counts' => $this->counts()];
    }

    /**
     * Headline numbers for the support inbox: what waits on us (and for how long), what waits on the customer, how fast we answer.
     * "Waiting" = open and the customer wrote last. Average first response covers requests raised in the last 30 days that have a team reply.
     */
    public function counts(): array
    {
        $db = db_connect(); $now = $this->stamp(); $ts = $this->now ?? time();
        $r = $db->query("SELECT SUM(status = 'open' AND last_from = 'customer') unanswered, SUM(status IN ('open','pending')) active, SUM(status = 'pending') pending, SUM(status = 'open') open_n, SUM(status = 'resolved') resolved, SUM(status = 'closed') closed_n, COUNT(*) total,
                                MIN(CASE WHEN status = 'open' AND last_from = 'customer' THEN last_message_at END) oldest_waiting,
                                SUM(status = 'open' AND last_from = 'customer' AND last_message_at < ?) past_target,
                                SUM(status IN ('resolved','closed') AND updated_at >= ?) resolved_7d
                         FROM support_tickets", [date('Y-m-d H:i:s', $ts - self::TARGET_HOURS * 3600), date('Y-m-d H:i:s', $ts - 7 * 86400)])->getRowArray();
        $fr = $db->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, m.first_team)) mins, COUNT(*) n FROM support_tickets t JOIN (SELECT ticket_id, MIN(created_at) first_team FROM support_messages WHERE author_type = 'team' GROUP BY ticket_id) m ON m.ticket_id = t.id
                          WHERE t.created_at >= ?", [date('Y-m-d H:i:s', $ts - 30 * 86400)])->getRowArray();
        return ['unanswered' => (int) ($r['unanswered'] ?? 0), 'active' => (int) ($r['active'] ?? 0), 'resolved' => (int) ($r['resolved'] ?? 0), 'total' => (int) ($r['total'] ?? 0),
            'pending' => (int) ($r['pending'] ?? 0), 'open' => (int) ($r['open_n'] ?? 0), 'closed' => (int) ($r['closed_n'] ?? 0), 'past_target' => (int) ($r['past_target'] ?? 0), 'resolved_7d' => (int) ($r['resolved_7d'] ?? 0),
            'oldest_waiting_hours' => $r['oldest_waiting'] ? (int) max(0, floor(($ts - strtotime($r['oldest_waiting'] . ' UTC')) / 3600)) : null,
            'avg_first_response_minutes' => ($fr['n'] ?? 0) > 0 ? (int) round((float) $fr['mins']) : null, 'target_hours' => self::TARGET_HOURS];
    }

    public function adminDetail(int $id): array
    {
        $db = db_connect();
        $t = $db->query('SELECT t.*, tn.name tenant_name, tn.plan tenant_plan, tn.status tenant_status, u.name user_name, u.email user_email, u.role user_role, a.name assignee_name
            FROM support_tickets t JOIN tenants tn ON tn.id = t.tenant_id JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.assigned_to WHERE t.id = ?', [$id])->getRowArray();
        if (! $t) { throw new \OutOfBoundsException('Ticket not found.'); }
        if ((int) $t['team_unread'] === 1) { $db->table('support_tickets')->where('id', $id)->update(['team_unread' => 0]); }
        $msgs = $db->query('SELECT m.id, m.author_type, m.author_id, m.body, m.created_at, u.name author_name FROM support_messages m LEFT JOIN users u ON u.id = m.author_id WHERE m.ticket_id = ? ORDER BY m.id', [$id])->getResultArray();
        $others = (int) $db->table('support_tickets')->where('tenant_id', $t['tenant_id'])->where('id !=', $id)->countAllResults();
        $first = $db->query("SELECT MIN(created_at) f FROM support_messages WHERE ticket_id = ? AND author_type = 'team'", [$id])->getRowArray()['f'] ?? null;
        $prev = $db->query('SELECT id, subject, status, created_at FROM support_tickets WHERE tenant_id = ? AND id <> ? ORDER BY id DESC LIMIT 5', [$t['tenant_id'], $id])->getResultArray();
        $health = (new \App\Services\Admin\CustomerDirectoryService($this->now))->one((int) $t['tenant_id']);   // the customer card: billing state, last seen, flags
        return $this->teamRow($t) + ['tenant_status' => $t['tenant_status'], 'user_role' => $t['user_role'], 'other_tickets' => $others,
            'first_response_minutes' => $first ? (int) max(0, round((strtotime($first . ' UTC') - strtotime($t['created_at'] . ' UTC')) / 60)) : null,
            'previous' => array_map(static fn ($p) => ['id' => (int) $p['id'], 'number' => self::number((int) $p['id']), 'subject' => $p['subject'], 'status' => $p['status'], 'created_at' => $p['created_at']], $prev),
            'customer' => $health === null ? null : ['billing' => $health['billing'], 'last_active' => $health['last_active'], 'flags' => $health['flags'], 'usage' => $health['usage'], 'whatsapp' => $health['whatsapp'], 'owner' => $health['owner'], 'created_at' => $health['created_at']],
            'messages' => array_map(static fn ($m) => ['id' => (int) $m['id'], 'from' => $m['author_type'], 'name' => $m['author_name'], 'body' => $m['body'], 'at' => $m['created_at']], $msgs)];
    }

    /** A public reply to the customer. $status afterwards: pending (default, waiting on them) | resolved | open. */
    public function adminReply(int $id, int $adminId, string $body, string $status = 'pending'): array
    {
        $t = $this->ticket($id);
        $body = $this->text($body, 5000);
        if (mb_strlen($body) < 1) { throw new \InvalidArgumentException('Write the reply first.'); }
        if ($t['status'] === 'closed') { throw new \DomainException('This ticket is closed. The customer has to open a new one.'); }
        if (! in_array($status, ['pending', 'resolved', 'open'], true)) { $status = 'pending'; }
        $this->addMessage($id, 'team', $adminId, $body);
        $now = $this->stamp();
        db_connect()->table('support_tickets')->where('id', $id)->update(['status' => $status, 'last_from' => 'team', 'last_message_at' => $now, 'customer_unread' => 1, 'team_unread' => 0,
            'assigned_to' => $t['assigned_to'] ?: $adminId, 'updated_at' => $now]);
        $this->queueCustomerMail($id, $body);
        return $this->adminDetail($id);
    }

    public function adminNote(int $id, int $adminId, string $body): array
    {
        $this->ticket($id);
        $body = $this->text($body, 5000);
        if (mb_strlen($body) < 1) { throw new \InvalidArgumentException('Write the note first.'); }
        $this->addMessage($id, 'note', $adminId, $body);
        db_connect()->table('support_tickets')->where('id', $id)->update(['updated_at' => $this->stamp()]);
        return $this->adminDetail($id);
    }

    /** @param array{status?:string,priority?:string,assigned_to?:int|string|null} $in */
    public function adminUpdate(int $id, int $adminId, array $in): array
    {
        $t = $this->ticket($id); $db = db_connect(); $set = [];
        if (isset($in['priority'])) {
            if (! in_array($in['priority'], self::PRIORITIES, true)) { throw new \InvalidArgumentException('Unknown priority.'); }
            $set['priority'] = $in['priority'];
        }
        if (array_key_exists('assigned_to', $in)) {
            $a = $in['assigned_to'];
            if ($a === null || $a === '' || (int) $a === 0) { $set['assigned_to'] = null; }
            else {
                $ok = $db->table('users')->where('id', (int) $a)->where('is_platform_admin', 1)->where('deleted_at', null)->countAllResults();
                if (! $ok) { throw new \InvalidArgumentException('You can only assign a ticket to a TripSarthi team member.'); }
                $set['assigned_to'] = (int) $a;
            }
        }
        if ($set) { $db->table('support_tickets')->where('id', $id)->update($set + ['updated_at' => $this->stamp()]); }
        if (isset($in['status']) && $in['status'] !== $t['status']) {
            if (! in_array($in['status'], self::STATUSES, true)) { throw new \InvalidArgumentException('Unknown status.'); }
            $this->setStatus($id, $in['status'], 'team');
        }
        return $this->adminDetail($id);
    }

    /** @return list<array{id:int,name:string}> platform admins, for the "assign to" box */
    public function team(): array
    {
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], db_connect()->table('users')->select('id, name')->where('is_platform_admin', 1)->where('deleted_at', null)->orderBy('name')->get()->getResultArray());
    }

    // ==== e-mail (after the response) =============================================================================================================

    /** Send the queued e-mails. Never throws. Call after the response is flushed. */
    public function deliverPending(): int
    {
        $sent = 0;
        foreach ($this->pending as $job) {
            try { if ($job()) { $sent++; } } catch (\Throwable $e) { log_message('error', 'SupportService mail failed: ' . $e->getMessage()); }
        }
        $this->pending = [];
        return $sent;
    }

    private function send(string $to, string $subject, string $html, string $text, ?string $replyTo = null): bool
    {
        return $this->mailer !== null ? (bool) ($this->mailer)($to, $subject, $html, $text, $replyTo) : EmailService::send($to, $subject, $html, $text, $replyTo);
    }

    private function queueTeamMail(int $id, string $what, string $body, bool $isNew): void
    {
        $this->pending[] = function () use ($id, $what, $body, $isNew): bool {
            $db = db_connect();
            $t = $db->query('SELECT t.*, tn.name tenant_name, tn.plan tenant_plan, u.name user_name, u.email user_email FROM support_tickets t JOIN tenants tn ON tn.id = t.tenant_id JOIN users u ON u.id = t.user_id WHERE t.id = ?', [$id])->getRowArray();
            if (! $t) { return false; }
            if (! $isNew && $t['team_notified_at'] && strtotime((string) $t['team_notified_at'] . ' UTC') > ($this->now ?? time()) - 600) { return false; }   // at most one e-mail per 10 minutes per ticket
            $db->table('support_tickets')->where('id', $id)->update(['team_notified_at' => $this->stamp()]);
            $no = self::number($id);
            $link = rtrim((string) env('ADMIN_URL', 'https://admin.tripsarthi.com'), '/') . '/#/admin/support/' . $id;
            $m = \App\Services\Email\EmailLayout::build(['subject' => $what . ' ' . $no, 'preheader' => mb_substr($t['user_name'] . ': ' . preg_replace('/\s+/', ' ', $body), 0, 110),
                'eyebrow' => 'Support · ' . $no, 'title' => $what,
                'blocks' => [['facts', [['Request', $t['subject']], ['From', $t['user_name'] . ' <' . $t['user_email'] . '>'], ['Workspace', $t['tenant_name'] . ' (' . $t['tenant_plan'] . ' plan)']]],
                             ['callout', mb_substr($body, 0, 1500), 'info'], ['button', 'Open in the admin app', $link]]]);
            $html = $m['html']; $text = $m['text'];
            $to = trim((string) env('CONTACT_NOTIFY_EMAIL', 'manglesh@gamavis.com'));
            return $this->send($to, '[' . $no . '] ' . $what . ': ' . mb_substr(str_replace(["\r", "\n"], ' ', $t['subject']), 0, 80), $html, $text, $t['user_email']);
        };
    }

    private function queueCustomerMail(int $id, string $body): void
    {
        $this->pending[] = function () use ($id, $body): bool {
            $t = db_connect()->query('SELECT t.subject, u.name user_name, u.email user_email FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?', [$id])->getRowArray();
            if (! $t || ! filter_var($t['user_email'], FILTER_VALIDATE_EMAIL)) { return false; }
            $no = self::number($id); $url = EmailService::appUrl() . '/#/support/' . $id;
            $first = \App\Services\Marketing\EmailBody::firstName((string) $t['user_name']);
            $m = \App\Services\Email\EmailLayout::build(['subject' => 'Re: ' . $t['subject'], 'preheader' => mb_substr(preg_replace('/\s+/', ' ', $body), 0, 110),
                'eyebrow' => 'Support · ' . $no, 'title' => 'The TripSarthi team replied',
                'blocks' => [['p', "Hi {$first}, here is our reply to your request."], ['facts', [['Your request', $t['subject']], ['Reference', $no]]],
                             ['callout', mb_substr($body, 0, 3000), 'ok'], ['p', 'Need to add something? Open the request and reply there, and it goes straight back to the team.'], ['button', 'Open the request', $url]]]);
            $html = $m['html']; $text = $m['text'];
            return $this->send($t['user_email'], '[' . $no . '] Re: ' . mb_substr(str_replace(["\r", "\n"], ' ', $t['subject']), 0, 100), $html, $text, null);
        };
    }

    // ==== internals ================================================================================================================================

    private function manager(string $role): bool { return in_array($role, ['owner', 'admin'], true); }

    private function ticket(int $id): array
    {
        $t = db_connect()->table('support_tickets')->where('id', $id)->get()->getRowArray();
        if (! $t) { throw new \OutOfBoundsException('Ticket not found.'); }
        return $t;
    }

    /** The ticket if it belongs to this workspace AND this user may see it; otherwise "not found" (never reveal that it exists). */
    private function owned(int $tenantId, int $userId, string $role, int $id): array
    {
        $t = db_connect()->table('support_tickets')->where('id', $id)->where('tenant_id', $tenantId)->get()->getRowArray();
        if (! $t || (! $this->manager($role) && (int) $t['user_id'] !== $userId)) { throw new \OutOfBoundsException('Request not found.'); }
        return $t;
    }

    private function setStatus(int $id, string $status, string $by): void
    {
        $now = $this->stamp(); $set = ['status' => $status, 'updated_at' => $now, 'closed_at' => $status === 'closed' ? $now : null];
        if ($by === 'team') { $set['customer_unread'] = 1; }
        db_connect()->table('support_tickets')->where('id', $id)->update($set);
        $label = ['open' => 'reopened', 'pending' => 'marked as waiting for your reply', 'resolved' => 'marked as resolved', 'closed' => 'closed'][$status];
        $this->addMessage($id, 'event', null, 'Request ' . $label . ($by === 'customer' ? ' by you.' : ' by the TripSarthi team.'));
    }

    private function addMessage(int $ticketId, string $type, ?int $authorId, string $body): void
    {
        db_connect()->table('support_messages')->insert(['ticket_id' => $ticketId, 'author_type' => $type, 'author_id' => $authorId, 'body' => $body, 'created_at' => $this->stamp()]);
    }

    private function customerRow(array $r): array
    {
        return ['id' => (int) $r['id'], 'number' => self::number((int) $r['id']), 'subject' => $r['subject'], 'category' => $r['category'], 'category_label' => self::CATEGORIES[$r['category']] ?? 'Other',
            'status' => $r['status'], 'unread' => (int) $r['customer_unread'] === 1, 'last_from' => $r['last_from'], 'last_message_at' => $r['last_message_at'], 'created_at' => $r['created_at']];
    }

    private function teamRow(array $r): array
    {
        return ['id' => (int) $r['id'], 'number' => self::number((int) $r['id']), 'subject' => $r['subject'], 'category' => $r['category'], 'category_label' => self::CATEGORIES[$r['category']] ?? 'Other',
            'priority' => $r['priority'], 'status' => $r['status'], 'last_from' => $r['last_from'], 'unread' => (int) $r['team_unread'] === 1, 'last_message_at' => $r['last_message_at'], 'created_at' => $r['created_at'],
            'tenant_id' => (int) $r['tenant_id'], 'tenant_name' => $r['tenant_name'] ?? '', 'tenant_plan' => $r['tenant_plan'] ?? '', 'user_name' => $r['user_name'] ?? '', 'user_email' => $r['user_email'] ?? '',
            'assigned_to' => $r['assigned_to'] ? (int) $r['assigned_to'] : null, 'assignee_name' => $r['assignee_name'] ?? null,
            'waiting_hours' => $this->waitingHours($r), 'past_target' => ($w = $this->waitingHours($r)) !== null && $w >= self::TARGET_HOURS];
    }

    /** Hours the customer has been waiting on us (open + they wrote last), else null. */
    private function waitingHours(array $r): ?int
    {
        if ($r['status'] !== 'open' || $r['last_from'] !== 'customer' || empty($r['last_message_at'])) { return null; }
        return (int) max(0, floor((($this->now ?? time()) - strtotime($r['last_message_at'] . ' UTC')) / 3600));
    }

    private function clean(string $s, int $max): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? ''), 0, $max); }

    /** Message text: tags dropped, line breaks kept, control characters removed. Output is escaped by the page/e-mail, never trusted as HTML. */
    private function text(string $s, int $max): string
    {
        $s = strip_tags(str_replace(["\r\n", "\r"], "\n", $s));
        $s = preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? '';
        $s = preg_replace("/\n{4,}/", "\n\n\n", trim($s)) ?? '';
        return mb_substr($s, 0, $max);
    }
}
