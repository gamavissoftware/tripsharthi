<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Marketing\ChatService;
use CodeIgniter\RESTful\ResourceController;

/**
 * Website live chat, visitor side (no login; the session token is the credential).
 *   POST /public/chat/start         {name?, email?, message, page, website(honeypot), t}
 *   POST /public/chat/{token}/send  {body}
 *   GET  /public/chat/{token}/poll?after=<message id>
 * Throttled per IP / token, small bodies, never 401. Bots that trip the honeypot get a harmless 201 with a dead token.
 */
class ChatController extends ResourceController
{
    protected $format = 'json';
    private const MAX_BODY = 8_192;

    private function fail422(string $m, int $code = 422) { return $this->respond(['success' => false, 'message' => $m], $code); }

    private function input(): ?array
    {
        $raw = (string) $this->request->getBody();
        if (strlen($raw) > self::MAX_BODY) { return null; }
        $in = str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'json') ? json_decode($raw, true) : $this->request->getPost();
        return is_array($in) ? $in : [];
    }

    public function start()
    {
        $ip = $this->request->getIPAddress(); $thr = service('throttler');
        if (! $thr->check('chat_start_' . md5($ip), 5, 600)) { return $this->fail422('Too many chats from your network. Please call us instead.', 429); }
        $in = $this->input(); if ($in === null) { return $this->fail422('That message is too large.', 413); }
        $svc = new ChatService();
        try { $r = $svc->start($in, $ip, (string) $this->request->getUserAgent()); }
        catch (\DomainException) { return $this->respond(['success' => true, 'token' => str_repeat('0', 32), 'messages' => [], 'online' => false], 201); }       // bot: looks like success, stores nothing
        catch (\InvalidArgumentException $e) { return $this->fail422($e->getMessage()); }
        catch (\Throwable $e) { log_message('error', '[chat] {m}', ['m' => $e->getMessage()]); return $this->fail422('We could not start the chat. Please call or email us.', 500); }
        $id = (int) $r['id'];
        register_shutdown_function(static function () use ($svc, $id): void {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            try { $svc->notifyTeam($id); } catch (\Throwable $e) { log_message('error', '[chat] notify {m}', ['m' => $e->getMessage()]); }
        });
        $p = $svc->poll($r['token'], 0);
        return $this->respond(['success' => true, 'token' => $r['token'], 'messages' => $p['messages'], 'online' => $p['online']], 201);
    }

    public function send($token = '')
    {
        if (! service('throttler')->check('chat_send_' . md5((string) $token), 20, 60)) { return $this->fail422('You are sending messages too fast.', 429); }
        $in = $this->input(); if ($in === null) { return $this->fail422('That message is too large.', 413); }
        $svc = new ChatService();
        try { $r = $svc->visitorSend((string) $token, (string) ($in['body'] ?? '')); }
        catch (\OutOfBoundsException) { return $this->fail422('Chat not found.', 404); }
        catch (\InvalidArgumentException $e) { return $this->fail422($e->getMessage()); }
        catch (\DomainException $e) { return $this->fail422($e->getMessage(), 409); }
        $sid = (int) $r['notify'];
        register_shutdown_function(static function () use ($svc, $sid): void {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            try { $svc->notifyTeam($sid); } catch (\Throwable $e) { log_message('error', '[chat] notify {m}', ['m' => $e->getMessage()]); }
        });
        return $this->respond(['success' => true, 'id' => $r['id']], 201);
    }

    public function poll($token = '')
    {
        if (! service('throttler')->check('chat_poll_' . md5((string) $token), 60, 60)) { return $this->fail422('Slow down.', 429); }
        try { $p = (new ChatService())->poll((string) $token, (int) $this->request->getGet('after')); }
        catch (\OutOfBoundsException) { return $this->fail422('Chat not found.', 404); }
        return $this->respond(['success' => true] + $p);
    }
}
