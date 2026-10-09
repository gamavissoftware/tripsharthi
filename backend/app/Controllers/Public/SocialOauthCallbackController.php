<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Social\FacebookOAuthService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Where Meta sends the browser back after the user approves (or cancels).
 *
 * Public by necessity — a redirect carries no Authorization header — but not
 * trusting: the only thing it accepts is a `state` it minted itself, which is
 * single-use, expires in fifteen minutes, and carries the tenant and user. A
 * forged or replayed state gets the same generic bounce as a cancelled one.
 *
 * It always ends in a redirect back to the SPA rather than rendering anything,
 * because the user's mental model is "I clicked Connect and came back to the
 * Social page" — an API-looking JSON body here would be a dead end in a tab.
 */
class SocialOauthCallbackController extends Controller
{
    private string $returnTo = 'social';

    public function index(): ResponseInterface
    {
        $code  = trim((string) ($this->request->getGet('code') ?? ''));
        $state = trim((string) ($this->request->getGet('state') ?? ''));

        // The Integrations page starts this flow too; land back where it began.
        $this->returnTo = $state !== '' ? FacebookOAuthService::returnToFor($state) : 'social';

        // The user pressed Cancel on Meta's dialog, or Meta refused outright.
        $error = trim((string) ($this->request->getGet('error') ?? ''));
        if ($error !== '') {
            $reason = trim((string) ($this->request->getGet('error_description') ?? $error));

            return $this->back(['social_oauth_error' => $reason]);
        }

        if ($code === '' || $state === '') {
            return $this->back(['social_oauth_error' => 'Facebook sent an incomplete response. Please try again.']);
        }

        try {
            (new FacebookOAuthService())->handleCallback($code, $state);
        } catch (\Throwable $e) {
            log_message('error', '[social oauth] callback failed: ' . $e->getMessage());

            return $this->back(['social_oauth_error' => $e->getMessage()]);
        }

        // Success: the SPA picks the state up and shows the page picker.
        return $this->back(['social_oauth_state' => $state]);
    }

    /**
     * @param array<string,string> $query
     */
    private function back(array $query): ResponseInterface
    {
        // The Integrations page tells an ads connection from a Page connection
        // by the query key, so the two never open each other's picker.
        if (in_array($this->returnTo, ['ads', 'ads_manage'], true)) {
            $renamed = [];
            foreach ($query as $k => $v) {
                $renamed[str_replace('social_oauth_', 'meta_ads_', (string) $k)] = $v;
            }
            $query = $renamed;
        }

        return $this->response->redirect(
            FacebookOAuthService::spaReturnUrl(http_build_query($query), $this->returnTo),
            'auto',
            302
        );
    }
}
