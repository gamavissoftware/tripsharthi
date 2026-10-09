<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Models\AdCampaignModel;
use App\Models\AdInsightDailyModel;
use App\Models\IntegrationModel;
use App\Services\WhatsApp\TokenCipher;

final class MetaAdapter implements AdPlatformAdapter
{
    private const LEAD_ACTIONS = ['lead', 'onsite_conversion.lead_grouped', 'leadgen_grouped'];
    private const CONVERSATION_ACTION = 'onsite_conversion.messaging_conversation_started_7d';

    public function __construct(private readonly int $tenantId, private readonly MetaMarketingClient $client) {}

    /** Build from the tenant's stored Meta Ads connection; null when not connected. */
    public static function forTenant(int $tenantId, ?callable $http = null): ?self
    {
        $row = (new IntegrationModel())->findActiveByType($tenantId, 'meta_ads');
        $cfg = $row ? (json_decode((string) ((array) $row)['config'], true) ?: []) : [];
        $tok = ! empty($cfg['user_token_enc']) ? TokenCipher::decrypt($cfg['user_token_enc']) : '';
        if ($tok === '' && AdsMockHttp::enabled()) { $tok = 'mock-token'; $http ??= AdsMockHttp::handler($tenantId); }   // local demo: no real connection needed
        return $tok === '' ? null : new self($tenantId, new MetaMarketingClient($tok, $http));
    }

    public function client(): MetaMarketingClient { return $this->client; }

    // ---- create -------------------------------------------------------------------------------------

    public function validate(array $spec): array
    {
        $e = MetaPlanBuilder::validate($spec);
        if ($e === []) {                                         // audience ownership/readiness needs the DB, so only once the spec itself is sound
            try { (new AdAudienceService())->resolveForMeta($this->tenantId, $spec); } catch (\InvalidArgumentException $x) { $e[] = $x->getMessage(); }
        }
        return $e;
    }

    public function preflight(array $spec): array
    {
        $notes = [];
        $acct  = $this->client->request('GET', $spec['account_id'], ['fields' => 'currency,account_status,disable_reason,name']);
        $currency = (string) ($acct['currency'] ?? '');
        if ((int) ($acct['account_status'] ?? 1) !== 1) {
            throw new AdsApiException('This ad account is not active (status ' . ($acct['account_status'] ?? '?') . '). Fix it in Meta Business settings first — e.g. a failed payment method.', AdsApiException::INVALID);
        }
        // Platform-side validation of the campaign object — nothing is created.
        $plan = MetaPlanBuilder::plan((new AdAudienceService())->resolveForMeta($this->tenantId, $spec));
        $this->client->create($spec['account_id'], 'campaigns', $plan['campaign'], true);
        $notes[] = 'Meta accepted the campaign settings (validation only — nothing was created).';
        return ['currency' => $currency, 'notes' => $notes];
    }

    public function create(array $spec): array
    {
        $acct = $spec['account_id'];
        $spec = (new AdAudienceService())->resolveForMeta($this->tenantId, $spec);                  // local audience ids -> Meta ids (and refuses unusable ones)
        $spec = (new AdAssetService())->resolveMetaImages($this->tenantId, $this->client, $spec);   // uploads local images, swaps in Meta's image_hash
        $plan = MetaPlanBuilder::plan($spec);
        $made = [];   // every id created so far, for rollback
        $step = 'campaign';
        $sets = [];
        try {
            $cid = $this->client->create($acct, 'campaigns', $plan['campaign']);           $made[] = $cid;
            foreach ($plan['sets'] as $i => $p) {
                $n = count($plan['sets']) > 1 ? ' ' . ($i + 1) : '';
                $step = 'ad set' . $n;
                $asid = $this->client->create($acct, 'adsets', $p['adset'] + ['campaign_id' => $cid]);   $made[] = $asid;
                $step = 'creative' . $n;
                $crid = $this->client->create($acct, 'adcreatives', $p['creative']);         $made[] = $crid;
                $step = 'ad' . $n;
                $adid = $this->client->create($acct, 'ads', $p['ad'] + ['adset_id' => $asid, 'creative' => ['creative_id' => $crid]]);   $made[] = $adid;
                $sets[] = ['adset_id' => $asid, 'creative_id' => $crid, 'ad_id' => $adid, 'budget' => $p['budget']];
            }
        } catch (AdsApiException $e) {
            foreach (array_reverse($made) as $id) {            // best-effort cleanup; everything was PAUSED anyway
                try { $this->client->delete($id); } catch (\Throwable) {}
            }
            throw new AdsApiException("Meta rejected the {$step}: " . $e->getMessage(), $e->kind, $e->platformCode, $e->raw);
        }
        // adset_id/creative_id/ad_id = the first set (older rows and single-set callers); `sets` lists all of them.
        return ['external_id' => $cid, 'account_ref' => $acct, 'objective' => $plan['campaign']['objective'], 'currency' => 'INR',
            'children' => ['adset_id' => $sets[0]['adset_id'], 'creative_id' => $sets[0]['creative_id'], 'ad_id' => $sets[0]['ad_id'], 'sets' => $sets]];
    }

    // ---- manage -------------------------------------------------------------------------------------

    public function setStatus(array $campaign, string $status): void
    {
        $kids = json_decode((string) ($campaign['children'] ?? '{}'), true) ?: [];
        if ($status === 'ACTIVE') {                            // bottom-up so nothing serves half-built
            $sets = $kids['sets'] ?? [$kids];
            foreach ($sets as $set) {
                foreach (['ad_id', 'adset_id'] as $k) {
                    if (! empty($set[$k])) { $this->client->update((string) $set[$k], ['status' => 'ACTIVE']); }
                }
            }
        }
        $this->client->update((string) $campaign['external_id'], ['status' => $status]);
    }

    public function setBudget(array $campaign, int $dailyPaise): void
    {
        $kids = json_decode((string) ($campaign['children'] ?? '{}'), true) ?: [];
        if (count($kids['sets'] ?? []) > 1) {                  // keep each ad set's share of the total
            $old = array_sum(array_map(static fn ($x) => (int) ($x['budget'] ?? 0), $kids['sets']));
            $new = []; $left = $dailyPaise;
            foreach ($kids['sets'] as $i => $x) { $new[$i] = $old > 0 ? (int) floor($dailyPaise * (int) ($x['budget'] ?? 0) / $old) : intdiv($dailyPaise, count($kids['sets'])); $left -= $new[$i]; }
            $new[0] += $left;                                  // remainder paise to the first set: the parts always add up
            foreach ($kids['sets'] as $i => $x) { $this->client->update((string) $x['adset_id'], ['daily_budget' => $new[$i]]); }
            return;
        }
        $adset = $kids['adset_id'] ?? null;
        if (! $adset) {
            $sets = $this->client->request('GET', (string) $campaign['external_id'] . '/adsets', ['fields' => 'id', 'limit' => 5])['data'] ?? [];
            if (count($sets) !== 1) {
                throw new AdsApiException('This campaign has ' . (count($sets) ?: 'no') . ' ad sets (or budget is set on the campaign itself). Change its budget in Meta Ads Manager.', AdsApiException::INVALID);
            }
            $adset = $sets[0]['id'];
        }
        $this->client->update((string) $adset, ['daily_budget' => $dailyPaise]);
    }

    // ---- sync ---------------------------------------------------------------------------------------

    public function sync(int $tenantId): array
    {
        $cfgRow = (new IntegrationModel())->findActiveByType($tenantId, 'meta_ads');
        $cfg = $cfgRow ? (json_decode((string) ((array) $cfgRow)['config'], true) ?: []) : [];
        $out = ['campaigns' => 0, 'insight_days' => 0];
        $until = date('Y-m-d');
        $since = date('Y-m-d', strtotime('-30 days'));

        $issues = []; $issuesOk = true;
        $accounts = (array) ($cfg['ad_accounts'] ?? []);
        if (! $accounts && AdsMockHttp::enabled()) { $accounts = [['id' => 'act_1000000001', 'name' => 'Demo Travels Ads', 'currency' => 'INR']]; }
        foreach ($accounts as $a) {
            $acct = (string) $a['id'];
            if (! str_starts_with($acct, 'act_')) { $acct = 'act_' . $acct; }
            $budgets = [];
            $kids = [];
            foreach ($this->client->adSets($acct) as $s) {
                $budgets[$s['campaign_id']] = ($budgets[$s['campaign_id']] ?? 0) + (int) ($s['daily_budget'] ?? 0);
                $kids[$s['campaign_id']]['adset_id'] = $kids[$s['campaign_id']]['adset_id'] ?? $s['id'];
            }
            $seen = [];
            foreach ($this->client->campaigns($acct) as $c) {
                $seen[] = (string) $c['id'];
                $row = (new AdCampaignModel())->setTenant($tenantId)->where('platform', 'meta')->where('external_id', $c['id'])->first();
                $data = [
                    'platform' => 'meta', 'external_id' => (string) $c['id'], 'account_ref' => $acct, 'name' => (string) $c['name'],
                    'objective' => (string) ($c['objective'] ?? ''), 'status' => (string) ($c['status'] ?? 'UNKNOWN'), 'effective_status' => (string) ($c['effective_status'] ?? ''),
                    'currency' => (string) ($a['currency'] ?: 'INR'),
                    'daily_budget' => (int) ($c['daily_budget'] ?? 0) ?: (int) ($budgets[$c['id']] ?? 0),
                    'last_synced_at' => date('Y-m-d H:i:s'),
                ];
                if ($row) {
                    $existingKids = json_decode((string) ($row['children'] ?? '{}'), true) ?: [];
                    $data['children'] = json_encode($existingKids + ($kids[$c['id']] ?? []));
                    (new AdCampaignModel())->setTenant($tenantId)->update((int) $row['id'], $data);
                } else {
                    (new AdCampaignModel())->setTenant($tenantId)->insert($data + ['origin' => 'synced', 'children' => json_encode($kids[$c['id']] ?? [])]);
                }
                $out['campaigns']++;
            }
            // Deleted in Meta -> keep our row (history/insights) but stop treating it as live.
            $local = (new AdCampaignModel())->setTenant($tenantId)->where('platform', 'meta')->where('account_ref', $acct)->where('external_id NOT LIKE', 'pending_%')->findAll();
            foreach ($local as $l) {
                if (! in_array($l['external_id'], $seen, true) && $l['status'] !== 'DELETED') {
                    (new AdCampaignModel())->setTenant($tenantId)->update((int) $l['id'], ['status' => 'DELETED', 'effective_status' => 'DELETED']);
                }
            }
            foreach ($this->client->insights($acct, $since, $until) as $i) {
                $this->upsertInsight($tenantId, (string) $i['campaign_id'], (string) $i['date_start'], $i);
                $out['insight_days']++;
            }
            try {
                foreach ($this->client->problemAds($acct) as $ad) { if ($p = self::parseIssue($ad)) { $issues[] = $p; } }
            } catch (AdsApiException $e) { $issuesOk = false; $out['issues_error'] = $e->getMessage(); }   // incomplete list: never "resolve" on it
        }
        if ($issuesOk && $accounts) { $out['issues'] = (new AdIssueService())->record($tenantId, 'meta', $issues); }
        return $out;
    }

    /** Meta ad -> an issue row, or null when the ad is fine. Review feedback looks like {"global": {"Reason": "explanation"}}. */
    public static function parseIssue(array $ad): ?array
    {
        $st = (string) ($ad['effective_status'] ?? '');
        if (! in_array($st, ['DISAPPROVED', 'WITH_ISSUES'], true)) { return null; }
        $parts = [];
        foreach ((array) ($ad['ad_review_feedback'] ?? []) as $group) {
            foreach ((array) $group as $reason => $why) { $parts[] = is_string($reason) ? trim($reason . (is_string($why) && $why !== '' ? ' — ' . $why : '')) : trim((string) $why); }
        }
        return ['campaign' => (string) ($ad['campaign_id'] ?? ''), 'ad' => (string) $ad['id'], 'ad_name' => (string) ($ad['name'] ?? ''), 'kind' => $st === 'DISAPPROVED' ? 'disapproved' : 'limited',
            'reason' => $parts ? implode('; ', array_slice($parts, 0, 3)) : ($st === 'DISAPPROVED' ? 'Disapproved by Meta ad review.' : 'Meta reported issues with this ad.')];
    }

    /** @param array $i raw Meta insight row */
    public static function parseInsight(array $i): array
    {
        $leads = 0; $conv = 0;
        foreach ((array) ($i['actions'] ?? []) as $a) {
            if (in_array($a['action_type'] ?? '', self::LEAD_ACTIONS, true)) { $leads = max($leads, (int) $a['value']); }
            if (($a['action_type'] ?? '') === self::CONVERSATION_ACTION) { $conv = (int) $a['value']; }
        }
        return ['spend' => (int) round(((float) ($i['spend'] ?? 0)) * 100), 'impressions' => (int) ($i['impressions'] ?? 0),
            'clicks' => (int) ($i['clicks'] ?? 0), 'leads' => $leads, 'conversations' => $conv];
    }

    private function upsertInsight(int $tenantId, string $campaignId, string $day, array $raw): void
    {
        $m = array_merge(['tenant_id' => $tenantId, 'platform' => 'meta', 'campaign_external_id' => $campaignId, 'day' => $day, 'synced_at' => date('Y-m-d H:i:s')], self::parseInsight($raw));
        $existing = (new AdInsightDailyModel())->setTenant($tenantId)->where('platform', 'meta')->where('campaign_external_id', $campaignId)->where('day', $day)->first();
        if ($existing) { (new AdInsightDailyModel())->setTenant($tenantId)->update((int) $existing['id'], $m); }
        else { (new AdInsightDailyModel())->setTenant($tenantId)->insert($m); }
    }
}
