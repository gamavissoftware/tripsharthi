<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Support\SupportService;

/**
 * /api/v1/support/* - a workspace's requests to the TripSarthi team (filter: auth). Owners/admins see every request of their workspace,
 * other roles only their own. Errors: 404 not found / not yours, 422 bad input, 409 not allowed now. Never 401.
 */
class SupportController extends TravelBaseController
{
    private function who(): array
    {
        $u = CurrentUser::get();
        $role = is_array($u) ? ($u['role'] ?? 'agent') : ($u->role ?? 'agent');
        return [CurrentUser::tenantId(), CurrentUser::id(), (string) $role];
    }

    private function run(callable $fn, SupportService $svc, int $code = 200)
    {
        try { $out = $fn(); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'not_found'], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
        // e-mails go out AFTER the answer has been sent, so a slow mail server never slows the page
        register_shutdown_function(static function () use ($svc): void {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            $svc->deliverPending();
        });
        return $this->ok($out, $code);
    }

    public function index()
    {
        [$t, $u, $r] = $this->who(); $svc = new SupportService();
        return $this->run(fn () => ['tickets' => $svc->listFor($t, $u, $r, (string) ($this->request->getGet('filter') ?? 'all')), 'categories' => SupportService::CATEGORIES, 'unread' => $svc->unreadFor($t, $u, $r)], $svc);
    }

    public function create()
    {
        [$t, $u] = $this->who(); $svc = new SupportService(); $b = $this->body();
        return $this->run(fn () => $svc->create($t, $u, $b), $svc, 201);
    }

    public function show($id = null)
    {
        [$t, $u, $r] = $this->who(); $svc = new SupportService();
        return $this->run(fn () => $svc->detailFor($t, $u, $r, (int) $id), $svc);
    }

    public function reply($id = null)
    {
        [$t, $u, $r] = $this->who(); $svc = new SupportService(); $b = $this->body();
        return $this->run(fn () => $svc->reply($t, $u, $r, (int) $id, (string) ($b['message'] ?? '')), $svc);
    }

    public function close($id = null)
    {
        [$t, $u, $r] = $this->who(); $svc = new SupportService();
        return $this->run(fn () => $svc->close($t, $u, $r, (int) $id), $svc);
    }

    public function unread()
    {
        [$t, $u, $r] = $this->who();
        return $this->ok(['unread' => (new SupportService())->unreadFor($t, $u, $r)]);
    }
}
