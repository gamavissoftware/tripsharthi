<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Travel\SupplierPayableService;

/** What the agency owes suppliers, and the ledger of what it has paid. */
class SupplierPayablesController extends TravelBaseController
{
    private function guard(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    public function index()
    {
        $f = (string) $this->request->getGet('filter');
        return $this->ok((new SupplierPayableService())->payables(CurrentUser::tenantId(), in_array($f, ['overdue', 'week'], true) ? $f : 'all'));
    }

    public function service($id = null)
    {
        return $this->guard(fn () => (new SupplierPayableService())->forService(CurrentUser::tenantId(), (int) $id));
    }

    public function pay($id = null)
    {
        return $this->guard(fn () => (new SupplierPayableService())->pay(CurrentUser::tenantId(), (int) $id, $this->body(), CurrentUser::id()), 201);
    }

    public function remove($id = null)
    {
        return $this->guard(fn () => (new SupplierPayableService())->delete(CurrentUser::tenantId(), (int) $id, CurrentUser::id()));
    }
}
