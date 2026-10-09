<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\TenantModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * White-label / branding settings for the current tenant.
 *
 * GET  /api/v1/settings/branding  → getBranding()
 * POST /api/v1/settings/branding  → updateBranding()
 *
 * Branding data is stored as a JSON sub-object inside tenants.settings:
 *   { "branding": { app_name, primary_color, logo_url, support_email, custom_domain } }
 */
class SettingsController extends ResourceController
{
    protected $format = 'json';

    private const BRANDING_DEFAULTS = [
        'app_name'      => 'TripSarthi',
        // Matches the app's --primary token (index.css); #0a6cc4 is --primary-dark.
        'primary_color' => '#0a6cc4',
        'logo_url'      => null,
        'support_email' => null,
        'custom_domain' => null,
    ];

    // GET /api/v1/settings/branding
    public function getBranding(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $model    = new TenantModel();
        $tenant   = $model->find($tenantId);

        if ($tenant === null) {
            return $this->fail('Tenant not found.', 404);
        }

        $settings = $this->decodeSettings($tenant);
        $branding = array_merge(
            self::BRANDING_DEFAULTS,
            $settings['branding'] ?? []
        );

        return $this->respond([
            'success' => true,
            'data'    => $branding,
        ]);
    }

    // POST /api/v1/settings/branding
    public function updateBranding(): ResponseInterface
    {
        $rules = [
            'app_name'      => 'if_exist|max_length[255]',
            'primary_color' => 'if_exist|regex_match[/^#[0-9a-fA-F]{6}$/]',
            'logo_url'      => 'if_exist|permit_empty|valid_url_strict',
            'support_email' => 'if_exist|permit_empty|valid_email',
            'custom_domain' => 'if_exist|permit_empty|max_length[255]',
        ];

        $messages = [
            'primary_color' => [
                'regex_match' => 'primary_color must be a valid hex color (#rrggbb).',
            ],
            'logo_url' => [
                'valid_url_strict' => 'logo_url must be a valid URL.',
            ],
        ];

        if (! $this->validate($rules, $messages)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId = CurrentUser::tenantId();
        $model    = new TenantModel();
        $tenant   = $model->find($tenantId);

        if ($tenant === null) {
            return $this->fail('Tenant not found.', 404);
        }

        $allowed  = ['app_name', 'primary_color', 'logo_url', 'support_email', 'custom_domain'];
        $input    = $this->request->getJSON(true) ?? [];
        $incoming = array_intersect_key($input, array_flip($allowed));

        $settings           = $this->decodeSettings($tenant);
        $existing           = $settings['branding'] ?? [];
        $settings['branding'] = array_merge($existing, $incoming);

        $model->update($tenantId, ['settings' => json_encode($settings)]);

        $branding = array_merge(self::BRANDING_DEFAULTS, $settings['branding']);

        return $this->respond([
            'success' => true,
            'data'    => $branding,
            'message' => 'Branding updated successfully.',
        ]);
    }

    // GET /api/v1/settings/record-visibility
    public function getRecordVisibility(): ResponseInterface
    {
        $tenant = (new TenantModel())->find(CurrentUser::tenantId());
        if ($tenant === null) {
            return $this->fail('Tenant not found.', 404);
        }
        $mode = is_array($tenant) ? ($tenant['record_visibility'] ?? 'open') : ($tenant->record_visibility ?? 'open');
        return $this->respond(['success' => true, 'data' => ['record_visibility' => $mode]]);
    }

    // POST /api/v1/settings/record-visibility   { mode: open|owner|team }   (owner/admin only)
    public function updateRecordVisibility(): ResponseInterface
    {
        $mode = (string) $this->request->getJsonVar('mode');
        if (! in_array($mode, ['open', 'owner', 'team'], true)) {
            return $this->fail(['mode' => 'mode must be open, owner, or team.'], 422);
        }
        $model = new TenantModel();
        if ($model->find(CurrentUser::tenantId()) === null) {
            return $this->fail('Tenant not found.', 404);
        }
        $model->update(CurrentUser::tenantId(), ['record_visibility' => $mode]);
        return $this->respond(['success' => true, 'data' => ['record_visibility' => $mode], 'message' => 'Record visibility updated.']);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Decode the tenants.settings JSON column.
     * Works whether the ORM returns a string, array, or object.
     *
     * @param  array|object $tenant
     * @return array<string, mixed>
     */
    private function decodeSettings(array|object $tenant): array
    {
        $raw = is_array($tenant) ? ($tenant['settings'] ?? null) : ($tenant->settings ?? null);

        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}

/*
 * Route snippet to add inside the api/v1 group in app/Config/Routes.php:
 *
 * $routes->group('settings', ['filter' => 'auth'], static function (RouteCollection $routes): void {
 *     $routes->get('branding',  'Api\SettingsController::getBranding');
 *     $routes->post('branding', 'Api\SettingsController::updateBranding');
 * });
 */
