<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Marketing\ContactEnquiryService;
use CodeIgniter\RESTful\ResourceController;

/**
 * POST /api/v1/public/contact — the website's contact form (JSON or form body). No login, so: throttled per IP (5 per 10 minutes, 20 per day),
 * body capped at 20 KB, never answers 401 (the SPA logs staff out on 401). Bots that trip the honeypot are told "sent" and nothing is stored.
 */
class ContactController extends ResourceController
{
    protected $format = 'json';
    private const MAX_BODY = 20_480;

    public function submit()
    {
        $ip = $this->request->getIPAddress();
        $thr = service('throttler');
        if (! $thr->check('contact_10m_' . md5($ip), 5, 600) || ! $thr->check('contact_day_' . md5($ip), 20, 86400)) {
            return $this->respond(['success' => false, 'message' => 'Too many messages from your network. Please try again later, or call us.'], 429);
        }
        $raw = (string) $this->request->getBody();
        if (strlen($raw) > self::MAX_BODY) { return $this->respond(['success' => false, 'message' => 'That message is too large.'], 413); }
        $in = str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'json') ? json_decode($raw, true) : $this->request->getPost();
        if (! is_array($in) || $in === []) { return $this->respond(['success' => false, 'message' => 'Please fill in the form.'], 422); }

        $v = ContactEnquiryService::validate($in);
        if ($v['errors']) { return $this->respond(['success' => false, 'message' => (string) reset($v['errors']), 'errors' => $v['errors']], 422); }
        try {
            $svc = new ContactEnquiryService();
            $r = $svc->submit($in, $ip, (string) $this->request->getUserAgent(), null, false);
            if ($r['status'] === 'stored' && $r['id']) {
                // The visitor gets their answer immediately; the notification email (which can be slow if SMTP is) goes out after the response is flushed.
                // If that fails the row stays "not emailed" and `php spark contact:list --retry` picks it up.
                $id = (int) $r['id'];
                register_shutdown_function(static function () use ($svc, $id): void {
                    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                    try { $svc->notify($id); } catch (\Throwable $e) { log_message('error', '[contact] notify {id}: {m}', ['id' => $id, 'm' => $e->getMessage()]); }
                });
            }
        } catch (\InvalidArgumentException $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            log_message('error', '[contact] {m}', ['m' => $e->getMessage()]);
            return $this->respond(['success' => false, 'message' => 'We could not save your message. Please email or call us.'], 500);
        }
        return $this->respond(['success' => true, 'message' => 'Thank you — we have received your message and will get back to you shortly.'], 201);   // same answer for stored, duplicate, spam and bots
    }
}
