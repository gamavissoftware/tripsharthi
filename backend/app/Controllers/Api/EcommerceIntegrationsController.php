<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\IntegrationModel;
use App\Services\Auth\CurrentUser;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Connect / disconnect Shopify & WooCommerce stores.
 *
 * The store identifier (Shopify shop domain or WooCommerce store URL) is stored
 * in integrations.page_id for O(1) webhook tenant resolution; the webhook secret
 * is encrypted in config.
 */
class EcommerceIntegrationsController extends ResourceController
{
    protected $format = 'json';

    private const PLATFORMS = ['shopify', 'woocommerce'];

    // GET /api/v1/ecommerce/:platform
    public function show($platform = null): ResponseInterface
    {
        if (! in_array($platform, self::PLATFORMS, true)) {
            return $this->fail('Unknown platform.', 422);
        }
        $model       = new IntegrationModel();
        $integration = $model->findActiveByType(CurrentUser::tenantId(), $platform);

        return $this->respond(['success' => true, 'data' => [
            'connected'            => $integration !== null,
            'store'                => $integration['page_id'] ?? '',
            'default_country_code' => $integration ? $model->configValue($integration, 'default_country_code') : '',
            'webhook_url'          => site_url('webhooks/' . ($platform === 'shopify' ? 'shopify' : 'woocommerce')),
        ]]);
    }

    // POST /api/v1/ecommerce/:platform
    public function connect($platform = null): ResponseInterface
    {
        if (! in_array($platform, self::PLATFORMS, true)) {
            return $this->fail('Unknown platform.', 422);
        }
        if (! $this->validate(['store' => 'required|string', 'webhook_secret' => 'required|string'])) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId = CurrentUser::tenantId();
        $store    = rtrim(strtolower(trim((string) $this->request->getJsonVar('store'))), '/');
        $config   = [
            'webhook_secret_enc'   => TokenCipher::encrypt(trim((string) $this->request->getJsonVar('webhook_secret'))),
            'default_country_code' => preg_replace('/\D/', '', (string) ($this->request->getJsonVar('default_country_code') ?? '')),
        ];

        $model    = new IntegrationModel();
        $existing = $model->findActiveByType($tenantId, $platform);
        if ($existing) {
            $model->setTenant($tenantId)->update((int) $existing['id'], [
                'page_id' => $store, 'config' => json_encode($config), 'status' => 'active',
            ]);
        } else {
            $model->setTenant($tenantId)->insert([
                'type' => $platform, 'page_id' => $store, 'config' => json_encode($config), 'status' => 'active',
            ]);
        }

        return $this->respond(['success' => true, 'message' => ucfirst($platform) . ' connected.']);
    }

    // DELETE /api/v1/ecommerce/:platform
    public function disconnect($platform = null): ResponseInterface
    {
        if (! in_array($platform, self::PLATFORMS, true)) {
            return $this->fail('Unknown platform.', 422);
        }
        $model       = new IntegrationModel();
        $integration = $model->findActiveByType(CurrentUser::tenantId(), $platform);
        if ($integration) {
            $model->setTenant(CurrentUser::tenantId())->delete((int) $integration['id']);
        }
        return $this->respondDeleted(['success' => true]);
    }
}
