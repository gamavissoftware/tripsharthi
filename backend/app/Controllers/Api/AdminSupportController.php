<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Support\SupportService;

/** /api/v1/admin/support/* - the TripSarthi team only (filter: adminauth). Errors: 404, 422, 409; never 401. */
class AdminSupportController extends TravelBaseController
{
    private function run(callable $fn, SupportService $svc, int $code = 200)
    {
        try { $out = $fn(); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'not_found'], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
        register_shutdown_function(static function () use ($svc): void {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            $svc->deliverPending();
        });
        return $this->ok($out, $code);
    }

    private function g(string $k): string { return (string) ($this->request->getGet($k) ?? ''); }

    public function index()
    {
        $svc = new SupportService();
        return $this->run(fn () => $svc->adminList($this->g('status'), $this->g('q'), $this->g('assigned'), (int) ($this->g('page') ?: 1)) + ['team' => $svc->team(), 'categories' => SupportService::CATEGORIES], $svc);
    }

    public function show($id = null)   { $svc = new SupportService(); return $this->run(fn () => $svc->adminDetail((int) $id) + ['team' => $svc->team()], $svc); }

    public function reply($id = null)
    {
        $svc = new SupportService(); $b = $this->body();
        return $this->run(fn () => $svc->adminReply((int) $id, CurrentUser::id(), (string) ($b['message'] ?? ''), (string) ($b['status'] ?? 'pending')), $svc);
    }

    public function note($id = null)
    {
        $svc = new SupportService(); $b = $this->body();
        return $this->run(fn () => $svc->adminNote((int) $id, CurrentUser::id(), (string) ($b['message'] ?? '')), $svc);
    }

    public function update($id = null)
    {
        $svc = new SupportService(); $b = $this->body();
        return $this->run(fn () => $svc->adminUpdate((int) $id, CurrentUser::id(), $b), $svc);
    }
}
