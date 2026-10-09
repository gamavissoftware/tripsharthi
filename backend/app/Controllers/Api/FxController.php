<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Travel\Currency;
use App\Services\Travel\FxService;

/** Exchange rates. Everyone can read (agents price lines with them); owner/admin change them. */
class FxController extends TravelBaseController
{
    private function svc(): FxService { return new FxService(); }

    private function guard(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    public function index()
    {
        $held = $this->svc()->list(CurrentUser::tenantId());
        $have = array_column($held, 'currency');
        return $this->ok(['rates' => $held, 'available' => array_values(array_filter(array_map(static fn ($c, $m) => $c === 'INR' || in_array($c, $have, true) ? null : ['code' => $c, 'name' => $m[0], 'symbol' => trim($m[1])], array_keys(Currency::LIST), Currency::LIST)))]);
    }

    /** POST /fx { currency, rate?, buffer_pct? } — no rate = fetch the automatic one. */
    public function add()
    {
        $b = $this->body(); $tid = CurrentUser::tenantId();
        return $this->guard(function () use ($b, $tid) {
            $c = strtoupper((string) ($b['currency'] ?? ''));
            if (isset($b['rate']) && $b['rate'] !== '') { return $this->svc()->setRate($tid, $c, (float) $b['rate'], isset($b['buffer_pct']) ? (float) $b['buffer_pct'] : null, CurrentUser::id(), ! empty($b['confirm'])); }
            $r = $this->svc()->addAuto($tid, $c, CurrentUser::id());
            if (isset($b['buffer_pct'])) { $r = $this->svc()->setBuffer($tid, $c, (float) $b['buffer_pct'], CurrentUser::id()); }
            return $r;
        }, 201);
    }

    public function update($currency = null)
    {
        $b = $this->body(); $tid = CurrentUser::tenantId(); $c = strtoupper((string) $currency);
        return $this->guard(function () use ($b, $tid, $c) {
            if (isset($b['rate']) && $b['rate'] !== '') { return $this->svc()->setRate($tid, $c, (float) $b['rate'], isset($b['buffer_pct']) ? (float) $b['buffer_pct'] : null, CurrentUser::id(), ! empty($b['confirm'])); }
            if (isset($b['buffer_pct'])) { return $this->svc()->setBuffer($tid, $c, (float) $b['buffer_pct'], CurrentUser::id()); }
            throw new \InvalidArgumentException('Nothing to change.');
        });
    }

    public function auto($currency = null) { return $this->guard(fn () => $this->svc()->resumeAuto(CurrentUser::tenantId(), strtoupper((string) $currency))); }
    public function refresh() { return $this->guard(fn () => $this->svc()->refresh(CurrentUser::tenantId())); }
    public function remove($currency = null) { return $this->guard(function () use ($currency) { $this->svc()->remove(CurrentUser::tenantId(), strtoupper((string) $currency), CurrentUser::id()); return ['deleted' => true]; }); }
    public function history($currency = null) { return $this->guard(fn () => $this->svc()->history(CurrentUser::tenantId(), strtoupper((string) $currency))); }
}
