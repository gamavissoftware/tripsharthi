<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Travel\SalesTargetService;

/**
 * /api/v1/targets (owners/admins only - route filter): the monthly target editor.
 * GET ?month=YYYY-MM (default: this month) ; PUT { month, items:[{user_id, revenue (paise), bookings}] }.
 * (The inherited /crm/sales-targets endpoints are untouched; both read and write the same table.)
 */
class TravelTargetsController extends TravelBaseController
{
    public function index()
    {
        $month = (string) ($this->request->getGet('month') ?: date('Y-m'));
        try { return $this->ok((new SalesTargetService())->members(CurrentUser::tenantId(), $month)); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
    }

    public function save()
    {
        $b = $this->body();
        try { return $this->ok((new SalesTargetService())->save(CurrentUser::tenantId(), (string) ($b['month'] ?? ''), is_array($b['items'] ?? null) ? $b['items'] : [], CurrentUser::id())); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }   // never 401: the SPA signs people out on 401
        catch (\Throwable $e) { log_message('error', '[targets] {m}', ['m' => $e->getMessage()]); return $this->respond(['success' => false, 'message' => 'Could not save the targets.'], 500); }
    }
}
