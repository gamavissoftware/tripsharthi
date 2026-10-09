<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Travel\AdCredentialsService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Google redirects the browser here after consent. Public (no Authorization header on a redirect);
 * trusts only a `state` it minted itself (single use, 15 min, carries the tenant). Always ends in a
 * redirect back to the SPA — success or not — so the user never lands on a raw JSON page.
 */
class GoogleAdsOauthCallbackController extends Controller
{
    public function index(): ResponseInterface
    {
        $code  = trim((string) ($this->request->getGet('code') ?? ''));
        $state = trim((string) ($this->request->getGet('state') ?? ''));

        if (($err = trim((string) ($this->request->getGet('error') ?? ''))) !== '') {
            return $this->back(['google_error' => $err === 'access_denied' ? 'You cancelled the Google connection.' : $err]);
        }
        if ($code === '' || $state === '') {
            return $this->back(['google_error' => 'Google sent an incomplete response. Please try again.']);
        }
        try {
            (new AdCredentialsService())->googleCallback($code, $state);
        } catch (\Throwable $e) {
            log_message('error', '[google ads oauth] callback failed: ' . $e->getMessage());
            return $this->back(['google_error' => $e->getMessage()]);
        }
        return $this->back(['google_connected' => '1']);
    }

    private function back(array $q): ResponseInterface
    {
        return $this->response->redirect(AdCredentialsService::spaReturnUrl(http_build_query($q)), 'auto', 302);
    }
}
