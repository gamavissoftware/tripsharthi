<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Email\Marketing\EmailTrackingService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public endpoints behind every marketing email.
 *
 * GET  /webhooks/email/open/{token}.gif   open pixel
 * GET  /webhooks/email/click/{token}?u=&s= signed click redirect
 * GET  /forms/unsubscribe/{token}          confirmation page
 * POST /forms/unsubscribe/{token}          unsubscribe (the page's button, and
 *                                          RFC 8058 one-click from Gmail/Yahoo)
 *
 * GET never unsubscribes: corporate link scanners open every URL in a message,
 * and would otherwise unsubscribe people who never clicked anything.
 */
class EmailTrackingController extends Controller
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(string $token): ResponseInterface
    {
        $token = preg_replace('/\.gif$/i', '', $token) ?? '';
        try {
            (new EmailTrackingService())->recordOpen($token);
        } catch (\Throwable $e) {
            log_message('error', '[email-open] ' . $e->getMessage());
        }

        return $this->response
            ->setHeader('Content-Type', 'image/gif')
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setBody((string) base64_decode(self::GIF));
    }

    public function click(string $token): ResponseInterface
    {
        $url = (string) ($this->request->getGet('u') ?? '');
        $sig = (string) ($this->request->getGet('s') ?? '');

        try {
            $target = (new EmailTrackingService())->recordClick($token, $url, $sig);
        } catch (\Throwable $e) {
            log_message('error', '[email-click] ' . $e->getMessage());
            $target = null;
        }

        if ($target === null) {
            return $this->response->setStatusCode(404)
                ->setBody($this->page('Link not found', 'This link is invalid or has expired.'));
        }

        return $this->response->redirect($target, 'auto', 302);
    }

    public function unsubscribeForm(string $token): ResponseInterface
    {
        $email = (new EmailTrackingService())->findByToken($token);
        if ($email === null) {
            return $this->response->setStatusCode(404)
                ->setBody($this->page('Link not found', 'This unsubscribe link is invalid or has expired.'));
        }

        $masked = $this->mask((string) $email['to_email']);
        $action = esc(current_url(), 'attr');
        $body   = <<<HTML
<p>Unsubscribe <strong>{$masked}</strong> from these emails?</p>
<form method="post" action="{$action}">
  <button type="submit" style="background:#111827;color:#fff;border:0;border-radius:6px;padding:12px 22px;font-size:15px;cursor:pointer;">Unsubscribe</button>
</form>
HTML;

        return $this->response->setBody($this->page('Unsubscribe', $body, false));
    }

    public function unsubscribe(string $token): ResponseInterface
    {
        $result = (new EmailTrackingService())->unsubscribe($token);
        if (! $result['ok']) {
            return $this->response->setStatusCode(404)
                ->setBody($this->page('Link not found', 'This unsubscribe link is invalid or has expired.'));
        }

        return $this->response->setBody($this->page(
            'You are unsubscribed',
            esc($this->mask((string) $result['email'])) . ' will no longer receive these emails.',
        ));
    }

    private function mask(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $keep = mb_substr($user, 0, 2);

        return esc($keep . str_repeat('•', max(1, mb_strlen($user) - 2)) . '@' . $domain);
    }

    private function page(string $title, string $message, bool $escape = true): string
    {
        $t = esc($title);
        $m = $escape ? esc($message) : $message;

        return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>{$t}</title></head>
<body style="margin:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2937;">
<div style="max-width:440px;margin:12vh auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center;">
<h1 style="font-size:20px;margin:0 0 12px;">{$t}</h1>
<div style="font-size:15px;line-height:1.5;">{$m}</div>
</div></body></html>
HTML;
    }
}
