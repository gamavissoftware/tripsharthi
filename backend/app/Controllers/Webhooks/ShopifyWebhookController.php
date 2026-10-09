<?php

declare(strict_types=1);

namespace App\Controllers\Webhooks;

use App\Models\IntegrationModel;
use App\Services\Commerce\EcommerceSignature;
use App\Services\Commerce\EcommerceWebhookService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shopify webhooks — POST /webhooks/shopify
 *
 * Tenant resolved from X-Shopify-Shop-Domain (stored in integrations.page_id),
 * then HMAC verified with that tenant's webhook secret. Always 200.
 */
class ShopifyWebhookController extends Controller
{
    public function receive(): ResponseInterface
    {
        $rawBody = $this->request->getBody() ?? '';
        $topic   = $this->request->getHeaderLine('X-Shopify-Topic');
        $shop    = strtolower(trim($this->request->getHeaderLine('X-Shopify-Shop-Domain')));
        $sig     = $this->request->getHeaderLine('X-Shopify-Hmac-Sha256');

        if ($shop === '') {
            return $this->ok();
        }

        $model       = new IntegrationModel();
        $integration = $model->findByTypeCrossTenant('shopify', 'page_id', $shop);
        if ($integration === null) {
            log_message('info', "ShopifyWebhook: unknown shop '{$shop}' — ignored.");
            return $this->ok();
        }
        $integration = is_array($integration) ? $integration : (array) $integration;

        $secret = $model->decryptWebhookSecret($integration);
        if (! EcommerceSignature::verify($rawBody, $sig, $secret)) {
            log_message('error', "ShopifyWebhook: signature mismatch for shop '{$shop}'.");
            return $this->ok();
        }

        $payload = json_decode($rawBody, true) ?: [];
        try {
            (new EcommerceWebhookService())->process(
                (int) $integration['tenant_id'],
                'shopify',
                $topic,
                $payload,
                $model->configValue($integration, 'default_country_code'),
            );
        } catch (\Throwable $e) {
            log_message('error', 'ShopifyWebhook: ' . $e->getMessage());
        }

        return $this->ok();
    }

    private function ok(): ResponseInterface
    {
        return $this->response->setStatusCode(200)->setBody('OK');
    }
}
