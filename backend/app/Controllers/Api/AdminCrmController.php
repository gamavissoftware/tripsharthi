<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Admin\PlatformCrmService;
use App\Services\Auth\CurrentUser;

/**
 * /api/v1/admin/crm/* - TripSarthi's own CRM (Subscriber Funnel) inside the marketing workspace (filter: adminauth).
 *   status / setup / dashboard / reconcile / automations/:key/(on|off) / mail-relay
 * Errors: 404 unknown automation, 422 bad input, 409 not possible now (no marketing workspace, templates not approved...). Never 401.
 */
class AdminCrmController extends TravelBaseController
{
    private function run(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'not_found'], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    private function adminId(): int { return (int) (CurrentUser::id() ?: 0); }

    public function status()    { return $this->run(fn () => (new PlatformCrmService())->status()); }
    public function dashboard() { return $this->run(fn () => (new PlatformCrmService())->dashboard((int) ($this->request->getGet('days') ?? 30))); }
    public function setup()     { return $this->run(fn () => (new PlatformCrmService())->setup($this->adminId())); }
    public function reconcile() { return $this->run(fn () => (new PlatformCrmService())->reconcile()); }
    public function mailRelay() { return $this->run(fn () => (new PlatformCrmService())->useMailRelay()); }
    public function on($key = null)  { return $this->run(fn () => (new PlatformCrmService())->activate((string) $key, true, $this->adminId())); }
    public function off($key = null) { return $this->run(fn () => (new PlatformCrmService())->activate((string) $key, false, $this->adminId())); }
}
