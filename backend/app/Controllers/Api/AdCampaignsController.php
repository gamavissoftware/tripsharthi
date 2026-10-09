<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\AdRuleModel;
use App\Services\Ads\AdCampaignManager;
use App\Services\Ads\AdCopyAiService;
use App\Services\Ads\AdGuardrails;
use App\Services\Ads\AdReportService;
use App\Services\Ads\AdsApiException;
use App\Services\Ads\AdsGuardrailException;
use App\Services\Ads\AdsMockHttp;
use App\Services\Ads\GoogleAdapter;
use App\Services\Ads\MetaAdapter;
use App\Services\AI\AiUsageService;
use App\Services\Auth\CurrentUser;
use App\Services\Billing\PlanLimitChecker;

/**
 * Campaign management (owner/admin only — it spends money and shows spend).
 * Platform problems are returned as 422 with {message, kind, violations?}. NEVER 401: the SPA treats a 401 as
 * "your login expired" and logs the user out, which a revoked Meta token must not do.
 */
class AdCampaignsController extends TravelBaseController
{
    private function mgr(): AdCampaignManager { return new AdCampaignManager(); }

    /** Run an action, converting domain failures into readable 422s. */
    private function run(callable $fn, int $okCode = 200)
    {
        try {
            return $this->ok($fn(), $okCode);
        } catch (AdsGuardrailException $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'guardrail', 'violations' => $e->violations], 422);
        } catch (AdsApiException $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => $e->kind], 422);
        } catch (\InvalidArgumentException | \DomainException $e) {
            return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422);
        }
    }

    // ---- overview ---------------------------------------------------------------------------------------

    public function index()
    {
        $tid = CurrentUser::tenantId();
        $days = (int) ($this->request->getGet('days') ?: 30);
        $rows = (new AdReportService())->campaigns($tid, $days);
        $tot = ['spend' => 0, 'crm_leads' => 0, 'bookings' => 0, 'revenue' => 0, 'active_daily_budget' => 0];
        foreach ($rows as $r) {
            foreach (['spend', 'crm_leads', 'bookings', 'revenue'] as $k) { $tot[$k] += $r[$k]; }
            if ($r['status'] === 'ACTIVE') { $tot['active_daily_budget'] += $r['daily_budget']; }
        }
        $tot['roas'] = $tot['spend'] > 0 ? round($tot['revenue'] / $tot['spend'], 2) : null;
        return $this->ok(['campaigns' => $rows, 'totals' => $tot, 'settings' => $this->mgr()->settings($tid), 'days' => $days, 'issues' => (new \App\Services\Ads\AdIssueService())->open($tid)]);
    }

    /** Which platforms can be managed right now, and what a new campaign can pick from. */
    public function connection()
    {
        $tid = CurrentUser::tenantId();
        $mock = AdsMockHttp::enabled();
        $out = ['mock' => $mock, 'meta' => ['connected' => false], 'google' => ['connected' => false]];

        try {
            if ($meta = MetaAdapter::forTenant($tid)) {
                $perms = $meta->client()->permissions();
                $cfgRow = (new \App\Models\IntegrationModel())->findActiveByType($tid, 'meta_ads');
                $cfg = $cfgRow ? (json_decode((string) ((array) $cfgRow)['config'], true) ?: []) : [];
                $accounts = (array) ($cfg['ad_accounts'] ?? []) ?: ($mock ? [['id' => 'act_1000000001', 'name' => 'Demo Travels Ads', 'currency' => 'INR']] : []);
                $out['meta'] = ['connected' => true, 'can_manage' => in_array('ads_management', $perms, true), 'ad_accounts' => $accounts,
                    'pages' => array_map(static fn ($p) => ['id' => $p['id'], 'name' => $p['name']], $meta->client()->pages())];
            }
        } catch (AdsApiException $e) {
            $out['meta'] = ['connected' => false, 'error' => $e->getMessage(), 'kind' => $e->kind];
        }
        $g = GoogleAdapter::forTenant($tid);
        $out['google'] = $g ? ['connected' => true, 'customer_id' => $g->client()->customerId()] : ['connected' => false];
        return $this->ok($out);
    }

    // ---- lookups ------------------------------------------------------------------------------------------

    public function leadForms()
    {
        return $this->run(function () {
            $m = MetaAdapter::forTenant(CurrentUser::tenantId()) ?? throw new AdsApiException('Meta is not connected.', AdsApiException::AUTH);
            return $m->client()->leadForms((string) preg_replace('/\D/', '', (string) $this->request->getGet('page_id')));
        });
    }

    public function metaGeo()
    {
        return $this->run(function () {
            $m = MetaAdapter::forTenant(CurrentUser::tenantId()) ?? throw new AdsApiException('Meta is not connected.', AdsApiException::AUTH);
            return array_map(static fn ($g) => ['key' => $g['key'], 'name' => $g['name'], 'region' => $g['region'] ?? ''], $m->client()->searchGeo((string) $this->request->getGet('q')));
        });
    }

    public function googleGeo()
    {
        return $this->run(function () {
            $g = GoogleAdapter::forTenant(CurrentUser::tenantId()) ?? throw new AdsApiException('Google is not connected.', AdsApiException::AUTH);
            return $g->client()->suggestGeo((string) $this->request->getGet('q'));
        });
    }

    // ---- images ------------------------------------------------------------------------------------------

    public function assets() { return $this->ok((new \App\Services\Ads\AdAssetService())->list(CurrentUser::tenantId())); }

    public function uploadAsset()
    {
        return $this->run(function () {
            $f = $this->request->getFile('file');
            if (! $f || ! $f->isValid()) { throw new \InvalidArgumentException('Choose an image to upload.'); }
            return (new \App\Services\Ads\AdAssetService())->store(CurrentUser::tenantId(), ['tmp_name' => $f->getTempName(), 'name' => $f->getClientName(), 'size' => $f->getSize()], CurrentUser::id());
        }, 201);
    }

    /** Staff-only preview of a stored image (the file is not public). */
    public function assetFile($id = null)
    {
        try { $r = (new \App\Services\Ads\AdAssetService())->read(CurrentUser::tenantId(), (int) $id); }
        catch (\InvalidArgumentException $e) { return $this->failNotFound($e->getMessage()); }
        catch (\RuntimeException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'error'], 500); }
        return $this->response->setHeader('Content-Type', $r['mime'])->setHeader('X-Content-Type-Options', 'nosniff')->setHeader('Cache-Control', 'private, max-age=300')->setBody($r['bytes']);
    }

    public function deleteAsset($id = null) { return $this->run(function () use ($id) { (new \App\Services\Ads\AdAssetService())->delete(CurrentUser::tenantId(), (int) $id); return ['deleted' => true]; }); }

    // ---- audiences -----------------------------------------------------------------------------------------

    private function aud(): \App\Services\Ads\AdAudienceService { return new \App\Services\Ads\AdAudienceService(); }

    public function audiences()
    {
        $tid = CurrentUser::tenantId();
        return $this->ok(['audiences' => $this->aud()->list($tid), 'sources' => \App\Services\Ads\AdAudienceService::SOURCES,
            'segments' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], db_connect()->table('segments')->select('id, name')->where('tenant_id', $tid)->where('deleted_at', null)->orderBy('name')->get()->getResultArray()),
            'min_seed' => \App\Services\Ads\AdAudienceService::MIN_SEED]);
    }

    public function createAudience() { return $this->run(fn () => $this->aud()->create(CurrentUser::tenantId(), CurrentUser::id(), $this->body()), 201); }
    public function createLookalike() { return $this->run(fn () => $this->aud()->createLookalike(CurrentUser::tenantId(), CurrentUser::id(), $this->body()), 201); }
    public function refreshAudience($id = null) { return $this->run(fn () => $this->aud()->refresh(CurrentUser::tenantId(), (int) $id, CurrentUser::id())); }
    public function audienceEstimate($id = null) { return $this->run(fn () => ['estimate' => $this->aud()->estimate(CurrentUser::tenantId(), (int) $id)]); }
    public function deleteAudience($id = null) { return $this->run(function () use ($id) { $this->aud()->delete(CurrentUser::tenantId(), (int) $id, CurrentUser::id()); return ['deleted' => true]; }); }

    // ---- create / lifecycle -----------------------------------------------------------------------------

    private function spec(): array
    {
        $b = $this->body();
        if (isset($b['daily_budget_rs'])) { $b['daily_budget'] = AdGuardrails::toPaise($b['daily_budget_rs']); }
        unset($b['daily_budget_rs']);
        return $b;
    }

    public function preview() { return $this->run(fn () => $this->mgr()->preview(CurrentUser::tenantId(), $this->spec())); }

    public function create() { return $this->run(fn () => $this->mgr()->create(CurrentUser::tenantId(), CurrentUser::id(), $this->spec()), 201); }

    public function launch($id = null) { return $this->run(fn () => $this->mgr()->launch(CurrentUser::tenantId(), (int) $id, CurrentUser::id())); }

    public function pause($id = null) { return $this->run(fn () => $this->mgr()->pause(CurrentUser::tenantId(), (int) $id, CurrentUser::id())); }

    public function budget($id = null)
    {
        $paise = AdGuardrails::toPaise($this->body()['daily_budget_rs'] ?? 0);
        return $this->run(fn () => $this->mgr()->setBudget(CurrentUser::tenantId(), (int) $id, $paise, CurrentUser::id()));
    }

    public function sync() { return $this->run(fn () => $this->mgr()->syncAll(CurrentUser::tenantId())); }

    /** GET /ad-campaigns/:id/history — the audit trail of this campaign. */
    public function history($id = null)
    {
        $rows = db_connect()->table('audit_logs')->where('tenant_id', CurrentUser::tenantId())->where('entity_type', 'ad_campaign')->where('entity_id', (int) $id)
            ->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();
        return $this->ok(array_map(static fn ($r) => ['at' => $r['created_at'], 'action' => $r['action'], 'by' => $r['actor_user_id'], 'before' => json_decode((string) $r['before'], true), 'after' => json_decode((string) $r['after'], true)], $rows));
    }

    // ---- settings / rules / AI ----------------------------------------------------------------------------

    public function settings() { return $this->ok($this->mgr()->settings(CurrentUser::tenantId())); }

    public function saveSettings()
    {
        $b = $this->body();
        $in = [];
        if (isset($b['daily_spend_cap_rs']))  { $in['daily_spend_cap'] = AdGuardrails::toPaise($b['daily_spend_cap_rs']); }
        if (isset($b['min_daily_budget_rs'])) { $in['min_daily_budget'] = AdGuardrails::toPaise($b['min_daily_budget_rs']); }
        if (isset($b['max_increase_pct']))    { $in['max_increase_pct'] = (int) $b['max_increase_pct']; }
        $in['rules_enabled'] = ! empty($b['rules_enabled']);
        return $this->run(fn () => $this->mgr()->saveSettings(CurrentUser::tenantId(), $in, CurrentUser::id()));
    }

    public function rules() { return $this->ok((new AdRuleModel())->setTenant(CurrentUser::tenantId())->orderBy('id', 'DESC')->findAll(100)); }

    public function saveRule($id = null)
    {
        $b = $this->body();
        $data = [
            'name' => trim((string) ($b['name'] ?? '')), 'platform' => in_array($b['platform'] ?? 'any', ['any', 'meta', 'google'], true) ? ($b['platform'] ?? 'any') : 'any',
            'metric' => $b['metric'] ?? '', 'threshold' => AdGuardrails::toPaise($b['threshold_rs'] ?? 0), 'window_days' => max(1, min(30, (int) ($b['window_days'] ?? 3))),
            'min_spend' => AdGuardrails::toPaise($b['min_spend_rs'] ?? 0), 'action' => ($b['action'] ?? 'notify') === 'pause' ? 'pause' : 'notify', 'enabled' => ! empty($b['enabled'] ?? true) ? 1 : 0,
        ];
        if ($data['name'] === '' || ! in_array($data['metric'], ['cpl', 'spend_no_leads', 'cost_per_booking'], true) || $data['threshold'] <= 0) {
            return $this->respond(['success' => false, 'message' => 'Give the rule a name, a metric and a threshold above zero.', 'kind' => 'invalid'], 422);
        }
        $m = new AdRuleModel();
        $tid = CurrentUser::tenantId();
        if ($id) { if (! $m->setTenant($tid)->find((int) $id)) { return $this->failNotFound('Rule not found.'); } (new AdRuleModel())->setTenant($tid)->update((int) $id, $data); }
        else { $id = (new AdRuleModel())->setTenant($tid)->insert($data, true); }
        return $this->ok((new AdRuleModel())->setTenant($tid)->find((int) $id), $id ? 200 : 201);
    }

    public function deleteRule($id = null)
    {
        $m = (new AdRuleModel())->setTenant(CurrentUser::tenantId());
        if (! $m->find((int) $id)) { return $this->failNotFound('Rule not found.'); }
        $m->delete((int) $id);
        return $this->ok(['deleted' => true]);
    }

    /** POST /ad-campaigns/ai-copy { platform, destination, trip_type?, price_from?, duration?, usp?, season? } (metered) */
    public function aiCopy()
    {
        $tid = CurrentUser::tenantId();
        $b = $this->body();
        $usage = new AiUsageService();
        if (! $usage->canUse($tid)) {
            $snap = $usage->snapshot($tid);
            return $this->fail((new PlanLimitChecker())->limitExceededResponse('ai_replies', $snap['used'], $snap['limit']), 422);
        }
        return $this->run(function () use ($tid, $b, $usage) {
            $r = (new AdCopyAiService($tid))->suggest(($b['platform'] ?? '') === 'google' ? 'google' : 'meta', $b);
            if (($r['_source'] ?? '') === 'ai') { $usage->record($tid); }
            return $r;
        });
    }
}
