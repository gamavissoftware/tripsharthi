<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Services\Email\EmailService;

/**
 * Website live chat (platform-level). A visitor's session token is their only credential. The team answers from the platform-admin inbox.
 * Delivery is polling (every few seconds while a window is open): simple, proxy-friendly, no extra servers.
 * Abuse limits: 1,000 chars per message, 200 messages per session, spam links refused, honeypot + minimum fill time on the opening form,
 * and the controller throttles by IP and token. The team is emailed about a new chat, and about a new visitor message at most once per 10 minutes.
 */
final class ChatService
{
    public const MAX_BODY = 1000; public const MAX_MESSAGES = 200; public const ONLINE_SECONDS = 90; public const NOTIFY_GAP = 600;

    /** @var (callable(string,string,string,string,?string):bool)|null */
    private $mailer;
    public function __construct(?callable $mailer = null) { $this->mailer = $mailer; }

    private static function now(): string { return date('Y-m-d H:i:s'); }
    private static function clean(string $s, int $max): string { return mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace(["\r\n", "\r"], "\n", $s))), 0, $max); }

    /** Is a member of the team looking at the inbox right now? (shown to visitors as "online"). */
    public static function teamOnline(): bool
    {
        $at = db_connect()->table('chat_sessions')->selectMax('last_agent_at', 'm')->get()->getRowArray()['m'] ?? null;
        $seen = (int) (cache('chat_team_seen') ?: 0);
        return $seen > time() - self::ONLINE_SECONDS || ($at !== null && strtotime((string) $at) > time() - 120);
    }
    public static function markTeamSeen(): void { cache()->save('chat_team_seen', time(), 300); }

    /** @return array{token:string,id:int} */
    public function start(array $in, string $ip = '', string $ua = '', ?int $nowMs = null): array
    {
        if (trim((string) ($in['website'] ?? '')) !== '') { throw new \DomainException('ignored'); }
        $nowMs ??= (int) round(microtime(true) * 1000); $t = (int) ($in['t'] ?? 0);
        if ($t > 0 && ($nowMs - $t) < 800) { throw new \DomainException('ignored'); }
        $name = self::clean((string) ($in['name'] ?? ''), 120); $email = strtolower(self::clean((string) ($in['email'] ?? ''), 190));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('That email address does not look right.'); }
        $msg = self::clean((string) ($in['message'] ?? ''), self::MAX_BODY);
        if ($msg === '') { throw new \InvalidArgumentException('Please type your message.'); }
        if (ContactEnquiryService::looksLikeSpam($msg)) { throw new \InvalidArgumentException('Please remove the links from your message.'); }
        $db = db_connect(); $now = self::now(); $token = bin2hex(random_bytes(16));
        $db->table('chat_sessions')->insert(['token' => $token, 'visitor_name' => $name ?: null, 'visitor_email' => $email ?: null, 'status' => 'open', 'page_url' => mb_substr(self::clean((string) ($in['page'] ?? ''), 255), 0, 255) ?: null,
            'ip_hash' => $ip !== '' ? substr(hash('sha256', $ip . '|' . (string) env('encryption.key', 'tripsarthi')), 0, 32) : null, 'user_agent' => mb_substr($ua, 0, 200) ?: null,
            'unread_agent' => 1, 'last_visitor_at' => $now, 'last_poll_at' => $now, 'last_message_at' => $now, 'created_at' => $now]);
        $id = (int) $db->insertID();
        $db->table('chat_messages')->insert(['session_id' => $id, 'sender' => 'visitor', 'body' => $msg, 'created_at' => $now]);
        $greet = self::teamOnline() ? 'Thanks for reaching out! A member of our team will reply here in a moment.' : 'Thanks for your message! Our team is away right now. ' . ($email !== '' ? 'We will reply by email as soon as we can.' : 'Leave your email or phone in your next message and we will get back to you.');
        $db->table('chat_messages')->insert(['session_id' => $id, 'sender' => 'system', 'body' => $greet, 'created_at' => $now]);
        return ['token' => $token, 'id' => $id];
    }

    private function session(string $token): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) { throw new \OutOfBoundsException('Chat not found.'); }
        return db_connect()->table('chat_sessions')->where('token', $token)->get()->getRowArray() ?: throw new \OutOfBoundsException('Chat not found.');
    }

    /** Visitor sends a message. @return array{id:int} */
    public function visitorSend(string $token, string $body): array
    {
        $s = $this->session($token);
        if ($s['status'] !== 'open') { throw new \DomainException('This chat has ended. Please start a new one.'); }
        $body = self::clean($body, self::MAX_BODY);
        if ($body === '') { throw new \InvalidArgumentException('Please type your message.'); }
        if (ContactEnquiryService::looksLikeSpam($body)) { throw new \InvalidArgumentException('Please remove the links from your message.'); }
        $db = db_connect();
        if ((int) $db->table('chat_messages')->where('session_id', $s['id'])->countAllResults() >= self::MAX_MESSAGES) { throw new \DomainException('This chat is full. Please call or email us.'); }
        $now = self::now();
        $db->table('chat_messages')->insert(['session_id' => $s['id'], 'sender' => 'visitor', 'body' => $body, 'created_at' => $now]);
        $mid = (int) $db->insertID();
        $db->query('UPDATE chat_sessions SET unread_agent = unread_agent + 1, last_visitor_at = ?, last_poll_at = ?, last_message_at = ? WHERE id = ?', [$now, $now, $now, $s['id']]);
        return ['id' => $mid, 'notify' => (int) $s['id']];
    }

    /** Email the team (best-effort, at most once per NOTIFY_GAP per chat). Call AFTER the response has been sent. */
    public function notifyTeam(int $sessionId): bool
    {
        $db = db_connect(); $s = $db->table('chat_sessions')->where('id', $sessionId)->get()->getRowArray();
        if (! $s || ($s['last_notified_at'] && strtotime((string) $s['last_notified_at']) > time() - self::NOTIFY_GAP)) { return false; }
        if (self::teamOnline() && (int) $s['unread_agent'] > 1) { return false; }                      // someone is in the inbox already
        $last = $db->table('chat_messages')->where('session_id', $sessionId)->where('sender', 'visitor')->orderBy('id', 'DESC')->get()->getRowArray();
        $h = static fn (?string $x): string => htmlspecialchars((string) $x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $who = $s['visitor_name'] ?: 'A visitor';
        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#0f1f3a"><h2 style="margin:0 0 10px">New website chat</h2><p><b>' . $h($who) . '</b>' . ($s['visitor_email'] ? ' &lt;' . $h($s['visitor_email']) . '&gt;' : '') . '<br><span style="color:#53657f">Page: ' . $h($s['page_url'] ?: '—') . '</span></p><p style="white-space:pre-wrap;background:#f3f7fc;border-radius:8px;padding:14px">' . $h($last['body'] ?? '') . '</p><p>Reply from <b>Platform admin → Live chat</b> in the TripSarthi app.</p></div>';
        $send = $this->mailer ?? [EmailService::class, 'send'];
        $ok = (bool) $send(ContactEnquiryService::recipient(), 'TripSarthi chat: ' . mb_substr(str_replace(["\r", "\n"], ' ', $who), 0, 60), $html, "New website chat from {$who}\n\n" . ($last['body'] ?? ''), $s['visitor_email'] ?: null);
        if ($ok) { $db->table('chat_sessions')->where('id', $sessionId)->update(['last_notified_at' => self::now()]); }
        return $ok;
    }

    /** Visitor polls. @return array{messages:list<array>,status:string,online:bool} */
    public function poll(string $token, int $after = 0): array
    {
        $s = $this->session($token); $db = db_connect();
        $db->table('chat_sessions')->where('id', $s['id'])->update(['last_poll_at' => self::now()]);
        $rows = $db->table('chat_messages')->select('id, sender, body, created_at')->where('session_id', $s['id'])->where('id >', $after)->orderBy('id')->limit(100)->get()->getResultArray();
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; } unset($r);
        return ['messages' => $rows, 'status' => $s['status'], 'online' => self::teamOnline()];
    }

    // ---- the team's side ---------------------------------------------------------------------------------------------------------------

    public function sessions(string $status = 'open'): array
    {
        self::markTeamSeen();
        $w = $status === 'closed' ? "s.status = 'closed'" : "s.status = 'open'";
        $rows = db_connect()->query("SELECT s.id, s.visitor_name, s.visitor_email, s.status, s.page_url, s.unread_agent, s.last_message_at, s.created_at, (s.last_poll_at IS NOT NULL AND s.last_poll_at > ?) visitor_online,
              (SELECT m.body FROM chat_messages m WHERE m.session_id = s.id AND m.sender <> 'system' ORDER BY m.id DESC LIMIT 1) last_body
              FROM chat_sessions s WHERE {$w} ORDER BY s.last_message_at DESC LIMIT 100", [date('Y-m-d H:i:s', time() - 30)])->getResultArray();
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['unread_agent'] = (int) $r['unread_agent']; $r['visitor_online'] = (bool) $r['visitor_online']; } unset($r);
        return $rows;
    }

    public function thread(int $id, int $after = 0, bool $markRead = true): array
    {
        self::markTeamSeen(); $db = db_connect();
        $s = $db->table('chat_sessions')->where('id', $id)->get()->getRowArray() ?: throw new \OutOfBoundsException('Chat not found.');
        if ($markRead && (int) $s['unread_agent'] > 0) { $db->table('chat_sessions')->where('id', $id)->update(['unread_agent' => 0]); }
        $rows = $db->table('chat_messages')->select('id, sender, body, created_at')->where('session_id', $id)->where('id >', $after)->orderBy('id')->limit(200)->get()->getResultArray();
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; } unset($r);
        return ['session' => ['id' => $id, 'visitor_name' => $s['visitor_name'], 'visitor_email' => $s['visitor_email'], 'status' => $s['status'], 'page_url' => $s['page_url'], 'created_at' => $s['created_at'], 'visitor_online' => $s['last_poll_at'] && strtotime((string) $s['last_poll_at']) > time() - 30], 'messages' => $rows];
    }

    public function agentReply(int $id, string $body, int $agentId): array
    {
        $db = db_connect(); $s = $db->table('chat_sessions')->where('id', $id)->get()->getRowArray() ?: throw new \OutOfBoundsException('Chat not found.');
        $body = self::clean($body, 2000); if ($body === '') { throw new \InvalidArgumentException('Type a reply first.'); }
        if ($s['status'] !== 'open') { throw new \DomainException('This chat is closed.'); }
        $now = self::now();
        $db->table('chat_messages')->insert(['session_id' => $id, 'sender' => 'agent', 'body' => $body, 'agent_id' => $agentId, 'created_at' => $now]); $mid = (int) $db->insertID();
        $db->table('chat_sessions')->where('id', $id)->update(['last_agent_at' => $now, 'last_message_at' => $now, 'unread_agent' => 0]);
        self::markTeamSeen();
        // Visitor has gone (widget closed) but left an email: send the answer there so it is not lost.
        $away = ! $s['last_poll_at'] || strtotime((string) $s['last_poll_at']) < time() - 120;
        if ($away && $s['visitor_email']) {
            $send = $this->mailer ?? [EmailService::class, 'send']; $h = static fn (string $x): string => htmlspecialchars($x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $send($s['visitor_email'], 'Reply from TripSarthi', '<div style="font-family:Arial,sans-serif;font-size:15px;color:#0f1f3a"><p>Hi ' . $h($s['visitor_name'] ?: 'there') . ',</p><p style="white-space:pre-wrap">' . $h($body) . '</p><p style="color:#53657f">— TripSarthi team. You can reply to this email.</p></div>', "Hi,\n\n{$body}\n\n— TripSarthi team", ContactEnquiryService::recipient());
        }
        return ['id' => $mid];
    }

    public function close(int $id): void
    {
        $db = db_connect(); if (! $db->table('chat_sessions')->where('id', $id)->countAllResults()) { throw new \OutOfBoundsException('Chat not found.'); }
        $db->table('chat_sessions')->where('id', $id)->update(['status' => 'closed', 'unread_agent' => 0]);
        $db->table('chat_messages')->insert(['session_id' => $id, 'sender' => 'system', 'body' => 'This chat was closed by our team. Start a new chat any time.', 'created_at' => self::now()]);
    }
}
