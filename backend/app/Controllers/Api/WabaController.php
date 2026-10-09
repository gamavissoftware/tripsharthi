<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\PhoneNumberModel;
use App\Models\WabaAccountModel;
use App\Services\Auth\CurrentUser;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\ProviderAdapter;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * WABA account management — manual paste-in connection for Sprint 2.
 * Embedded Signup (OAuth) is deferred to a later sprint.
 *
 * GET  /api/v1/waba                — get current WABA account for tenant
 * POST /api/v1/waba                — create or update WABA connection
 * GET  /api/v1/waba/test           — test Meta API connectivity
 * GET  /api/v1/waba/phone-numbers  — list connected phone numbers
 * POST /api/v1/waba/phone-numbers  — add a phone number
 */
class WabaController extends ResourceController
{
    protected $format = 'json';

    // GET /api/v1/waba
    public function show($id = null): ResponseInterface
    {
        $account = (new WabaAccountModel())->setTenant(CurrentUser::tenantId())->first();

        // The callback URL the customer pastes into Meta must be the app's public
        // base URL — deriving it from the browser origin hands them localhost.
        $webhookUrl = rtrim(base_url('webhooks/whatsapp'), '/');

        if (! $account) {
            return $this->respond([
                'success'     => true,
                'data'        => null,
                'connected'   => false,
                'webhook_url' => $webhookUrl,
            ]);
        }

        // Never return the encrypted token to the frontend
        unset($account['access_token_enc']);

        // Decode provider_config_json for response (strip sensitive keys)
        if (! empty($account['provider_config_json'])) {
            $cfg = json_decode($account['provider_config_json'], true) ?? [];
            // Mask token/password fields — show only last 4 chars
            foreach (['access_token', 'auth_token', 'api_key', 'auth_value'] as $sensitive) {
                if (isset($cfg[$sensitive]) && strlen($cfg[$sensitive]) > 4) {
                    $cfg[$sensitive] = str_repeat('*', strlen($cfg[$sensitive]) - 4) . substr($cfg[$sensitive], -4);
                }
            }
            $account['provider_config'] = $cfg;
        }
        unset($account['provider_config_json']);

        return $this->respond([
            'success'     => true,
            'data'        => $account,
            'connected'   => true,
            'webhook_url' => $webhookUrl,
        ]);
    }

    // GET /api/v1/waba/providers — return available providers + their form fields
    public function providers(): ResponseInterface
    {
        return $this->respond([
            'success'   => true,
            'providers' => [
                ['id' => 'meta',      'name' => 'Meta Cloud API',  'description' => 'Direct WhatsApp Business API — WABA required'],
                ['id' => 'wati',      'name' => 'WATI',            'description' => 'WhatsApp Team Inbox — popular BSP'],
                ['id' => 'aisensy',   'name' => 'AiSensy',         'description' => 'AiSensy WhatsApp Business Platform'],
                ['id' => '360dialog', 'name' => '360dialog',       'description' => 'Meta official BSP partner'],
                ['id' => 'twilio',    'name' => 'Twilio',          'description' => 'Twilio WhatsApp Sandbox / Business'],
                ['id' => 'custom',    'name' => 'Custom API',      'description' => 'Any OpenAPI-compatible provider'],
            ],
            'fields' => ProviderAdapter::providerFields(),
        ]);
    }

    // POST /api/v1/waba
    public function connect(): ResponseInterface
    {
        $provider = $this->request->getJsonVar('provider') ?? 'meta';

        // Validation rules differ by provider
        if ($provider === 'meta') {
            $rules = [
                'waba_id'         => 'required|max_length[100]',
                'phone_number_id' => 'required|max_length[100]',
                'access_token'    => 'required|min_length[10]',
                'display_name'    => 'permit_empty|max_length[255]',
                'business_id'     => 'permit_empty|max_length[100]',
            ];
        } else {
            $rules = [
                'provider_config' => 'required',
                'display_name'    => 'permit_empty|max_length[255]',
            ];
        }

        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId    = CurrentUser::tenantId();
        $displayName = $this->request->getJsonVar('display_name') ?? '';
        $model       = new WabaAccountModel();
        $existing    = $model->setTenant($tenantId)->first();
        $verifyToken = bin2hex(random_bytes(20));

        if ($provider === 'meta') {
            $rawToken   = $this->request->getJsonVar('access_token');
            $phoneNumId = $this->request->getJsonVar('phone_number_id');
            $businessId = $this->request->getJsonVar('business_id') ?? '';
            $appId      = $this->request->getJsonVar('app_id') ?? '';

            if ($existing !== null) {
                $accountId = (int) $existing['id'];
                $model->setTenant($tenantId)->update($accountId, [
                    'waba_id'      => $this->request->getJsonVar('waba_id'),
                    'business_id'  => $businessId,
                    'app_id'       => $appId,
                    'display_name' => $displayName,
                    'verify_token' => $verifyToken,
                    'provider'     => 'meta',
                    'status'       => 'pending',
                ]);
                if ($rawToken) $model->setToken($accountId, $rawToken);
            } else {
                $accountId = (int) $model->withoutTenantScope()->insert([
                    'tenant_id'        => $tenantId,
                    'waba_id'          => $this->request->getJsonVar('waba_id'),
                    'business_id'      => $businessId,
                    'app_id'           => $appId,
                    'display_name'     => $displayName,
                    'access_token_enc' => TokenCipher::encrypt($rawToken),
                    'verify_token'     => $verifyToken,
                    'provider'         => 'meta',
                    'status'           => 'pending',
                ], true);
            }

            // Upsert phone_number row (Meta only)
            $pnModel  = new PhoneNumberModel();
            $existPn  = $pnModel->setTenant($tenantId)->where('phone_number_id', $phoneNumId)->first();
            if ($existPn !== null) {
                $pnModel->setTenant($tenantId)->update((int) $existPn['id'], [
                    'waba_account_id' => $accountId,
                    'display_number'  => $displayName,
                ]);
            } else {
                $pnModel->withoutTenantScope()->insert([
                    'waba_account_id' => $accountId,
                    'tenant_id'       => $tenantId,
                    'phone_number_id' => $phoneNumId,
                    'display_number'  => $displayName,
                    'is_default'      => 1,
                ]);
            }

            $webhookUrl = base_url('webhooks/whatsapp');
            return $this->respondCreated([
                'success'      => true,
                'message'      => 'WhatsApp (Meta) account saved. Register the webhook URL in Meta for Developers.',
                'webhook_url'  => $webhookUrl,
                'verify_token' => $verifyToken,
            ]);
        }

        // ── Non-Meta provider ─────────────────────────────────────────────────
        $providerConfig = $this->request->getJsonVar('provider_config') ?? [];

        if ($existing !== null) {
            $accountId = (int) $existing['id'];
            $model->setTenant($tenantId)->update($accountId, [
                'provider'             => $provider,
                'provider_config_json' => json_encode($providerConfig),
                'display_name'         => $displayName,
                'verify_token'         => $verifyToken,
                'status'               => 'pending',
            ]);
        } else {
            $accountId = (int) $model->withoutTenantScope()->insert([
                'tenant_id'            => $tenantId,
                'provider'             => $provider,
                'provider_config_json' => json_encode($providerConfig),
                'display_name'         => $displayName,
                'verify_token'         => $verifyToken,
                'status'               => 'pending',
                'waba_id'              => '',
            ], true);
        }

        // Test connection immediately for non-Meta providers
        $account = $model->withoutTenantScope()->find($accountId);
        $adapter = ProviderAdapter::fromAccount(is_array($account) ? $account : (array) $account);
        $test    = $adapter->testConnection();
        if ($test['success']) {
            $model->withoutTenantScope()->update($accountId, ['status' => 'active']);
        }

        return $this->respondCreated([
            'success' => true,
            'message' => ucfirst($provider) . ' account saved' . ($test['success'] ? ' and verified.' : '. Connection test failed — check your credentials.'),
            'tested'  => $test,
        ]);
    }

    // GET /api/v1/waba/test
    public function testConnection(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $model    = new WabaAccountModel();
        $account  = $model->setTenant($tenantId)->first();

        if (! $account) {
            return $this->fail('No WABA account configured.', 422);
        }

        $pn = (new PhoneNumberModel())->defaultForTenant($tenantId);
        if (! $pn) {
            return $this->fail('No phone number configured.', 422);
        }

        $rawToken = $model->getDecryptedToken($account);
        if (empty($rawToken)) {
            return $this->fail('Access token is missing or could not be decrypted.', 422);
        }

        $client = new CloudApiClient(
            is_array($pn) ? $pn['phone_number_id'] : $pn->phone_number_id,
            $rawToken
        );
        $result = $client->testConnection();

        if ($result['success']) {
            // Update quality_rating from Meta's response
            $pnModel = new PhoneNumberModel();
            $update = [
                'quality_rating' => strtolower($result['info']['quality_rating'] ?? 'unknown'),
            ];

            // Connect-time we only know the WABA's display *name*; Meta returns the
            // actual number here, so correct the row rather than showing a name
            // where the UI promises a phone number.
            if (! empty($result['info']['display_phone_number'])) {
                $update['display_number'] = $result['info']['display_phone_number'];
            }

            $pnModel->setTenant($tenantId)->update((int) (is_array($pn) ? $pn['id'] : $pn->id), $update);

            // Mark account as active
            $model->setTenant($tenantId)->update((int) $account['id'], ['status' => 'active']);
        }

        return $this->respond(['success' => $result['success'], 'data' => $result]);
    }

    // GET /api/v1/waba/phone-numbers
    public function phoneNumbers(): ResponseInterface
    {
        $numbers = (new PhoneNumberModel())->setTenant(CurrentUser::tenantId())->findAll();
        return $this->respond(['success' => true, 'data' => $numbers]);
    }
}
