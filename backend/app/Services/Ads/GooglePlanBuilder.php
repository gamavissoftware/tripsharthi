<?php

declare(strict_types=1);

namespace App\Services\Ads;

/** Google Search campaign plan: spec -> one ATOMIC mutate (temp ids -1..). PURE and contract-tested. Everything is PAUSED. */
final class GooglePlanBuilder
{
    public const INDIA = 2356;
    public const LANGUAGES = ['en' => 1000, 'hi' => 1023];   // English, Hindi
    public const MATCH_TYPES = ['BROAD', 'PHRASE', 'EXACT'];

    public const MAX_GROUPS = 5;

    /** The ad groups of a spec. A spec without `ad_groups` is ONE group made of the top-level headlines/descriptions/keywords. */
    public static function groups(array $s): array
    {
        $g = array_values((array) ($s['ad_groups'] ?? []));
        if ($g) { return $g; }
        return [['name' => null, 'headlines' => $s['headlines'] ?? [], 'descriptions' => $s['descriptions'] ?? [], 'keywords' => $s['keywords'] ?? [], 'final_url' => null, 'max_cpc' => $s['max_cpc'] ?? null, 'path1' => $s['path1'] ?? null, 'path2' => $s['path2'] ?? null]];
    }

    /** @return array{errors:list<string>,warnings:list<string>} */
    public static function validate(array $s): array
    {
        $e = []; $w = [];
        if (! in_array($s['kind'] ?? '', ['search', 'pmax', 'demand_gen'], true)) { $e[] = 'Choose a campaign type: Search, Performance Max or Demand Gen.'; }
        if (trim((string) ($s['name'] ?? '')) === '') { $e[] = 'Give the campaign a name.'; }
        if (! preg_match('/^\d{10}$/', (string) ($s['customer_id'] ?? ''))) { $e[] = 'Choose a Google Ads account.'; }
        if ((int) ($s['daily_budget'] ?? 0) <= 0) { $e[] = 'Enter a daily budget.'; }
        if (! preg_match('#^https://[^\s]+$#', (string) ($s['final_url'] ?? ''))) { $e[] = 'Enter the landing page (https://…).'; }
        if (! empty($s['end_date']) && ! empty($s['start_date']) && $s['end_date'] < $s['start_date']) { $e[] = 'End date is before the start date.'; }
        if (($s['kind'] ?? '') === 'pmax') { $r = PmaxPlanBuilder::validate($s); return ['errors' => array_merge($e, $r['errors']), 'warnings' => $r['warnings']]; }
        if (($s['kind'] ?? '') === 'demand_gen') { $r = DemandGenPlanBuilder::validate($s); return ['errors' => array_merge($e, $r['errors']), 'warnings' => $r['warnings']]; }

        $groups = self::groups($s);
        if (count($groups) > self::MAX_GROUPS) { $e[] = 'Use at most ' . self::MAX_GROUPS . ' ad groups per campaign.'; }
        foreach ($groups as $i => $g) {
            $r = self::validateGroup($g, count($groups) > 1 ? 'Ad group ' . ($i + 1) . ': ' : '');
            $e = array_merge($e, $r['errors']); $w = array_merge($w, $r['warnings']);
            if (! empty($g['final_url']) && ! preg_match('#^https://[^\s]+$#', (string) $g['final_url'])) { $e[] = 'Ad group ' . ($i + 1) . ': the landing page must start with https://.'; }
        }
        return ['errors' => $e, 'warnings' => $w];
    }

    /** @return array{errors:list<string>,warnings:list<string>} */
    private static function validateGroup(array $g, string $p): array
    {
        $e = []; $w = [];
        $h = array_values(array_filter(array_map('trim', (array) ($g['headlines'] ?? []))));
        $d = array_values(array_filter(array_map('trim', (array) ($g['descriptions'] ?? []))));
        if (count($h) < 3 || count($h) > 15) { $e[] = $p . 'Provide 3 to 15 headlines.'; }
        if (count($d) < 2 || count($d) > 4) { $e[] = $p . 'Provide 2 to 4 descriptions.'; }
        if (count($h) !== count(array_unique(array_map('mb_strtolower', $h)))) { $e[] = $p . 'Headlines must be different from each other.'; }
        $fields = []; $limits = [];
        foreach ($h as $i => $t) { $fields['headline ' . ($i + 1)] = $t; $limits['headline ' . ($i + 1)] = 30; }
        foreach ($d as $i => $t) { $fields['description ' . ($i + 1)] = $t; $limits['description ' . ($i + 1)] = 90; }
        $lint = AdCopyLint::checkAll($fields, 'google', $limits);
        $e = array_merge($e, array_map(static fn ($m) => $p . $m, $lint['errors'])); $w = array_merge($w, array_map(static fn ($m) => $p . $m, $lint['warnings']));

        $k = (array) ($g['keywords'] ?? []);
        if (count($k) < 1 || count($k) > 50) { $e[] = $p . 'Provide 1 to 50 keywords.'; }
        foreach ($k as $kw) {
            $t = trim((string) ($kw['text'] ?? ''));
            if ($t === '' || mb_strlen($t) > 80) { $e[] = $p . 'Each keyword must be 1–80 characters.'; break; }
            if (! in_array($kw['match'] ?? 'PHRASE', self::MATCH_TYPES, true)) { $e[] = $p . 'Keyword match type must be broad, phrase or exact.'; break; }
        }
        return ['errors' => $e, 'warnings' => $w];
    }

    public static function plan(array $s): array
    {
        if (($s['kind'] ?? 'search') === 'pmax') { return PmaxPlanBuilder::plan($s); }
        if (($s['kind'] ?? 'search') === 'demand_gen') { return DemandGenPlanBuilder::plan($s); }
        $c  = "customers/{$s['customer_id']}";
        $rb = "{$c}/campaignBudgets/-1"; $rc = "{$c}/campaigns/-2";
        $name = trim((string) $s['name']);

        $campaign = [
            'resourceName' => $rc, 'name' => $name, 'status' => 'PAUSED', 'advertisingChannelType' => 'SEARCH', 'campaignBudget' => $rb,
            'manualCpc' => new \stdClass(),
            'networkSettings' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => false, 'targetContentNetwork' => false, 'targetPartnerSearchNetwork' => false],
            'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
            'finalUrlSuffix' => 'utm_source=google&utm_medium=cpc&utm_campaign={campaignid}&utm_term={keyword}',
        ];
        if (! empty($s['start_date'])) { $campaign['startDate'] = $s['start_date']; }
        if (! empty($s['end_date']))   { $campaign['endDate'] = $s['end_date']; }

        $ops = [
            ['campaignBudgetOperation' => ['create' => ['resourceName' => $rb, 'name' => $name . ' · budget ' . bin2hex(random_bytes(3)), 'amountMicros' => (string) AdGuardrails::paiseToMicros((int) $s['daily_budget']), 'deliveryMethod' => 'STANDARD', 'explicitlyShared' => false]]],
            ['campaignOperation' => ['create' => $campaign]],
        ];
        $groups = self::groups($s);
        foreach ($groups as $i => $g) {
            $rg = "{$c}/adGroups/" . (-3 - $i);          // temp ids -3, -4, … (campaign budget = -1, campaign = -2)
            $label = count($groups) > 1 ? (trim((string) ($g['name'] ?? '')) ?: 'Ad group ' . ($i + 1)) : 'ad group';
            $ops[] = ['adGroupOperation' => ['create' => ['resourceName' => $rg, 'name' => $name . ' · ' . $label, 'campaign' => $rc, 'status' => 'ENABLED', 'type' => 'SEARCH_STANDARD',
                'cpcBidMicros' => (string) AdGuardrails::paiseToMicros((int) (($g['max_cpc'] ?? null) ?: ($s['max_cpc'] ?? 2000)))]]];
            $ops[] = ['adGroupAdOperation' => ['create' => ['adGroup' => $rg, 'status' => 'ENABLED', 'ad' => [
                'finalUrls' => [($g['final_url'] ?? null) ?: $s['final_url']],
                'responsiveSearchAd' => array_filter([
                    'headlines' => array_map(static fn ($t) => ['text' => trim($t)], array_values(array_filter((array) $g['headlines']))),
                    'descriptions' => array_map(static fn ($t) => ['text' => trim($t)], array_values(array_filter((array) $g['descriptions']))),
                    'path1' => $g['path1'] ?? null, 'path2' => $g['path2'] ?? null,
                ])]]]];
            foreach ((array) $g['keywords'] as $kw) {
                $ops[] = ['adGroupCriterionOperation' => ['create' => ['adGroup' => $rg, 'status' => 'ENABLED', 'keyword' => ['text' => trim($kw['text']), 'matchType' => $kw['match'] ?? 'PHRASE']]]];
            }
        }
        foreach ((array) ($s['negatives'] ?? []) as $n) {
            if (trim((string) $n) !== '') { $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $rc, 'negative' => true, 'keyword' => ['text' => trim($n), 'matchType' => 'BROAD']]]]; }
        }
        foreach ((array) ($s['geo'] ?? [self::INDIA]) ?: [self::INDIA] as $g) {
            $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $rc, 'location' => ['geoTargetConstant' => 'geoTargetConstants/' . (int) $g]]]];
        }
        foreach ((array) ($s['languages'] ?? ['en']) as $l) {
            if (isset(self::LANGUAGES[$l])) { $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $rc, 'language' => ['languageConstant' => 'languageConstants/' . self::LANGUAGES[$l]]]]]; }
        }
        return $ops;
    }

    /** Pull the new resource ids out of a mutate response (ordered like the operations). ad_group/ad = the first; ad_groups/ads list them all. */
    public static function ids(array $response): array
    {
        $out = ['ad_groups' => [], 'ads' => []];
        foreach ((array) ($response['mutateOperationResponses'] ?? []) as $r) {
            foreach (['campaignBudgetResult' => 'budget', 'campaignResult' => 'campaign', 'assetGroupResult' => 'asset_group'] as $k => $name) {
                if (isset($r[$k]['resourceName'])) { $out[$name] = $r[$k]['resourceName']; }
            }
            if (isset($r['adGroupResult']['resourceName'])) { $out['ad_groups'][] = $r['adGroupResult']['resourceName']; }
            if (isset($r['adGroupAdResult']['resourceName'])) { $out['ads'][] = $r['adGroupAdResult']['resourceName']; }
        }
        if ($out['ad_groups']) { $out['ad_group'] = $out['ad_groups'][0]; }
        if ($out['ads']) { $out['ad'] = $out['ads'][0]; }
        return $out;
    }
}
