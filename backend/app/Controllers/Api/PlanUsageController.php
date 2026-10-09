<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Billing\PlanLimitChecker;
use App\Services\Tenancy\FeatureGate;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Plan usage vs limits across all enforced dimensions (Phase K2). GET /crm/plan-usage
 */
class PlanUsageController extends ResourceController
{
    protected $format = 'json';

    public function index(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $plan     = CurrentUser::tenantPlan();
        $usage    = (new PlanLimitChecker())->usageCounts($tenantId);
        $limits   = FeatureGate::getPlanLimits($plan);

        return $this->respond([
            'success'   => true,
            'data'      => [
                'plan'      => FeatureGate::isSelfHosted() ? 'self_hosted' : $plan,
                'unlimited' => FeatureGate::isSelfHosted(),
                'usage'     => $usage,
                'limits'    => $limits,
            ],
        ]);
    }
}
