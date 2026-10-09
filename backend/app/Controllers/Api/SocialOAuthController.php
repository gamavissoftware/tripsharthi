<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Social\FacebookOAuthService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * "Connect with Facebook" — the authenticated half of the OAuth flow.
 *
 * The unauthenticated half (Meta's redirect back) is
 * Public\SocialOauthCallbackController; it has no session, which is exactly why
 * the state row exists.
 *
 * No access token is accepted from, or returned to, the browser anywhere in
 * this controller.
 */
class SocialOAuthController extends ResourceController
{
    protected $format = 'json';

    /**
     * Whether this server can run the flow at all, plus the redirect URI the
     * Meta app must whitelist — shown in the UI so setting it up does not mean
     * guessing the exact string.
     */
    public function status(): ResponseInterface
    {
        $configId = FacebookOAuthService::loginConfigId();

        return $this->respond(['success' => true, 'data' => [
            'configured'   => FacebookOAuthService::isConfigured(),
            'redirect_uri' => FacebookOAuthService::redirectUri(),
            'scopes'       => FacebookOAuthService::SCOPES,
            // Which Meta login product this install is driving. 'business'
            // means permissions come from the dashboard configuration named
            // here; 'classic' means they are requested as scopes per call.
            'login_mode'   => $configId !== '' ? 'business' : 'classic',
            'config_id'    => $configId,
        ]]);
    }

    /**
     * Mint a state and hand back the consent dialog URL for the browser to go to.
     */
    public function start(): ResponseInterface
    {
        $returnTo = trim((string) ($this->request->getJsonVar('return_to') ?? 'social'));

        try {
            $url = (new FacebookOAuthService())->start(CurrentUser::tenantId(), CurrentUser::id(), $returnTo);
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 422);
        }

        return $this->respond(['success' => true, 'data' => ['auth_url' => $url]]);
    }

    /**
     * The pages the returning user can choose from. Display fields only.
     */
    public function pages(): ResponseInterface
    {
        $state = trim((string) ($this->request->getGet('state') ?? ''));
        if ($state === '') {
            return $this->fail(['state' => 'Missing state.'], 422);
        }

        try {
            $pages = (new FacebookOAuthService())->pages($state, CurrentUser::tenantId());
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 422);
        }

        return $this->respond(['success' => true, 'data' => $pages]);
    }

    /**
     * Connect the chosen page.
     */
    public function select(): ResponseInterface
    {
        $rules = ['state' => 'required|max_length[64]', 'page_id' => 'required|max_length[50]'];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        try {
            $account = (new FacebookOAuthService())->selectPage(
                trim((string) $this->request->getJsonVar('state')),
                trim((string) $this->request->getJsonVar('page_id')),
                CurrentUser::tenantId(),
                CurrentUser::id()
            );
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 422);
        }

        return $this->respondCreated(['success' => true, 'data' => $account]);
    }
}
