<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Marketing\EmailUnsubscribe;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public, no login: the unsubscribe link in TripSarthi marketing e-mails.
 *   GET  shows a confirmation page (link scanners that pre-fetch URLs must not unsubscribe anyone by themselves),
 *   POST does it (the page's button, and the mail apps' RFC 8058 one-click request).
 * The address + HMAC in the link are the only credential; a wrong/forged link changes nothing and says so. Throttled per IP.
 */
class EmailUnsubscribeController extends Controller
{
    private function page(string $title, string $message, string $form = '', int $status = 200): ResponseInterface
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $h($title) . '</title>'
            . '<style>body{font-family:Arial,sans-serif;background:#f3f7fc;color:#0f1f3a;margin:0;display:grid;place-items:center;min-height:100vh}main{background:#fff;border-radius:14px;padding:28px 30px;max-width:420px;margin:16px;box-shadow:0 2px 14px #0a1f4414}'
            . 'h1{font-size:20px;margin:0 0 10px}p{line-height:1.5;color:#41536e}button{background:#0a6cc4;color:#fff;border:0;border-radius:8px;padding:11px 18px;font-size:15px;cursor:pointer}</style></head><body><main>'
            . '<div style="font-weight:800;font-size:18px;color:#0a1f44;margin-bottom:12px">Trip<span style="color:#12a89e">Sarthi</span></div><h1>' . $h($title) . '</h1><p>' . $h($message) . '</p>' . $form . '</main></body></html>';
        return $this->response->setStatusCode($status)->setHeader('Content-Type', 'text/html; charset=utf-8')->setHeader('Cache-Control', 'no-store')->setHeader('X-Robots-Tag', 'noindex')
            ->setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'")->setBody($html);
    }

    private function check(): ?array
    {
        $email = EmailUnsubscribe::decode((string) ($this->request->getGet('e') ?? ''));
        $tok = (string) ($this->request->getGet('t') ?? '');
        return ($email !== '' && EmailUnsubscribe::verify($email, $tok)) ? [$email, $tok] : null;
    }

    public function show(): ResponseInterface
    {
        if (! service('throttler')->check('unsub_' . md5($this->request->getIPAddress()), 30, MINUTE)) { return $this->page('Please wait a moment', 'Too many requests. Try again in a minute.', '', 429); }
        $c = $this->check();
        if ($c === null) { return $this->page('This link is not valid', 'The unsubscribe link is incomplete or has been changed. Please use the link from the latest e-mail, or reply to that e-mail and we will remove you.', '', 400); }
        [$email] = $c; [$u, $d] = explode('@', $email, 2);
        $masked = mb_substr($u, 0, 1) . '•••@' . $d;
        $form = '<form method="post" action="' . htmlspecialchars((string) $this->request->getUri()->getPath(), ENT_QUOTES) . '?' . htmlspecialchars((string) $this->request->getUri()->getQuery(), ENT_QUOTES) . '"><button type="submit">Yes, unsubscribe me</button></form>';
        return $this->page('Unsubscribe from TripSarthi e-mails?', 'We will stop sending news and offers to ' . $masked . '. Account and billing messages you need will still arrive.', $form);
    }

    public function apply(): ResponseInterface
    {
        if (! service('throttler')->check('unsub_' . md5($this->request->getIPAddress()), 30, MINUTE)) { return $this->page('Please wait a moment', 'Too many requests. Try again in a minute.', '', 429); }
        $c = $this->check();
        if ($c === null) { return $this->page('This link is not valid', 'Nothing was changed. Please use the link from the latest e-mail.', '', 400); }
        EmailUnsubscribe::apply($c[0]);
        return $this->page('You are unsubscribed', 'We will not send you TripSarthi news and offers any more. You can switch them back on any time in your profile.');
    }
}
