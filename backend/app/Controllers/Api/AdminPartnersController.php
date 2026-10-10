<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Partner\PartnerService;

/**
 * /api/v1/admin/partners/* - the TripSarthi team only (filter: adminauth). Errors: 404 not found, 422 bad input, 409 not allowed now. Never 401.
 */
class AdminPartnersController extends TravelBaseController
{
    private function svc(): PartnerService { return new PartnerService(); }

    private function run(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'not_found'], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    public function index()             { return $this->run(fn () => $this->svc()->partners()); }
    public function show($id = null)    { return $this->run(fn () => $this->svc()->detail((int) $id)); }

    /** POST /admin/partners -> {partner, invite_url} (the link is shown once) */
    public function create()
    {
        return $this->run(function () {
            $r = $this->svc()->create($this->body(), CurrentUser::id());
            return ['partner' => $r['partner'], 'invite_url' => PartnerService::inviteUrl($r['invite_token'])];
        }, 201);
    }

    public function update($id = null)  { return $this->run(fn () => $this->svc()->update((int) $id, $this->body(), CurrentUser::id())); }

    /** POST /admin/partners/:id/status { status: active|suspended } */
    public function status($id = null)  { return $this->run(fn () => $this->svc()->setStatus((int) $id, (string) ($this->body()['status'] ?? ''), CurrentUser::id())); }

    /** POST /admin/partners/:id/invite -> {invite_url}  (also how a forgotten password is reset) */
    public function invite($id = null)  { return $this->run(fn () => ['invite_url' => PartnerService::inviteUrl($this->svc()->newInvite((int) $id, CurrentUser::id()))]); }

    /** POST /admin/partners/:id/payout { method, reference, note?, pay_anyway? } */
    public function payout($id = null)
    {
        $b = $this->body();
        return $this->run(fn () => $this->svc()->payout((int) $id, (string) ($b['method'] ?? ''), (string) ($b['reference'] ?? ''), (string) ($b['note'] ?? ''), CurrentUser::id(), ! empty($b['pay_anyway'])), 201);
    }

    /** POST /admin/commissions/:id/void { reason } */
    public function voidCommission($id = null)
    {
        return $this->run(function () use ($id) { $this->svc()->voidCommission((int) $id, (string) ($this->body()['reason'] ?? ''), CurrentUser::id()); return ['voided' => true]; });
    }
}
