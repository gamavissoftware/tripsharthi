<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\IntegrationModel;
use App\Services\Leads\MetaLeadsArchiveService;
use App\Services\Social\GraphClient;
use App\Services\WhatsApp\TokenCipher;

/**
 * Connects the Facebook Page that runs TripSarthi's lead ads to the marketing workspace, but only after Meta has confirmed everything.
 * The old "paste an ID and a token" form stored whatever it was given (an app id once ended up as the "Page"); this checks each step:
 *   1. the token is asked who it is - a PAGE token must belong to the Page entered; a USER token must list that Page (me/accounts);
 *   2. a user token is exchanged for a long-lived one (needs META_APP_ID + META_APP_SECRET on the server) so the Page token derived from it
 *      does not expire - a Page token taken from a short-lived login dies in hours and real-time delivery would silently stop;
 *   3. the Page's lead forms must be readable (leads_retrieval works);
 *   4. only then is the connection saved (keeping the webhook verify token already registered in Meta), the Page subscribed to `leadgen`
 *      and the Page's real name stored.
 * The token is never returned or logged.
 */
final class PlatformFacebookConnector
{
    public function __construct(private ?GraphClient $graph = null)
    {
        $this->graph ??= new GraphClient();
    }

    private function tenant(): int
    {
        $t = (new PlatformAudienceService())->marketingTenant();
        if ($t === null) { throw new \DomainException('Choose the marketing workspace first (WhatsApp -> Our marketing).'); }
        return $t;
    }

    /** @return array{page_id:string,page_name:string,forms:int,leads_in_forms:int,long_lived:bool,subscribed:bool,warning:?string} */
    public function connect(string $pageId, string $token, int $adminId): array
    {
        $tenant = $this->tenant();
        $pageId = trim($pageId); $token = trim($token);
        if (! preg_match('/^\d{5,25}$/', $pageId)) { throw new \InvalidArgumentException('The Page ID should be a number of 5 to 25 digits.'); }
        if (strlen($token) < 20) { throw new \InvalidArgumentException('Paste the full access token from Meta.'); }

        $warning = null; $longLived = false;
        try { $me = $this->graph->get('me', ['fields' => 'id,name', 'access_token' => $token]); }
        catch (\RuntimeException $e) { throw new \InvalidArgumentException('Meta did not accept that token: ' . $this->clean($e->getMessage(), $token)); }

        if ((string) ($me['id'] ?? '') === $pageId) {
            $pageToken = $token;                                   // already a Page token
            $name = (string) ($me['name'] ?? '');
            $warning = 'This Page token may expire within hours. Paste a user token instead and the CRM will make it last.';
        } else {
            $userToken = $token;
            $appId = trim((string) env('META_APP_ID', '')); $secret = trim((string) env('META_APP_SECRET', ''));
            if ($appId !== '' && $secret !== '') {
                try {
                    $x = $this->graph->get('oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $appId, 'client_secret' => $secret, 'fb_exchange_token' => $token]);
                    if (! empty($x['access_token'])) { $userToken = (string) $x['access_token']; $longLived = true; }
                } catch (\RuntimeException $e) { $warning = 'Could not make the token long-lived: ' . $this->clean($e->getMessage(), $token); }
            } else {
                $warning = 'META_APP_ID is not set on the server, so the token could not be made long-lived. It may expire within hours.';
            }
            $pageToken = ''; $name = '';
            $url = ['fields' => 'id,name,access_token', 'limit' => 100, 'access_token' => $userToken];
            for ($i = 0; $i < 10 && $pageToken === ''; $i++) {
                try { $r = $this->graph->get('me/accounts', $url); }
                catch (\RuntimeException $e) { throw new \InvalidArgumentException('Meta would not list your Pages: ' . $this->clean($e->getMessage(), $token)); }
                foreach ((array) ($r['data'] ?? []) as $p) {
                    if ((string) ($p['id'] ?? '') === $pageId) { $pageToken = (string) ($p['access_token'] ?? ''); $name = (string) ($p['name'] ?? ''); break; }
                }
                $after = $r['paging']['cursors']['after'] ?? null;
                if ($pageToken !== '' || ! $after || empty($r['paging']['next'])) { break; }
                $url['after'] = $after;
            }
            if ($pageToken === '') {
                throw new \InvalidArgumentException("That token cannot manage Page {$pageId}. In Meta's approval popup, tick the TripSarthi Page (and its business portfolio), then generate the token again.");
            }
        }

        // Prove lead access with the Page token before saving anything.
        try { $f = $this->graph->get("{$pageId}/leadgen_forms", ['fields' => 'id,name,status,leads_count', 'limit' => 100, 'access_token' => $pageToken]); }
        catch (\RuntimeException $e) { throw new \InvalidArgumentException('Meta would not show this Page\'s lead forms (is leads_retrieval approved?): ' . $this->clean($e->getMessage(), $token)); }
        $forms = (array) ($f['data'] ?? []);
        $leads = array_sum(array_map(static fn ($x) => (int) ($x['leads_count'] ?? 0), $forms));

        $im = new IntegrationModel();
        $existing = db_connect()->table('integrations')->where('tenant_id', $tenant)->where('type', 'meta_lead_ads')->get()->getRowArray();
        $verify = $existing && ! empty($existing['verify_token']) ? (string) $existing['verify_token'] : bin2hex(random_bytes(20));   // keep the one registered in Meta
        $country = '';
        if ($existing) { $c = json_decode((string) ($existing['config'] ?? '{}'), true) ?: []; $country = (string) ($c['default_country_code'] ?? ''); }
        $id = $im->saveConfig($tenant, 'meta_lead_ads', [
            'page_id' => $pageId, 'verify_token' => $verify,
            'config' => json_encode(['page_access_token_enc' => TokenCipher::encrypt($pageToken), 'default_country_code' => $country, 'page_name' => $name, 'long_lived' => $longLived, 'connect_method' => 'admin_token']),
        ]);

        $subscribed = true;
        try { (new \App\Services\Leads\MetaLeadAdsLinker($this->graph))->subscribe($tenant, (int) $id); }
        catch (\Throwable $e) { $subscribed = false; $warning = trim(($warning ? $warning . ' ' : '') . 'Saved, but Meta refused the lead subscription: ' . $this->clean($e->getMessage(), $token)); }

        try {
            db_connect()->table('audit_logs')->insert(['tenant_id' => $tenant, 'actor_user_id' => null, 'impersonated_by' => $adminId, 'action' => 'admin.crm.facebook.connect', 'entity_type' => 'integration', 'entity_id' => (int) $id,
                'after' => json_encode(['page_id' => $pageId, 'page_name' => $name, 'long_lived' => $longLived, 'subscribed' => $subscribed]), 'created_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) { log_message('warning', '[crm] audit failed: ' . $e->getMessage()); }

        return ['page_id' => $pageId, 'page_name' => $name, 'forms' => count($forms), 'leads_in_forms' => $leads, 'long_lived' => $longLived, 'subscribed' => $subscribed, 'warning' => $warning];
    }

    /**
     * Brings the leads Meta still holds (it keeps them 90 days) into the CRM, month by month, without starting any automation, then puts
     * each into the Subscriber Funnel.
     * @return array{months:array<string,array>,inserted:int,updated:int,skipped:int,deals:int}
     */
    public function importPast(int $months = 4): array
    {
        $tenant = $this->tenant();
        $months = max(1, min(4, $months));
        $svc = new MetaLeadsArchiveService($this->graph);
        $out = ['months' => [], 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'deals' => 0];
        for ($i = 0; $i < $months; $i++) {
            $m = date('Y-m', strtotime(date('Y-m-01') . " -{$i} month"));
            try { $r = $svc->import($tenant, $m, null, false, 'Facebook lead · ' . $m); }
            catch (\RuntimeException $e) { $out['months'][$m] = ['error' => $e->getMessage()]; continue; }
            $out['months'][$m] = ['inserted' => $r['inserted'], 'updated' => $r['updated'], 'skipped' => $r['skipped']];
            foreach (['inserted', 'updated', 'skipped'] as $k) { $out[$k] += $r[$k]; }
        }
        $out['deals'] = (new PlatformCrmService())->adoptMissing();
        return $out;
    }

    /**
     * The newest lead events Meta delivered to the marketing workspace, with why one failed (so a rejected lead is visible without server access).
     * @return list<array<string,mixed>>
     */
    public function recentEvents(int $limit = 15): array
    {
        $rows = \Config\Database::connect()->table('meta_lead_events')
            ->select('id, leadgen_id, status, contact_id, error, created_at')
            ->where('tenant_id', $this->tenant())
            ->orderBy('id', 'DESC')->limit(max(1, min(50, $limit)))->get()->getResultArray();
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'leadgen_id' => (string) $r['leadgen_id'], 'status' => (string) $r['status'],
            'contact_id' => $r['contact_id'] !== null ? (int) $r['contact_id'] : null,
            'error' => $r['error'], 'created_at' => $r['created_at'],
        ], $rows);
    }

    /** Never let a token reach a message. */
    private function clean(string $msg, string $token): string
    {
        return mb_substr(str_replace($token, '***', $msg), 0, 300);
    }
}
