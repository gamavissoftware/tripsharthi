<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Models\AdCampaignModel;
use App\Models\AdInsightDailyModel;

final class GoogleAdapter implements AdPlatformAdapter
{
    public function __construct(private readonly int $tenantId, private readonly GoogleAdsClient $client) {}

    public static function forTenant(int $tenantId, ?callable $http = null): ?self
    {
        $c = GoogleAdsClient::forTenant($tenantId, $http);
        return $c ? new self($tenantId, $c) : null;
    }

    public function client(): GoogleAdsClient { return $this->client; }

    /** Normalise Google's enum to the shared ACTIVE/PAUSED/DELETED vocabulary. */
    public static function status(string $g): string
    {
        return match ($g) { 'ENABLED' => 'ACTIVE', 'PAUSED' => 'PAUSED', 'REMOVED' => 'DELETED', default => $g };
    }

    public function validate(array $spec): array
    {
        $errors = GooglePlanBuilder::validate($spec)['errors'];
        if (in_array($spec['kind'] ?? '', ['pmax', 'demand_gen'], true) && ! $errors) { $errors = $this->checkImages($spec); }   // shapes live in the DB, so only once the spec is otherwise sound
        return $errors;
    }

    /** Each referenced image must exist and fit its slot (a portrait photo cannot be a landscape marketing image). @return list<string> */
    private function checkImages(array $spec): array
    {
        $e = []; $assets = new AdAssetService();
        $dg = ($spec['kind'] ?? '') === 'demand_gen';
        foreach ($dg ? DemandGenPlanBuilder::imageRefs($spec) : PmaxPlanBuilder::imageRefs($spec) as $id => $slot) {
            try { $img = $assets->read($this->tenantId, $id); }
            catch (\InvalidArgumentException | \RuntimeException $ex) { $e[] = $ex->getMessage(); continue; }
            if ($dg) {                                                    // Demand Gen: exact ratio (+-1%) and a minimum size per slot
                if ($p = AdAssetService::strictProblem($img['width'], $img['height'], $slot, $img['name'])) { $e[] = $p; }
                continue;
            }
            $need = $slot === 'logo' ? 'square' : $slot;                    // a logo is a square image
            if ($img['shape'] !== $need) { $e[] = "“{$img['name']}” is a {$img['shape']} image ({$img['width']}×{$img['height']}) but was chosen as a {$slot} image" . ($slot === 'logo' ? ' (logos must be square).' : '.'); }
        }
        return $e;
    }

    /** PMax / Demand Gen: attach the base64 of every referenced image (never stored in the spec itself). */
    private function withImages(array $spec): array
    {
        $kind = $spec['kind'] ?? '';
        if (! in_array($kind, ['pmax', 'demand_gen'], true)) { return $spec; }
        $assets = new AdAssetService();
        $key = $kind === 'pmax' ? 'pmax' : 'demand_gen';
        foreach (array_keys($kind === 'pmax' ? PmaxPlanBuilder::imageRefs($spec) : DemandGenPlanBuilder::imageRefs($spec)) as $id) { $spec[$key]['image_data'][$id] = base64_encode($assets->read($this->tenantId, $id)['bytes']); }
        return $spec;
    }

    public function preflight(array $spec): array
    {
        $rows = $this->client->search('SELECT customer.currency_code, customer.status, customer.descriptive_name FROM customer LIMIT 1');
        $cur = (string) ($rows[0]['customer']['currencyCode'] ?? '');
        if (($rows[0]['customer']['status'] ?? 'ENABLED') !== 'ENABLED') {
            throw new AdsApiException('This Google Ads account is not enabled (suspended, cancelled or not yet set up).', AdsApiException::INVALID);
        }
        $this->client->mutate(GooglePlanBuilder::plan($this->withImages($spec)), true);   // atomic dry run: nothing is created
        return ['currency' => $cur, 'notes' => ['Google accepted the whole campaign (validation only — nothing was created).']];
    }

    public function create(array $spec): array
    {
        $r = $this->client->mutate(GooglePlanBuilder::plan($this->withImages($spec)));    // atomic: all or nothing, so no rollback needed
        $ids = GooglePlanBuilder::ids($r);
        if (empty($ids['campaign'])) { throw new AdsApiException('Google did not return the new campaign. Check the account in Google Ads before retrying.', AdsApiException::TRANSIENT); }
        $kind = ['pmax' => 'PERFORMANCE_MAX', 'demand_gen' => 'DEMAND_GEN'][$spec['kind'] ?? ''] ?? 'SEARCH';
        return ['external_id' => self::lastId($ids['campaign']), 'account_ref' => $spec['customer_id'], 'objective' => $kind, 'currency' => 'INR',
            'children' => ['budget_resource' => $ids['budget'] ?? null, 'ad_group' => $ids['ad_group'] ?? null, 'ad' => $ids['ad'] ?? null, 'ad_groups' => $ids['ad_groups'], 'ads' => $ids['ads'], 'asset_group' => $ids['asset_group'] ?? null]];
    }

    public function setStatus(array $campaign, string $status): void
    {
        $this->client->mutateResource('campaigns', [[
            'update' => ['resourceName' => "customers/{$this->client->customerId()}/campaigns/{$campaign['external_id']}", 'status' => $status === 'ACTIVE' ? 'ENABLED' : 'PAUSED'],
            'updateMask' => 'status',
        ]]);
    }

    public function setBudget(array $campaign, int $dailyPaise): void
    {
        $kids = json_decode((string) ($campaign['children'] ?? '{}'), true) ?: [];
        if (empty($kids['budget_resource'])) {
            throw new AdsApiException('Unknown budget for this campaign — run Sync first.', AdsApiException::INVALID);
        }
        if (! empty($kids['budget_shared'])) {
            throw new AdsApiException('This campaign uses a budget shared with other campaigns. Change it in Google Ads.', AdsApiException::INVALID);
        }
        $this->client->mutateResource('campaignBudgets', [[
            'update' => ['resourceName' => $kids['budget_resource'], 'amountMicros' => (string) AdGuardrails::paiseToMicros($dailyPaise)],
            'updateMask' => 'amount_micros',
        ]]);
    }

    public function sync(int $tenantId): array
    {
        $out = ['campaigns' => 0, 'insight_days' => 0];
        $cid = $this->client->customerId();
        $seen = [];
        $rows = $this->client->search("SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign.campaign_budget, campaign_budget.amount_micros, campaign_budget.explicitly_shared, customer.currency_code FROM campaign WHERE campaign.status != 'REMOVED'");
        foreach ($rows as $r) {
            $id = (string) $r['campaign']['id'];
            $seen[] = $id;
            $kids = ['budget_resource' => $r['campaign']['campaignBudget'] ?? null, 'budget_shared' => (bool) ($r['campaignBudget']['explicitlyShared'] ?? false)];
            $data = ['platform' => 'google', 'external_id' => $id, 'account_ref' => $cid, 'name' => (string) $r['campaign']['name'],
                'objective' => (string) ($r['campaign']['advertisingChannelType'] ?? ''), 'status' => self::status((string) $r['campaign']['status']),
                'effective_status' => (string) $r['campaign']['status'], 'currency' => (string) ($r['customer']['currencyCode'] ?? 'INR'),
                'daily_budget' => AdGuardrails::microsToPaise((string) ($r['campaignBudget']['amountMicros'] ?? 0)), 'last_synced_at' => date('Y-m-d H:i:s')];
            $row = (new AdCampaignModel())->setTenant($tenantId)->where('platform', 'google')->where('external_id', $id)->first();
            if ($row) {
                $old = json_decode((string) ($row['children'] ?? '{}'), true) ?: [];
                (new AdCampaignModel())->setTenant($tenantId)->update((int) $row['id'], $data + ['children' => json_encode($kids + $old)]);
            } else {
                (new AdCampaignModel())->setTenant($tenantId)->insert($data + ['origin' => 'synced', 'children' => json_encode($kids)]);
            }
            $out['campaigns']++;
        }
        foreach ((new AdCampaignModel())->setTenant($tenantId)->where('platform', 'google')->where('account_ref', $cid)->where('external_id NOT LIKE', 'pending_%')->findAll() as $l) {
            if (! in_array($l['external_id'], $seen, true) && $l['status'] !== 'DELETED') {
                (new AdCampaignModel())->setTenant($tenantId)->update((int) $l['id'], ['status' => 'DELETED', 'effective_status' => 'REMOVED']);
            }
        }

        $since = date('Y-m-d', strtotime('-30 days')); $until = date('Y-m-d');
        $m = $this->client->search("SELECT campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions FROM campaign WHERE segments.date BETWEEN '{$since}' AND '{$until}'");
        foreach ($m as $r) {
            $vals = ['tenant_id' => $tenantId, 'platform' => 'google', 'campaign_external_id' => (string) $r['campaign']['id'], 'day' => (string) $r['segments']['date'],
                'spend' => AdGuardrails::microsToPaise((string) ($r['metrics']['costMicros'] ?? 0)), 'impressions' => (int) ($r['metrics']['impressions'] ?? 0),
                'clicks' => (int) ($r['metrics']['clicks'] ?? 0), 'leads' => (int) round((float) ($r['metrics']['conversions'] ?? 0)), 'conversations' => 0, 'synced_at' => date('Y-m-d H:i:s')];
            $e = (new AdInsightDailyModel())->setTenant($tenantId)->where('platform', 'google')->where('campaign_external_id', $vals['campaign_external_id'])->where('day', $vals['day'])->first();
            $e ? (new AdInsightDailyModel())->setTenant($tenantId)->update((int) $e['id'], $vals) : (new AdInsightDailyModel())->setTenant($tenantId)->insert($vals);
            $out['insight_days']++;
        }
        try {
            $issues = [];
            foreach ($this->client->search("SELECT campaign.id, ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.policy_summary.approval_status, ad_group_ad.policy_summary.policy_topic_entries FROM ad_group_ad WHERE ad_group_ad.policy_summary.approval_status IN ('DISAPPROVED', 'APPROVED_LIMITED') AND ad_group_ad.status != 'REMOVED' AND campaign.status != 'REMOVED'") as $r) {
                if (($p = self::parseIssue($r)) && $p['ad'] !== '') { $issues[] = $p; }
            }
            $out['issues'] = (new AdIssueService())->record($tenantId, 'google', $issues);
        } catch (AdsApiException $e) { $out['issues_error'] = $e->getMessage(); }   // incomplete list: never "resolve" on it
        return $out;
    }

    /** GAQL row -> an issue, or null when approved. */
    public static function parseIssue(array $r): ?array
    {
        $ad = $r['adGroupAd'] ?? [];
        $st = (string) ($ad['policySummary']['approvalStatus'] ?? '');
        if (! in_array($st, ['DISAPPROVED', 'APPROVED_LIMITED'], true)) { return null; }
        $topics = [];
        foreach ((array) ($ad['policySummary']['policyTopicEntries'] ?? []) as $t) { if (! empty($t['topic'])) { $topics[] = ucwords(strtolower(str_replace('_', ' ', (string) $t['topic']))) . (isset($t['type']) ? ' (' . strtolower(str_replace('_', ' ', (string) $t['type'])) . ')' : ''); } }
        return ['campaign' => (string) ($r['campaign']['id'] ?? ''), 'ad' => (string) ($ad['ad']['id'] ?? ''), 'ad_name' => (string) ($ad['ad']['name'] ?? ''), 'kind' => $st === 'DISAPPROVED' ? 'disapproved' : 'limited',
            'reason' => $topics ? implode('; ', array_slice($topics, 0, 3)) : 'Disapproved by Google Ads policy review.'];
    }

    private static function lastId(string $resourceName): string
    {
        return substr($resourceName, (int) strrpos($resourceName, '/') + 1);
    }
}
