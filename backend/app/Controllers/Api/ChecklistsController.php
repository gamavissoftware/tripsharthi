<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Travel\ChecklistService;

/** Document / visa checklists per booking, and the upcoming-departures overview. */
class ChecklistsController extends TravelBaseController
{
    private function guard(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    public function overview()
    {
        $days = max(7, min(180, (int) ($this->request->getGet('days') ?: 60)));
        return $this->ok((new ChecklistService())->overview(CurrentUser::tenantId(), $days));
    }

    public function show($bookingId = null)
    {
        $tid = CurrentUser::tenantId();
        return $this->guard(function () use ($tid, $bookingId) {
            $svc = new ChecklistService();
            $r = $svc->forBooking($tid, (int) $bookingId);
            if ($r['items'] === []) { $svc->generate($tid, (int) $bookingId); $r = $svc->forBooking($tid, (int) $bookingId); }   // first look creates it
            return $r;
        });
    }

    public function generate($bookingId = null)
    {
        $tid = CurrentUser::tenantId();
        return $this->guard(function () use ($tid, $bookingId) { $svc = new ChecklistService(); $n = $svc->generate($tid, (int) $bookingId); return ['added' => $n] + $svc->forBooking($tid, (int) $bookingId); });
    }

    public function setStatus($itemId = null)
    {
        $b = $this->body();
        return $this->guard(fn () => (new ChecklistService())->setStatus(CurrentUser::tenantId(), (int) $itemId, (string) ($b['status'] ?? ''), isset($b['notes']) ? (string) $b['notes'] : null, CurrentUser::id()));
    }
}
