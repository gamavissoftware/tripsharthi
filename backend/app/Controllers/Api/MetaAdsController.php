<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Analytics\MetaAdsService;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Meta Ads spend connection.
 *
 * GET    /api/v1/meta-ads             — status + chosen accounts
 * POST   /api/v1/meta-ads/connect     — { state } from a Facebook Login started with return_to=ads
 * GET    /api/v1/meta-ads/accounts    — ad accounts the token can read (picker)
 * POST   /api/v1/meta-ads/accounts    — { accounts: [{id,name,currency}] } to report on
 * POST   /api/v1/meta-ads/sync        — pull spend now
 * DELETE /api/v1/meta-ads             — forget the token
 *
 * No access token is accepted from, or returned to, the browser.
 */
class MetaAdsController extends ResourceController
{
    protected $format = 'json';

    public function status(): ResponseInterface
    {
        return $this->respond(['success' => true, 'data' => (new MetaAdsService())->status(CurrentUser::tenantId())]);
    }

    public function connect(): ResponseInterface
    {
        $state = trim((string) ($this->request->getJsonVar('state') ?? ''));
        if ($state === '') {
            return $this->fail(['state' => 'Missing state.'], 422);
        }
        try {
            $status = (new MetaAdsService())->connect($state, CurrentUser::tenantId(), CurrentUser::id());
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 422);
        }

        return $this->respond(['success' => true, 'data' => $status]);
    }

    public function accounts(): ResponseInterface
    {
        try {
            $accounts = (new MetaAdsService())->accounts(CurrentUser::tenantId());
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 422);
        }

        return $this->respond(['success' => true, 'data' => $accounts]);
    }

    public function select(): ResponseInterface
    {
        $accounts = $this->request->getJsonVar('accounts');
        $accounts = json_decode(json_encode($accounts ?? []), true) ?: [];
        try {
            $status = (new MetaAdsService())->select(CurrentUser::tenantId(), $accounts);
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 422);
        }

        return $this->respond(['success' => true, 'data' => $status]);
    }

    public function sync(): ResponseInterface
    {
        $months = (int) ($this->request->getJsonVar('months') ?? 12);
        try {
            $sync = (new MetaAdsService())->sync(CurrentUser::tenantId(), $months);
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 422);
        }

        return $this->respond(['success' => true, 'sync' => $sync, 'data' => (new MetaAdsService())->report(CurrentUser::tenantId(), $months)]);
    }

    public function disconnect(): ResponseInterface
    {
        (new MetaAdsService())->disconnect(CurrentUser::tenantId());

        return $this->respondDeleted(['success' => true]);
    }
}
