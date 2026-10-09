<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Services\Email\EmailService;

/**
 * The website contact form. Public and unauthenticated, so it is defensive:
 *  - everything is validated and length-capped; the topic is a whitelist and is the only thing that reaches the email subject (no header injection),
 *  - a hidden honeypot field and a minimum fill time quietly drop bots (they still see "sent"),
 *  - link-stuffed messages are stored as `spam` and never emailed,
 *  - the same sender repeating the same message inside 15 minutes is stored once,
 *  - the sender's IP is kept only as a salted hash,
 *  - the message is SAVED FIRST; the notification email is best-effort and retried by `php spark contact:list --retry`.
 * No acknowledgement is emailed to the visitor (that would make the form a free mail relay).
 */
final class ContactEnquiryService
{
    public const TOPICS = ['demo' => 'Demo request', 'pricing' => 'Pricing enquiry', 'support' => 'Help / support', 'partner' => 'Partnership', 'other' => 'Enquiry'];
    public const MIN_FILL_MS = 1500;
    public const DEDUPE_MINUTES = 15;

    /** @var (callable(string,string,string,string,?string):bool)|null  to, subject, html, text, replyTo -> sent?  Injectable for tests. */
    private $mailer;

    public function __construct(?callable $mailer = null) { $this->mailer = $mailer; }

    /** @return array{errors:array<string,string>,clean:array<string,string>} */
    public static function validate(array $in): array
    {
        $e = []; $c = [];
        $one = static fn (string $s): string => trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s));      // no control chars / newlines in single-line fields
        $c['name'] = mb_substr($one((string) ($in['name'] ?? '')), 0, 120);
        if (mb_strlen($c['name']) < 2) { $e['name'] = 'Please enter your name.'; }
        $c['email'] = mb_substr(strtolower($one((string) ($in['email'] ?? ''))), 0, 190);
        if (! filter_var($c['email'], FILTER_VALIDATE_EMAIL)) { $e['email'] = 'Please enter a valid email address.'; }
        $phone = $one((string) ($in['phone'] ?? ''));
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($phone !== '' && (strlen($digits) < 7 || strlen($digits) > 15 || ! preg_match('/^[0-9 +\-().]{7,30}$/', $phone))) { $e['phone'] = 'That phone number does not look right.'; }
        $c['phone'] = $phone;
        $c['company'] = mb_substr($one((string) ($in['company'] ?? '')), 0, 160);
        $topic = (string) ($in['topic'] ?? 'other');
        $c['topic'] = isset(self::TOPICS[$topic]) ? $topic : 'other';
        $msg = str_replace(["\r\n", "\r"], "\n", (string) ($in['message'] ?? ''));
        $msg = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $msg));
        if (mb_strlen($msg) > 3000) { $e['message'] = 'Please keep the message under 3,000 characters.'; }
        $c['message'] = mb_substr($msg, 0, 3000);
        $c['source'] = mb_substr($one((string) ($in['source'] ?? '')), 0, 120);
        return ['errors' => $e, 'clean' => $c];
    }

    /** @return bool true when the text looks like link spam */
    public static function looksLikeSpam(string $message): bool
    {
        return preg_match_all('~https?://|www\.~i', $message) > 2;
    }

    /**
     * @param array $in   form fields plus optional `website` (honeypot) and `t` (ms timestamp when the page loaded)
     * @param bool  $notifyNow false = the caller will call notify($id) itself after the HTTP response has been sent
     * @return array{status:string,id:?int}  status: stored | spam | duplicate | ignored
     * @throws \InvalidArgumentException with ->getMessage() = first error; use validate() to get every field error
     */
    public function submit(array $in, string $ip = '', string $userAgent = '', ?int $nowMs = null, bool $notifyNow = true): array
    {
        if (trim((string) ($in['website'] ?? '')) !== '') { return ['status' => 'ignored', 'id' => null]; }                      // honeypot
        $nowMs ??= (int) round(microtime(true) * 1000);
        $t = (int) ($in['t'] ?? 0);
        if ($t > 0 && ($nowMs - $t) < self::MIN_FILL_MS) { return ['status' => 'ignored', 'id' => null]; }                        // filled faster than a person can

        $v = self::validate($in);
        if ($v['errors']) { throw new \InvalidArgumentException((string) reset($v['errors'])); }
        $c = $v['clean'];

        $db = db_connect(); $now = date('Y-m-d H:i:s');
        $dupe = $db->table('contact_enquiries')->where('email', $c['email'])->where('message', $c['message'])->where('created_at >=', date('Y-m-d H:i:s', time() - self::DEDUPE_MINUTES * 60))->countAllResults();
        if ($dupe > 0) { return ['status' => 'duplicate', 'id' => null]; }

        $spam = self::looksLikeSpam($c['message']);
        $db->table('contact_enquiries')->insert($c + [
            'status' => $spam ? 'spam' : 'new',
            'ip_hash' => $ip !== '' ? substr(hash('sha256', $ip . '|' . (string) env('encryption.key', 'tripsarthi')), 0, 32) : null,
            'user_agent' => mb_substr($userAgent, 0, 200) ?: null, 'created_at' => $now,
        ]);
        $id = (int) $db->insertID();
        if (! $spam && $notifyNow) { $this->notify($id); }
        return ['status' => $spam ? 'spam' : 'stored', 'id' => $id];
    }

    public static function recipient(): string
    {
        $to = trim((string) env('CONTACT_NOTIFY_EMAIL', 'manglesh@gamavis.com'));
        return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : 'manglesh@gamavis.com';
    }

    /** Email the team about a stored enquiry. Never throws; records the outcome on the row. */
    public function notify(int $id): bool
    {
        $db = db_connect();
        $r = $db->table('contact_enquiries')->where('id', $id)->get()->getRowArray();
        if (! $r || $r['status'] !== 'new') { return false; }
        $h = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $topic = self::TOPICS[$r['topic']] ?? 'Enquiry';
        $rows = [['Name', $r['name']], ['Email', $r['email']], ['Phone', $r['phone'] ?: '—'], ['Company', $r['company'] ?: '—'], ['Topic', $topic], ['Page', $r['source'] ?: '—'], ['Received', $r['created_at'] . ' (server time)']];
        $tr = ''; foreach ($rows as [$k, $v]) { $tr .= '<tr><td style="padding:6px 14px 6px 0;color:#53657f">' . $h($k) . '</td><td style="padding:6px 0"><b>' . $h($v) . '</b></td></tr>'; }
        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#0f1f3a"><h2 style="margin:0 0 12px">New website enquiry</h2><table>' . $tr . '</table><p style="white-space:pre-wrap;background:#f3f7fc;border-radius:8px;padding:14px;margin-top:14px">' . $h($r['message'] ?: '(no message)') . '</p><p style="color:#53657f;font-size:13px">Reply to this email to answer ' . $h($r['name']) . ' directly.</p></div>';
        $text = "New website enquiry\nName: {$r['name']}\nEmail: {$r['email']}\nPhone: " . ($r['phone'] ?: '-') . "\nCompany: " . ($r['company'] ?: '-') . "\nTopic: {$topic}\n\n" . ($r['message'] ?: '(no message)');
        $send = $this->mailer ?? [EmailService::class, 'send'];
        $ok = (bool) $send(self::recipient(), 'TripSarthi website: ' . $topic . ' from ' . mb_substr(str_replace(["\r", "\n"], ' ', $r['name']), 0, 60), $html, $text, $r['email']);
        $db->table('contact_enquiries')->where('id', $id)->update($ok ? ['notified_at' => date('Y-m-d H:i:s'), 'notify_error' => null] : ['notify_error' => 'Email could not be sent — see logs']);
        return $ok;
    }

    /** @return int how many pending notifications were sent */
    public function retryUnsent(int $limit = 20): int
    {
        $sent = 0;
        foreach (db_connect()->table('contact_enquiries')->select('id')->where('status', 'new')->where('notified_at', null)->orderBy('id')->limit($limit)->get()->getResultArray() as $r) { if ($this->notify((int) $r['id'])) { $sent++; } }
        return $sent;
    }
}
