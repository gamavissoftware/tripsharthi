<?php

declare(strict_types=1);

namespace App\Controllers\Webhooks;

use App\Models\IntegrationModel;
use App\Services\Commerce\EcommerceSignature;
use App\Services\Commerce\EcommerceWebhookService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * WooCommerce webhooks — POST /webhooks/woocommerce
 *
 * Tenant resolved from X-WC-Webhook-Source (the store URL, stored in
 * integrations.page_id), then HMAC verified with that tenant's secret.
 * Always 200.
 */
class WooCommerceWebhookController extends Controller
{
    public function receive(): ResponseInterface
    {
        $rawBody = $this->request->getBody() ?? '';
        $topic   = $this->request->getHeaderLine('X-WC-Webhook-Topic');
        $source  = rtrim(strtolower(trim($this->request->getHeaderLine('X-WC-Webhook-Source'))), '/');
        $sig     = $this->request->getHeaderLine('X-WC-Webhook-Signature');

        if ($source === '') {
            return $this->ok();
        }

        $model       = new IntegrationModel();
        $integration = $model->findByTypeCrossTenant('woocommerce', 'page_id', $source);
        if ($integration === null) {
            log_message('info', "WooWebhook: unknown store '{$source}' — ignored.");
            return $this->ok();
        }
        $integration = is_array($integration) ? $integration : (array) $integration;

        $secret = $model->decryptWebhookSecret($integration);
        if (! EcommerceSignature::verify($rawBody, $sig, $secret)) {
            log_message('error', "WooWebhook: signature mismatch for store '{$source}'.");
            return $this->ok();
        }

        $payload = json_decode($rawBody, true) ?: [];
        try {
            (new EcommerceWebhookService())->process(
                (int) $integration['tenant_id'],
                'woocommerce',
                $topic,
                $payload,
                $model->configValue($integration, 'default_country_code'),
            );
        } catch (\Throwable $e) {
            log_message('error', 'WooWebhook: ' . $e->getMessage());
        }

        return $this->ok();
    }

    private function ok(): ResponseInterface
    {
        return $this->response->setStatusCode(200)->setBody('OK');
    }
}
