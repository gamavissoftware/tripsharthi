<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Einvoice\EinvoiceException;
use App\Services\Einvoice\EinvoiceService;

/** E-invoicing settings (owner/admin). Secrets are write-only: no endpoint ever returns them. */
class EinvoiceController extends TravelBaseController
{
    private function guard(callable $fn)
    {
        try { return $this->ok($fn()); }
        catch (EinvoiceException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'einvoice_' . $e->kind], in_array($e->kind, ['transient', 'auth'], true) ? 502 : 422); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
    }

    public function settings() { return $this->ok((new EinvoiceService())->settings(CurrentUser::tenantId())); }
    public function save() { return $this->guard(fn () => (new EinvoiceService())->save(CurrentUser::tenantId(), $this->body(), CurrentUser::id())); }
    public function test() { return $this->guard(fn () => (new EinvoiceService())->test(CurrentUser::tenantId())); }
}
