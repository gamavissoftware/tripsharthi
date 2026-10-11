<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Admin\ImpersonationService;
use App\Services\Auth\CurrentUser;

/** /api/v1/admin/tenants/:id/impersonate, /admin/impersonations* - platform admins only (filter: adminauth). Errors: 404, 422, 409; never 401. */
class AdminImpersonationController extends TravelBaseController
{
    private function run(callable $fn, int $okCode = 200)
    {
        try { return $this->ok($fn(), $okCode); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'not_found'], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    /** POST tenants/:id/impersonate {reason, view_only} -> {token, app_url, ...}. The token is shown ONCE and opens the customer app in a new tab. */
    public function start($tenantId = null)
    {
        $b = $this->body();
        return $this->run(function () use ($tenantId, $b): array {
            $r = (new ImpersonationService())->start(CurrentUser::id(), (int) $tenantId, (string) ($b['reason'] ?? ''), filter_var($b['view_only'] ?? false, FILTER_VALIDATE_BOOLEAN), $this->request->getIPAddress());
            $r['app_url'] = rtrim((string) (env('APP_URL') ?: (str_contains((string) $this->request->getServer('HTTP_HOST'), 'localhost') ? 'http://localhost:5917' : 'https://app.tripsarthi.com')), '/');
            return $r;
        }, 201);
    }

    public function list()
    {
        $t = (int) ($this->request->getGet('tenant_id') ?? 0);
        return $this->run(fn () => ['rows' => (new ImpersonationService())->log($t ?: null, (int) ($this->request->getGet('limit') ?: 100))]);
    }

    public function end($id = null)
    {
        return $this->run(function () use ($id): array { (new ImpersonationService())->end((int) $id, 'admin'); return ['ended' => true]; });
    }
}
