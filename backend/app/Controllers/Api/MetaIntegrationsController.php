<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\IntegrationModel;
use App\Models\SocialAccountModel;
use App\Services\Auth\CurrentUser;
use App\Services\Leads\MetaLeadAdsLinker;
use App\Services\Leads\MetaLeadMapper;
use App\Services\Social\FacebookOAuthService;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Meta Lead Ads integration management.
 *
 * GET    /api/v1/meta-integrations          — list tenant integrations
 * POST   /api/v1/meta-integrations          — connect a Facebook Page (pasted token — manual fallback)
 * POST   /api/v1/meta-integrations/link-page — connect a Page via Facebook Login (preferred: no token in the browser)
 * DELETE /api/v1/meta-integrations/:id      — disconnect
 * POST   /api/v1/meta-integrations/:id/subscribe — subscribe page to leadgen webhook
 */
class MetaIntegrationsController extends ResourceController
{
    protected $format = 'json';

    private function model(): IntegrationModel
    {
        return (new IntegrationModel())->setTenant(CurrentUser::tenantId());
    }

    // GET /api/v1/meta-integrations
    public function index(): ResponseInterface
    {
        $list = $this->model()->where('type', 'meta_lead_ads')->findAll();
        // Never return encrypted token
        foreach ($list as &$row) {
            $config = json_decode($row['config'] ?? '{}', true) ?: [];
            unset($config['page_access_token_enc']);
            $row['config'] = $config;
        }
        // Pages already connected with Facebook Login (Social Planner). Any of
        // them can be used for Lead Ads without a second login — display fields
        // only, never the ciphertext.
        $accounts = new SocialAccountModel();
        $pages    = array_map(
            static fn ($r) => $accounts->publicRow((array) $r),
            $accounts->setTenant(CurrentUser::tenantId())->findAll()
        );

        return $this->respond([
            'success' => true,
            'data'    => $list,
            // Meta calls this URL — derive it from the app's public base URL.
            'webhook_url'     => rtrim(base_url('webhooks/meta-leads'), '/'),
            'connected_pages' => $pages,
            'facebook_login'  => [
                'configured' => FacebookOAuthService::isConfigured(),
                'login_mode' => FacebookOAuthService::loginConfigId() !== '' ? 'business' : 'classic',
            ],
        ]);
    }

    // POST /api/v1/meta-integrations/link-page
    //
    // Two ways in, both without a token ever touching the browser:
    //   { state, page_id }     — fresh from the Facebook Login page picker
    //   { social_account_id }  — a Page the Social Planner already connected
    // Links the Page to Lead Ads, then tries the leadgen subscription. The link
    // is kept even when the subscription is refused: the usual reason is a token
    // minted before pages_manage_metadata was granted, and the response says so.
    public function linkPage(): ResponseInterface
    {
        $tenantId    = CurrentUser::tenantId();
        $state       = trim((string) ($this->request->getJsonVar('state') ?? ''));
        $pageId      = trim((string) ($this->request->getJsonVar('page_id') ?? ''));
        $accountId   = (int) ($this->request->getJsonVar('social_account_id') ?? 0);
        $countryCode = trim((string) ($this->request->getJsonVar('default_country_code') ?? ''));

        try {
            if ($state !== '') {
                if ($pageId === '') {
                    return $this->fail(['page_id' => 'Choose a Page.'], 422);
                }
                $account   = (new FacebookOAuthService())->selectPage($state, $pageId, $tenantId, CurrentUser::id());
                $accountId = (int) $account['id'];
            }
            if ($accountId <= 0) {
                return $this->fail(['error' => 'Pick a connected Page, or connect one with Facebook first.'], 422);
            }

            $linker = new MetaLeadAdsLinker();
            $link   = $linker->linkSocialAccount($tenantId, $accountId, $countryCode);

            $subscribeError = null;
            try {
                $linker->subscribe($tenantId, $link['id']);
            } catch (\Throwable $e) {
                $subscribeError = $e->getMessage();
                log_message('warning', "MetaIntegrations: leadgen subscribe failed for tenant {$tenantId}: {$subscribeError}");
            }
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 422);
        }

        $row    = (array) $this->model()->find($link['id']);
        $config = json_decode($row['config'] ?? '{}', true) ?: [];
        unset($config['page_access_token_enc']);
        $row['config'] = $config;

        return $this->respondCreated([
            'success'         => true,
            'data'            => $row,
            'subscribed'      => $subscribeError === null,
            'subscribe_error' => $subscribeError,
            'verify_token'    => $link['verify_token'],
            'webhook_url'     => rtrim(base_url('webhooks/meta-leads'), '/'),
        ]);
    }

    // POST /api/v1/meta-integrations
    public function create(): ResponseInterface
    {
        $rules = [
            'page_id'            => 'required|max_length[100]',
            'page_access_token'  => 'required|min_length[10]',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId   = CurrentUser::tenantId();
        $rawToken   = $this->request->getJsonVar('page_access_token');
        $pageId     = $this->request->getJsonVar('page_id');
        $countryCode = $this->request->getJsonVar('default_country_code') ?? '';
        $verifyToken = bin2hex(random_bytes(20)); // 40-char hex for webhook challenge

        $config = json_encode([
            'page_access_token_enc' => TokenCipher::encrypt($rawToken),
            'default_country_code'  => $countryCode,
        ]);

        // Upsert (restores a soft-deleted row) so reconnecting a Page doesn't hit
        // the UNIQUE(tenant_id, type) "Duplicate entry" error.
        $id = (new IntegrationModel())->saveConfig($tenantId, 'meta_lead_ads', [
            'page_id'      => $pageId,
            'verify_token' => $verifyToken,
            'config'       => $config,
        ]);

        $row = $this->model()->find((int) $id);
        unset($row['config']); // don't return encrypted token

        return $this->respondCreated([
            'success'      => true,
            'data'         => $row,
            'verify_token' => $verifyToken,
            'webhook_url'  => base_url('webhooks/meta-leads'),
            'note'         => 'Register the webhook_url + verify_token in Meta for Developers → App → Facebook Login → Webhook.',
        ]);
    }

    // DELETE /api/v1/meta-integrations/:id
    public function delete($id = null): ResponseInterface
    {
        $row = $this->model()->find((int) $id);
        if (! $row) return $this->failNotFound("Integration #{$id} not found.");
        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    // POST /api/v1/meta-integrations/:id/test
    // Simulates a test Meta lead arriving — creates a test contact and fires flows.
    public function test($id = null): ResponseInterface
    {
        $row = $this->model()->find((int) $id);
        if (! $row) return $this->failNotFound("Integration #{$id} not found.");

        $tenantId  = CurrentUser::tenantId();
        $pageId    = $row['page_id'] ?? '';
        $ts        = time();
        $leadgenId = 'test_lead_' . $ts;

        // Build a mock leadgen webhook payload
        $mockPayload = [
            'object' => 'page',
            'entry'  => [[
                'id'      => $pageId,
                'time'    => $ts,
                'changes' => [[
                    'field' => 'leadgen',
                    'value' => [
                        'leadgen_id' => $leadgenId,
                        'page_id'    => $pageId,
                        'form_id'    => 'test_form_001',
                        'adgroup_id' => 'test_adgroup',
                        'ad_id'      => 'test_ad',
                        'created_time' => $ts,
                    ],
                ]],
            ]],
        ];

        // Use hard-coded mock data for test (bypasses Graph API entirely)
        try {
            $defaultCountryCode = '';
            $config = json_decode($row['config'] ?? '{}', true) ?? [];
            if (! empty($config['default_country_code'])) {
                $defaultCountryCode = $config['default_country_code'];
            }

            // Build mock field_data as Meta would return from Graph API
            $mockFieldData = [
                ['name' => 'full_name',    'values' => ['Test Lead from Meta ' . date('H:i')]],
                ['name' => 'phone_number', 'values' => ['+919000' . rand(100000, 999999)]],
                ['name' => 'email',        'values' => ['testlead_' . time() . '@metademo.test']],
            ];
            $mapped = MetaLeadMapper::map($mockFieldData, $defaultCountryCode);

            if (empty($mapped['wa_number'])) {
                $mapped['wa_number'] = '+919999900000'; // fallback for test
            }

            $mapped['source'] = 'meta_lead_ads';

            $dedupeService = new \App\Services\Leads\ContactDedupeService(
                new \App\Models\ContactModel(),
                new \App\Models\ContactFieldValueModel()
            );
            $result = $dedupeService->upsert($tenantId, $mapped);

            // Fire flow trigger
            if ($result['contact_id'] > 0) {
                \App\Services\Flow\FlowTriggerService::fire(
                    'meta_lead_received',
                    $tenantId,
                    $result['contact_id'],
                    ['leadgen_id' => $leadgenId, 'page_id' => $pageId]
                );
            }

            return $this->respond([
                'success'    => true,
                'message'    => 'Test lead created successfully! Check Contacts for the new lead.',
                'contact_id' => $result['contact_id'],
                'action'     => $result['action'] ?? 'created',
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'MetaIntegrationsController::test error: ' . $e->getMessage());
            return $this->fail('Test lead failed: ' . $e->getMessage(), 500);
        }
    }

    // POST /api/v1/meta-integrations/:id/subscribe
    // Subscribes the Page to the leadgen webhook field via Graph API.
    public function subscribe($id = null): ResponseInterface
    {
        $row = $this->model()->find((int) $id);
        if (! $row) return $this->failNotFound("Integration #{$id} not found.");

        try {
            (new MetaLeadAdsLinker())->subscribe(CurrentUser::tenantId(), (int) $id);
        } catch (\Throwable $e) {
            return $this->fail("Page subscription failed: {$e->getMessage()}", 422);
        }

        $mock = filter_var(env('META_LEADS_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);

        return $this->respond([
            'success' => true,
            'message' => $mock
                ? '[MOCK] Page subscription simulated. META_LEADS_MOCK_MODE is on.'
                : 'Page subscribed to leadgen webhook.',
        ]);
    }
}
