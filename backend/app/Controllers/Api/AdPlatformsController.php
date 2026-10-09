<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Travel\AdCredentialsService;

/**
 * Ad platform credentials (owner/admin only). No secret is ever returned.
 *
 *  GET    /ad-platforms                      status of both platforms
 *  PUT    /ad-platforms/meta                 { dataset_id, access_token?, waba_id?, test_event_code?, events? }  (validated with Meta)
 *  POST   /ad-platforms/meta/test            send a test event
 *  POST   /ad-platforms/google/start         -> { auth_url }
 *  GET    /ad-platforms/google/customers
 *  PUT    /ad-platforms/google/account       { customer_id, login_customer_id? }
 *  GET    /ad-platforms/google/actions
 *  PUT    /ad-platforms/google/mapping       { conversion_actions: {Lead:…,QuoteSent:…,Purchase:…}, events? }
 *  DELETE /ad-platforms/{meta|google}
 *  GET    /ad-platforms/deliveries           recent conversion events
 *  POST   /ad-platforms/deliveries/:id/retry
 */
class AdPlatformsController extends TravelBaseController
{
    private function svc(): AdCredentialsService
    {
        return new AdCredentialsService();
    }

    /** Run a service call, turning its exceptions into 422s with a readable message. */
    private function guard(callable $fn, int $code = 200)
    {
        try {
            return $this->ok($fn(), $code);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function status()            { return $this->ok($this->svc()->status(CurrentUser::tenantId())); }
    public function saveMeta()          { return $this->guard(fn () => $this->svc()->saveMeta(CurrentUser::tenantId(), $this->body())); }
    public function testMeta()         { return $this->guard(fn () => $this->svc()->testMeta(CurrentUser::tenantId())); }
    public function googleStart()      { return $this->guard(fn () => ['auth_url' => $this->svc()->googleStart(CurrentUser::tenantId(), CurrentUser::id())]); }
    public function googleCustomers()  { return $this->guard(fn () => $this->svc()->googleCustomers(CurrentUser::tenantId())); }
    public function googleActions()    { return $this->guard(fn () => $this->svc()->googleConversionActions(CurrentUser::tenantId())); }

    public function googleAccount()
    {
        $b = $this->body();
        return $this->guard(fn () => $this->svc()->googleSelect(CurrentUser::tenantId(), (string) ($b['customer_id'] ?? ''), (string) ($b['login_customer_id'] ?? '')));
    }

    public function googleMapping()
    {
        $b = $this->body();
        return $this->guard(fn () => $this->svc()->saveGoogleMapping(CurrentUser::tenantId(), (array) ($b['conversion_actions'] ?? []), $b['events'] ?? null));
    }

    public function disconnect($platform = '')
    {
        if (! in_array($platform, ['meta', 'google'], true)) {
            return $this->failNotFound('Unknown platform.');
        }
        $this->svc()->disconnect(CurrentUser::tenantId(), $platform);
        return $this->ok(['disconnected' => true]);
    }

    public function deliveries()
    {
        return $this->ok($this->svc()->deliveryLog(CurrentUser::tenantId()));
    }

    public function retry($id = null)
    {
        return $this->svc()->retry(CurrentUser::tenantId(), (int) $id) ? $this->ok(['queued' => true]) : $this->fail('Only failed or skipped events can be retried.', 409);
    }
}
