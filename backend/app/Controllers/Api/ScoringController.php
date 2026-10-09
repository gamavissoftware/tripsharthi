<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\LeadScoringRuleModel;
use App\Services\Auth\CurrentUser;
use App\Services\Crm\LeadScoringService;
use App\Services\Tenancy\FeatureGate;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Lead-scoring configuration + recompute (Phase H1).
 *
 * GET  /crm/scoring-rules    effective weights + thresholds + whether editable
 * PUT  /crm/scoring-rules    update weights/thresholds (Pro in SaaS; open self-hosted)
 * POST /crm/scoring/recalc   recompute every contact's score for the tenant
 */
class ScoringController extends ResourceController
{
    protected $format = 'json';

    /** Editing weights is a Pro feature in SaaS; self-hosted always has it. */
    private function canEdit(): bool
    {
        return FeatureGate::isSelfHosted() || CurrentUser::tenantPlan() !== 'free';
    }

    public function index(): ResponseInterface
    {
        $cfg = (new LeadScoringService())->rulesFor(CurrentUser::tenantId());
        return $this->respond(['success' => true, 'data' => [
            'weights'        => $cfg['weights'],
            'hot_threshold'  => $cfg['hot'],
            'warm_threshold' => $cfg['warm'],
            'defaults'       => LeadScoringService::DEFAULTS,
            'editable'       => $this->canEdit(),
        ]]);
    }

    public function save(): ResponseInterface
    {
        // Tenant-wide scoring config is an admin concern, and a Pro feature in SaaS.
        $role = (string) (CurrentUser::get()['role'] ?? 'agent');
        if (! in_array($role, ['owner', 'admin'], true)) {
            return $this->failForbidden('Only owners and admins can edit scoring weights.');
        }
        if (! $this->canEdit()) {
            return $this->failForbidden('Editing scoring weights requires a Pro plan.');
        }
        $weights = $this->request->getJsonVar('weights', true);
        $hot     = $this->request->getJsonVar('hot_threshold');
        $warm    = $this->request->getJsonVar('warm_threshold');

        $tenantId = CurrentUser::tenantId();
        $model    = new LeadScoringRuleModel();
        $existing = $model->setTenant($tenantId)->where('tenant_id', $tenantId)->first();

        $payload = ['tenant_id' => $tenantId];
        if (is_array($weights)) {
            $payload['weights'] = json_encode($weights);
        }
        if ($hot !== null) {
            $payload['hot_threshold'] = (int) $hot;
        }
        if ($warm !== null) {
            $payload['warm_threshold'] = (int) $warm;
        }

        if ($existing) {
            $model->setTenant($tenantId)->update($existing['id'], $payload);
        } else {
            $model->setTenant($tenantId)->insert($payload);
        }

        // Re-score everyone against the new rules so lists/badges update immediately.
        $n = (new LeadScoringService())->recalcTenant($tenantId);
        return $this->respond(['success' => true, 'recalculated' => $n]);
    }

    public function recalc(): ResponseInterface
    {
        $n = (new LeadScoringService())->recalcTenant(CurrentUser::tenantId());
        return $this->respond(['success' => true, 'recalculated' => $n]);
    }
}
