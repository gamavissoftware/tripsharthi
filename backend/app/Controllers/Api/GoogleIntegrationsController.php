<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\IntegrationModel;
use App\Services\Auth\CurrentUser;
use App\Services\Leads\GoogleLeadMapper;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Google Ads Lead Forms integration management.
 *
 * GET    /api/v1/google-integrations          — list tenant integrations
 * POST   /api/v1/google-integrations          — connect (generate a webhook key)
 * DELETE /api/v1/google-integrations/:id      — disconnect
 * POST   /api/v1/google-integrations/:id/test — simulate a test lead
 *
 * Unlike Meta there is no OAuth/page token and no programmatic "subscribe" step:
 * the business pastes the returned webhook URL + key into Google Ads → lead form
 * → "Deliver new leads" → Webhook integration. The integration is live on connect.
 */
class GoogleIntegrationsController extends ResourceController
{
    protected $format = 'json';

    private function model(): IntegrationModel
    {
        return (new IntegrationModel())->setTenant(CurrentUser::tenantId());
    }

    // GET /api/v1/google-integrations
    public function index(): ResponseInterface
    {
        $model = new IntegrationModel();
        $list  = $this->model()->where('type', 'google_lead_forms')->findAll();
        foreach ($list as &$row) {
            // The key is encrypted at rest; decrypt it for the owner to re-view
            // (their own authenticated session). Never expose the hash or ciphertext.
            $row['google_key'] = $model->decryptGoogleKey($row);
            $config = json_decode($row['config'] ?? '{}', true) ?: [];
            unset($config['google_key_enc']);
            $row['config'] = $config;
            unset($row['verify_token']);
        }
        return $this->respond([
            'success'     => true,
            'data'        => $list,
            'webhook_url' => base_url('webhooks/google-leads'),
        ]);
    }

    // POST /api/v1/google-integrations
    public function create(): ResponseInterface
    {
        $tenantId    = CurrentUser::tenantId();
        $countryCode = (string) ($this->request->getJsonVar('default_country_code') ?? '');
        $label       = trim((string) ($this->request->getJsonVar('label') ?? ''));
        $googleKey   = bin2hex(random_bytes(20)); // 40-char hex shared secret (160-bit CSPRNG)

        $config = json_encode([
            'default_country_code' => $countryCode,
            'label'                => $label,
            // Encrypted copy so the owner can re-view the key; the raw key is never stored.
            'google_key_enc'       => TokenCipher::encrypt($googleKey),
        ]);

        // Upsert (restores a soft-deleted row) so reconnecting doesn't hit the
        // UNIQUE(tenant_id, type) "Duplicate entry" error. verify_token stores a
        // SHA-256 hash of the key (replay-safe webhook lookup), not the key itself.
        $id = (new IntegrationModel())->saveConfig($tenantId, 'google_lead_forms', [
            'verify_token' => IntegrationModel::hashGoogleKey($googleKey),
            'config'       => $config,
        ]);

        $row = $this->model()->find((int) $id);
        unset($row['config'], $row['verify_token']);

        return $this->respondCreated([
            'success'     => true,
            'data'        => $row,
            'google_key'  => $googleKey,
            'webhook_url' => base_url('webhooks/google-leads'),
            'note'        => 'In Google Ads → your Lead form → "Deliver new leads" → '
                . 'Webhook integration, paste the Webhook URL and the Key, then "Send test data".',
        ]);
    }

    // DELETE /api/v1/google-integrations/:id
    public function delete($id = null): ResponseInterface
    {
        $row = $this->model()->find((int) $id);
        if (! $row) return $this->failNotFound("Integration #{$id} not found.");
        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    // POST /api/v1/google-integrations/:id/test
    // Simulates a Google lead arriving — creates a test contact and fires flows.
    public function test($id = null): ResponseInterface
    {
        $row = $this->model()->find((int) $id);
        if (! $row) return $this->failNotFound("Integration #{$id} not found.");

        $tenantId = CurrentUser::tenantId();

        try {
            $config             = json_decode($row['config'] ?? '{}', true) ?: [];
            $defaultCountryCode = (string) ($config['default_country_code'] ?? '');

            // Build mock user_column_data as Google would push it.
            $mockColumns = [
                ['column_id' => 'FULL_NAME',    'column_name' => 'Full name', 'string_value' => 'Test Lead from Google ' . date('H:i')],
                ['column_id' => 'PHONE_NUMBER', 'column_name' => 'Phone',     'string_value' => '+919000' . rand(100000, 999999)],
                ['column_id' => 'EMAIL',        'column_name' => 'Email',     'string_value' => 'testlead_' . time() . '@googledemo.test'],
            ];
            $mapped = GoogleLeadMapper::map($mockColumns, $defaultCountryCode);

            if (empty($mapped['wa_number'])) {
                $mapped['wa_number'] = '+919999900000'; // fallback for test
            }
            $mapped['source'] = 'google_lead_forms';

            $dedupe = new \App\Services\Leads\ContactDedupeService(
                new \App\Models\ContactModel(),
                new \App\Models\ContactFieldValueModel()
            );
            $result = $dedupe->upsert($tenantId, $mapped);

            // upsert() already fires lead_created + google_lead_received via the
            // source-based wiring; no explicit fire() needed here.

            return $this->respond([
                'success'    => true,
                'message'    => 'Test lead created successfully! Check Contacts for the new lead.',
                'contact_id' => $result['contact_id'],
                'action'     => $result['action'] ?? 'created',
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'GoogleIntegrationsController::test error: ' . $e->getMessage());
            return $this->fail('Test lead failed: ' . $e->getMessage(), 500);
        }
    }
}
