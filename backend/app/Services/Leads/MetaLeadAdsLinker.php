<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\IntegrationModel;
use App\Models\SocialAccountModel;
use App\Services\Social\GraphClient;
use RuntimeException;

/**
 * Wire Meta Lead Ads to a Facebook Page that was connected with Facebook
 * Login, instead of asking the user to paste a Page Access Token.
 *
 * The Social Planner already stores an encrypted, non-expiring page token in
 * `social_accounts` (FacebookOAuthService). Lead Ads needs exactly that token
 * — same Page, same app — so this copies the *ciphertext* across into the
 * `meta_lead_ads` integration row. The token is never decrypted on the way,
 * and never travels through a browser form.
 *
 * Two steps, kept separate because the second can legitimately fail while the
 * first succeeded (e.g. the token predates `pages_manage_metadata` being added
 * to the Login configuration, and the Page has to be reconnected):
 *
 *   linkSocialAccount()  — integrations.page_id + token + verify_token
 *   subscribe()          — POST /{page}/subscribed_apps?subscribed_fields=leadgen
 *
 * The verify_token is kept across re-links: it is what the Meta dashboard's
 * webhook registration was saved with, and regenerating it on every reconnect
 * would silently break that registration.
 */
class MetaLeadAdsLinker
{
    public const TYPE = 'meta_lead_ads';

    public function __construct(private ?GraphClient $graph = null)
    {
        $this->graph ??= new GraphClient();
    }

    /**
     * Point the tenant's Lead Ads integration at a connected Page.
     *
     * @return array{id:int,page_id:string,page_name:string,verify_token:string}
     */
    public function linkSocialAccount(int $tenantId, int $socialAccountId, string $countryCode = ''): array
    {
        $account = (new SocialAccountModel())->setTenant($tenantId)->find($socialAccountId);
        if (! $account) {
            throw new RuntimeException('That connected Page was not found.');
        }
        $account = (array) $account;

        if (empty($account['access_token_enc'])) {
            throw new RuntimeException('That Page has no stored access token — connect it with Facebook first.');
        }
        if (($account['status'] ?? 'active') !== 'active') {
            throw new RuntimeException('That Page needs reconnecting with Facebook before it can be used.');
        }

        $pageId   = (string) $account['page_id'];
        $pageName = (string) ($account['page_name'] ?? $pageId);

        // Raw read on purpose: the previous row may be soft-deleted (disconnect),
        // and its verify_token / country code should survive a re-link.
        $existing = db_connect()->table('integrations')
            ->where('tenant_id', $tenantId)->where('type', self::TYPE)
            ->get()->getRowArray();

        $config = $existing ? (json_decode((string) ($existing['config'] ?? '{}'), true) ?: []) : [];
        $config['page_access_token_enc'] = (string) $account['access_token_enc'];
        $config['page_name']             = $pageName;
        $config['social_account_id']     = (int) $account['id'];
        $config['connect_method']        = 'facebook_login';
        if ($countryCode !== '') {
            $config['default_country_code'] = $countryCode;
        }
        $config['default_country_code'] ??= '';
        // A (possibly different) Page is being linked: its subscription state is
        // unknown until subscribe() confirms it.
        unset($config['subscribed'], $config['subscribed_at']);

        $verifyToken = (string) ($existing['verify_token'] ?? '');
        if ($verifyToken === '') {
            $verifyToken = bin2hex(random_bytes(20));
        }

        $id = (new IntegrationModel())->saveConfig($tenantId, self::TYPE, [
            'page_id'      => $pageId,
            'verify_token' => $verifyToken,
            'config'       => json_encode($config),
        ]);

        log_message('info', "MetaLeadAdsLinker: tenant {$tenantId} linked Page {$pageId} ({$pageName}) to Lead Ads.");

        return [
            'id'           => $id,
            'page_id'      => $pageId,
            'page_name'    => $pageName,
            'verify_token' => $verifyToken,
        ];
    }

    /**
     * Subscribe the linked Page to the `leadgen` webhook field.
     *
     * Throws with Meta's own reason on refusal, because the usual cause — a
     * token that was minted before `pages_manage_metadata` was granted — is
     * something only the user can fix (reconnect the Page).
     */
    public function subscribe(int $tenantId, int $integrationId): void
    {
        $model = (new IntegrationModel())->setTenant($tenantId);
        $row   = $model->find($integrationId);
        if (! $row) {
            throw new RuntimeException("Integration #{$integrationId} not found.");
        }
        $row = (array) $row;

        $token  = $model->decryptPageToken($row);
        $pageId = (string) ($row['page_id'] ?? '');
        if ($token === '' || $pageId === '') {
            throw new RuntimeException('Missing page_id or page access token.');
        }

        if (filter_var(env('META_LEADS_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN)) {
            log_message('alert', 'MetaLeadAdsLinker: META_LEADS_MOCK_MODE — subscription skipped.');
            $this->markSubscribed($model, $integrationId, $row);

            return;
        }

        $resp = $this->graph->post("{$pageId}/subscribed_apps", [
            'subscribed_fields' => 'leadgen',
            'access_token'      => $token,
        ]);

        if (! ($resp['success'] ?? false)) {
            throw new RuntimeException('Meta did not confirm the leadgen subscription.');
        }

        $this->markSubscribed($model, $integrationId, $row);
        log_message('info', "MetaLeadAdsLinker: Page {$pageId} subscribed to leadgen for tenant {$tenantId}.");
    }

    /**
     * @param array<string,mixed> $row
     */
    private function markSubscribed(IntegrationModel $model, int $integrationId, array $row): void
    {
        $config                  = json_decode((string) ($row['config'] ?? '{}'), true) ?: [];
        $config['subscribed']    = true;
        $config['subscribed_at'] = date('Y-m-d H:i:s');
        $model->update($integrationId, ['config' => json_encode($config)]);
    }
}
