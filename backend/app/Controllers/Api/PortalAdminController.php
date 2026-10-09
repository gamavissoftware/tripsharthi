<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\BookingModel;
use App\Services\Auth\CurrentUser;
use App\Services\Travel\PortalService;

/** Staff side of the customer portal: the link, and reviewing what customers uploaded. */
class PortalAdminController extends TravelBaseController
{
    private function guard(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\RuntimeException $e) { log_message('error', 'portal admin: ' . $e->getMessage()); return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'error'], 500); }
    }

    public function link($bookingId = null)
    {
        return $this->guard(function () use ($bookingId) {
            $t = (new PortalService())->ensureToken(CurrentUser::tenantId(), (int) $bookingId);
            return ['token' => $t, 'url' => rtrim((string) base_url(), '/') . '/#/trip/' . $t];
        });
    }

    public function rotate($bookingId = null)
    {
        return $this->guard(function () use ($bookingId) {
            $t = (new PortalService())->rotateToken(CurrentUser::tenantId(), (int) $bookingId, CurrentUser::id());
            return ['token' => $t, 'url' => rtrim((string) base_url(), '/') . '/#/trip/' . $t];
        });
    }

    public function uploads($itemId = null)
    {
        return $this->guard(fn () => (new PortalService())->uploadsForItem(CurrentUser::tenantId(), (int) $itemId));
    }

    public function download($uploadId = null)
    {
        try { $f = (new PortalService())->readUpload(CurrentUser::tenantId(), (int) $uploadId); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 404); }
        catch (\Throwable $e) { log_message('error', 'portal download: ' . $e->getMessage()); return $this->respond(['success' => false, 'message' => 'This file cannot be opened. Ask the customer to upload it again.', 'kind' => 'error'], 500); }
        return $this->response->setHeader('Content-Type', $f['mime'])->setHeader('Content-Disposition', 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['name']) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')->setHeader('Content-Security-Policy', 'sandbox')->setHeader('Cache-Control', 'private, no-store')->setBody($f['bytes']);
    }

    public function review($uploadId = null)
    {
        $b = $this->body();
        return $this->guard(function () use ($uploadId, $b) { (new PortalService())->review(CurrentUser::tenantId(), (int) $uploadId, ($b['decision'] ?? '') === 'accept', $b['reason'] ?? null, CurrentUser::id()); return ['ok' => true]; });
    }
}
