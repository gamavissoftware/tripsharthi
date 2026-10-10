<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Admin\OfferAdminService;
use App\Services\Auth\CurrentUser;

/**
 * /api/v1/admin/{coupons,promotions,grants} - the TripSarthi team only (filter: adminauth). Errors: 404 not found, 422 bad input,
 * 409 not allowed now. Never 401 (the admin app signs staff out on 401).
 */
class AdminOffersController extends TravelBaseController
{
    private function svc(): OfferAdminService { return new OfferAdminService(); }

    private function run(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'not_found'], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    public function coupons()                    { return $this->run(fn () => $this->svc()->coupons()); }
    public function createCoupon()               { return $this->run(fn () => $this->svc()->saveCoupon($this->body(), null, CurrentUser::id()), 201); }
    public function updateCoupon($id = null)     { return $this->run(fn () => $this->svc()->saveCoupon($this->body(), (int) $id, CurrentUser::id())); }
    public function couponRedemptions($id = null) { return $this->run(fn () => $this->svc()->couponRedemptions((int) $id)); }

    public function promotions()                 { return $this->run(fn () => $this->svc()->promotions()); }
    public function createPromotion()            { return $this->run(fn () => $this->svc()->savePromotion($this->body(), null, CurrentUser::id()), 201); }
    public function updatePromotion($id = null)  { return $this->run(fn () => $this->svc()->savePromotion($this->body(), (int) $id, CurrentUser::id())); }

    public function grants()                     { return $this->run(fn () => $this->svc()->grants()); }

    /** POST /admin/grants { tenant_id, kind: trial|extension, days, plan?, reason } */
    public function grant()
    {
        $b = $this->body();
        return $this->run(fn () => $this->svc()->grant((int) ($b['tenant_id'] ?? 0), (string) ($b['kind'] ?? ''), (int) ($b['days'] ?? 0), (string) ($b['plan'] ?? ''), (string) ($b['reason'] ?? ''), CurrentUser::id()), 201);
    }
}
