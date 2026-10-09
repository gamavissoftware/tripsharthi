<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\TenantModel;
use App\Services\Auth\CurrentUser;
use App\Services\Crm\CurrencyService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Tenant display preferences (Phase J4) — currently the default currency.
 *
 * GET /crm/preferences           { default_currency, currencies, symbols }
 * PUT /crm/preferences           { default_currency }   (owner/admin)
 */
class PreferencesController extends ResourceController
{
    protected $format = 'json';

    public function index(): ResponseInterface
    {
        return $this->respond(['success' => true, 'data' => [
            'default_currency' => CurrencyService::tenantDefault(CurrentUser::tenantId()),
            'currencies'       => CurrencyService::codes(),
            'symbols'          => CurrencyService::SYMBOL,
        ]]);
    }

    public function save(): ResponseInterface
    {
        $role = (string) (CurrentUser::get()['role'] ?? 'agent');
        if (! in_array($role, ['owner', 'admin'], true)) {
            return $this->failForbidden('Only owners and admins can change preferences.');
        }
        $code = strtoupper((string) $this->request->getJsonVar('default_currency'));
        if (! in_array($code, CurrencyService::codes(), true)) {
            return $this->fail(['default_currency' => 'Unsupported currency.'], 422);
        }

        $tenantId = CurrentUser::tenantId();
        $model    = new TenantModel();
        $tenant   = $model->find($tenantId);
        $settings = $tenant ? (json_decode($tenant['settings'] ?? '{}', true) ?: []) : [];
        $settings['default_currency'] = $code;
        $model->update($tenantId, ['settings' => json_encode($settings)]);

        return $this->respond(['success' => true, 'data' => ['default_currency' => $code]]);
    }
}
