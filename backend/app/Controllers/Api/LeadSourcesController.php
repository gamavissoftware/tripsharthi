<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Leads\PortalLeadService;

/** Settings → Lead Sources (owner/admin): create the webhook URL / email rule for each portal and watch what arrives. */
class LeadSourcesController extends TravelBaseController
{
    private function guard(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
    }
    private function svc(): PortalLeadService { return new PortalLeadService(); }

    public function index() { return $this->ok(['sources' => $this->svc()->list(CurrentUser::tenantId()), 'webhook_base' => rtrim((string) base_url(), '/') . '/api/v1/public/lead-sources/', 'fields' => array_keys(\App\Services\Leads\LeadFieldExtractor::ALIASES)]); }
    public function create() { return $this->guard(fn () => $this->svc()->save(CurrentUser::tenantId(), $this->body(), null, CurrentUser::id()), 201); }
    public function update($id = null) { return $this->guard(fn () => $this->svc()->save(CurrentUser::tenantId(), $this->body(), (int) $id, CurrentUser::id())); }
    public function rotate($id = null) { return $this->guard(fn () => $this->svc()->rotateToken(CurrentUser::tenantId(), (int) $id, CurrentUser::id())); }
    public function remove($id = null) { return $this->guard(function () use ($id) { $this->svc()->delete(CurrentUser::tenantId(), (int) $id, CurrentUser::id()); return ['deleted' => true]; }); }
    public function events($id = null) { return $this->guard(fn () => $this->svc()->events(CurrentUser::tenantId(), (int) $id)); }
}
