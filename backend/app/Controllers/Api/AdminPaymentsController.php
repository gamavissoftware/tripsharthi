<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Billing\PlatformRazorpay;

/**
 * /api/v1/admin/payments/* - TripSarthi's own Razorpay account (filter: adminauth).
 * The secret and webhook secret are write-only: GET never returns them. Errors: 422 with plain words, never 401.
 */
class AdminPaymentsController extends TravelBaseController
{
    private function run(callable $fn)
    {
        try { return $this->ok($fn()); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
    }

    public function razorpay() { return $this->run(fn () => PlatformRazorpay::status()); }

    /** PUT /admin/payments/razorpay { key_id, key_secret?, webhook_secret? } - checked against Razorpay before saving. */
    public function save()
    {
        $b = $this->body();
        return $this->run(fn () => PlatformRazorpay::save((string) ($b['key_id'] ?? ''), (string) ($b['key_secret'] ?? ''), (string) ($b['webhook_secret'] ?? ''), CurrentUser::id()));
    }

    public function test()  { return $this->run(fn () => PlatformRazorpay::test()); }
    public function clear() { return $this->run(fn () => PlatformRazorpay::clear(CurrentUser::id())); }
}
