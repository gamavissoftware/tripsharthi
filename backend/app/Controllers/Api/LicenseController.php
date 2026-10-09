<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Licensing\LicenseService;
use App\Services\Tenancy\FeatureGate;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * License key management — self_hosted mode only.
 *
 * POST /api/v1/license/activate  — first-time activation (requires network)
 * GET  /api/v1/license/status    — current license state (DB read only)
 *
 * Both endpoints gate on FeatureGate::isLicenseRequired() → 403 in saas mode.
 */
class LicenseController extends ResourceController
{
    protected $format = 'json';

    // POST /api/v1/license/activate
    public function activate(): ResponseInterface
    {
        if (! FeatureGate::isLicenseRequired()) {
            return $this->fail('License management is not available in SaaS mode.', 403);
        }

        $rules = ['key' => 'required|min_length[10]'];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $rawKey = trim((string) $this->request->getJsonVar('key'));

        try {
            (new LicenseService())->activate($rawKey);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 503);
        }

        $status = (new LicenseService())->checkStatus();

        return $this->respond([
            'success' => true,
            'status'  => $status->reason,
            'message' => 'License activated successfully.',
        ], 201);
    }

    // GET /api/v1/license/status
    public function status(): ResponseInterface
    {
        if (! FeatureGate::isLicenseRequired()) {
            return $this->fail('License management is not available in SaaS mode.', 403);
        }

        $status = (new LicenseService())->checkStatus();

        return $this->respond([
            'success'        => true,
            'is_active'      => $status->isActive,
            'is_read_only'   => $status->isReadOnly,
            'grace_days_left'=> $status->graceDaysLeft,
            'reason'         => $status->reason,
        ]);
    }
}
