<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Admin\PlatformEmailService;
use App\Services\Auth\CurrentUser;

/** /api/v1/admin/email/* - TripSarthi's own e-mail marketing (filter: adminauth). Errors: 404, 422, 409; never 401. */
class AdminEmailController extends TravelBaseController
{
    private function run(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'not_found'], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    private function svc(): PlatformEmailService { return new PlatformEmailService(); }

    public function index()          { return $this->run(fn () => ['campaigns' => $this->svc()->campaigns(), 'segments' => $this->svc()->segments(), 'summary' => $this->svc()->summary()]); }
    public function show($id = null) { return $this->run(fn () => $this->svc()->campaign((int) $id)); }
    public function create()         { return $this->run(fn () => $this->svc()->save($this->body(), null, CurrentUser::id()), 201); }
    public function update($id = null) { return $this->run(fn () => $this->svc()->save($this->body(), (int) $id, CurrentUser::id())); }
    public function delete($id = null) { return $this->run(function () use ($id) { $this->svc()->delete((int) $id, CurrentUser::id()); return ['deleted' => true]; }); }
    public function preview($id = null) { return $this->run(fn () => $this->svc()->preview((int) $id)); }
    public function test($id = null)  { return $this->run(fn () => $this->svc()->testSend((int) $id, CurrentUser::id())); }
    public function send($id = null)
    {
        $b = $this->body();
        return $this->run(fn () => $this->svc()->send((int) $id, CurrentUser::id(), (int) ($b['confirm_count'] ?? -1)));
    }
    public function cancel($id = null) { return $this->run(fn () => $this->svc()->cancel((int) $id, CurrentUser::id())); }
}
