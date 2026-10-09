<?php

declare(strict_types=1);

namespace App\Services\Ads;

/**
 * Google Demand Gen (multi-asset image ads on YouTube, Discover, Gmail, Display, Maps): ONE atomic mutate = budget + campaign + ad group +
 * image assets + one DemandGenMultiAssetAd (+ location/language on the ad group). PURE and contract-tested. Everything is PAUSED.
 *
 * Field names and limits are taken from the Google Ads API v25 definitions (DemandGenMultiAssetAdInfo, AdGroup.DemandGenAdGroupSettings,
 * Campaign.DemandGenCampaignSettings): headlines 1-5 (30), descriptions 1-5 (90), business name required (25), marketing images — landscape
 * 1.91:1 min 600x314, square 1:1 min 300x300, portrait 4:5 min 480x600, tall 9:16 min 600x1067 (each +-1%; at least one landscape OR square;
 * at most 20 in total), logos 1:1 min 128x128 (1-5, required). Text lives inline as AdTextAsset ({text}); images are Asset resources.
 * Scope: image ads only. Video, carousel and product-feed ads are NOT built. It has been checked against those definitions and our simulator,
 * not against a live account — the review step's validate-only call is what proves a spec on a real account.
 */
final class DemandGenPlanBuilder
{
    public const CHANNELS = ['youtube_in_stream' => 'YouTube In-Stream', 'youtube_in_feed' => 'YouTube In-Feed', 'youtube_shorts' => 'YouTube Shorts', 'discover' => 'Discover', 'gmail' => 'Gmail', 'display' => 'Google Display Network', 'maps' => 'Maps'];
    public const CTAS = ['Book now', 'Learn more', 'Get quote', 'Contact us', 'Sign up', 'Visit site'];
    /** slot => [field in the ad, min, max] */
    public const SLOTS = ['landscape' => ['marketingImages', 0, 20], 'square' => ['squareMarketingImages', 0, 20], 'portrait' => ['portraitMarketingImages', 0, 20], 'tall' => ['tallPortraitMarketingImages', 0, 20], 'logo' => ['logoImages', 1, 5]];

    /** @return array{errors:list<string>,warnings:list<string>} */
    public static function validate(array $s): array
    {
        $e = []; $w = [];
        $d = (array) ($s['demand_gen'] ?? []);
        $bn = trim((string) ($d['business_name'] ?? ''));
        if ($bn === '' || mb_strlen($bn) > 25) { $e[] = 'Enter your business name (up to 25 characters).'; }
        $fields = []; $limits = [];
        foreach (['headlines' => [1, 5, 30, 'headline'], 'descriptions' => [1, 5, 90, 'description']] as $k => [$min, $max, $len, $label]) {
            $items = array_values(array_filter(array_map('trim', (array) ($d[$k] ?? []))));
            if (count($items) < $min || count($items) > $max) { $e[] = "Provide {$min} to {$max} {$label}s."; }
            if (count($items) !== count(array_unique(array_map('mb_strtolower', $items)))) { $e[] = ucfirst($label) . 's must be different from each other.'; }
            foreach ($items as $i => $t) { $fields["{$label} " . ($i + 1)] = $t; $limits["{$label} " . ($i + 1)] = $len; }
        }
        $lint = AdCopyLint::checkAll($fields, 'google', $limits);
        $e = array_merge($e, $lint['errors']); $w = array_merge($w, $lint['warnings']);
        $cta = trim((string) ($d['cta'] ?? ''));
        if ($cta !== '' && ! in_array($cta, self::CTAS, true)) { $e[] = 'Choose a button text from the list.'; }
        if (! in_array($d['bidding'] ?? 'conversions', ['conversions', 'clicks'], true)) { $e[] = 'Choose what to optimise for: conversions or clicks.'; }

        $ch = $d['channels'] ?? 'all';
        if (is_array($ch)) {
            $picked = array_values(array_intersect(array_keys(self::CHANNELS), array_map('strval', $ch)));
            if (! $picked) { $e[] = 'Choose at least one place for the ads to show (or use all channels).'; }
        } elseif (! in_array($ch, ['all', 'owned'], true)) { $e[] = 'Unknown channel choice.'; }

        $total = 0; $counts = [];
        foreach (self::SLOTS as $slot => [, $min, $max]) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($d['images'][$slot] ?? [])))));
            $counts[$slot] = count($ids);
            if ($slot !== 'logo') { $total += count($ids); }
            if ($slot === 'logo' && (count($ids) < $min || count($ids) > $max)) { $e[] = 'Add 1 to 5 square logos (at least 128×128).'; }
        }
        if ($counts['landscape'] + $counts['square'] < 1) { $e[] = 'Add at least one landscape (1.91:1) or square (1:1) image.'; }
        if ($total > 20) { $e[] = 'Use at most 20 images in total (excluding logos).'; }
        if ($counts['portrait'] + $counts['tall'] === 0) { $w[] = 'Portrait (4:5) and tall (9:16) images are what YouTube Shorts and Discover feeds show best — consider adding some.'; }
        return ['errors' => $e, 'warnings' => $w];
    }

    /** Every image asset id with the slot it is meant for. @return array<int,string> id => slot (first slot wins when an id is reused) */
    public static function imageRefs(array $s): array
    {
        $out = [];
        foreach (array_keys(self::SLOTS) as $slot) { foreach ((array) ($s['demand_gen']['images'][$slot] ?? []) as $id) { if ((int) $id > 0) { $out[(int) $id] ??= $slot; } } }
        return $out;
    }

    public static function plan(array $s): array
    {
        $c = "customers/{$s['customer_id']}"; $d = (array) $s['demand_gen'];
        $rb = "{$c}/campaignBudgets/-1"; $rc = "{$c}/campaigns/-2"; $rg = "{$c}/adGroups/-3";
        $name = trim((string) $s['name']);

        $campaign = [
            'resourceName' => $rc, 'name' => $name, 'status' => 'PAUSED', 'advertisingChannelType' => 'DEMAND_GEN', 'campaignBudget' => $rb,
            ($d['bidding'] ?? 'conversions') === 'clicks' ? 'targetSpend' : 'maximizeConversions' => new \stdClass(),
            'demandGenCampaignSettings' => ['upgradedTargeting' => true],                       // location + language are set on the AD GROUP
            'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
            'finalUrlSuffix' => 'utm_source=google&utm_medium=demandgen&utm_campaign={campaignid}',
        ];
        if (! empty($s['start_date'])) { $campaign['startDate'] = $s['start_date']; }
        if (! empty($s['end_date']))   { $campaign['endDate'] = $s['end_date']; }

        $ch = $d['channels'] ?? 'all';
        $channelControls = is_array($ch)
            ? ['selectedChannels' => array_fill_keys(array_map(static fn ($k) => lcfirst(str_replace('_', '', ucwords($k, '_'))), array_values(array_intersect(array_keys(self::CHANNELS), array_map('strval', $ch)))), true)]
            : ['channelStrategy' => $ch === 'owned' ? 'ALL_OWNED_AND_OPERATED_CHANNELS' : 'ALL_CHANNELS'];

        $ops = [
            ['campaignBudgetOperation' => ['create' => ['resourceName' => $rb, 'name' => $name . ' · budget ' . bin2hex(random_bytes(3)), 'amountMicros' => (string) AdGuardrails::paiseToMicros((int) $s['daily_budget']), 'deliveryMethod' => 'STANDARD', 'explicitlyShared' => false]]],
            ['campaignOperation' => ['create' => $campaign]],
            ['adGroupOperation' => ['create' => ['resourceName' => $rg, 'name' => $name . ' · ad group', 'campaign' => $rc, 'status' => 'ENABLED',
                'audienceSetting' => ['useAudienceGrouped' => false], 'demandGenAdGroupSettings' => ['channelControls' => $channelControls]]]],
        ];

        $tmp = -10; $made = []; $byField = [];
        foreach (self::SLOTS as $slot => [$field]) {
            foreach (array_values(array_unique(array_filter(array_map('intval', (array) ($d['images'][$slot] ?? []))))) as $id) {
                if (! isset($made[$id])) {                      // one Asset per distinct stored image, even if it is used in two slots
                    $ra = "{$c}/assets/" . $tmp--; $made[$id] = $ra;
                    $ops[] = ['assetOperation' => ['create' => ['resourceName' => $ra, 'name' => $name . ' · image ' . $id, 'imageAsset' => ['data' => (string) ($d['image_data'][$id] ?? '')]]]];
                }
                $byField[$field][] = ['asset' => $made[$id]];
            }
        }
        $ad = array_filter([
            'headlines' => array_map(static fn ($t) => ['text' => trim($t)], array_values(array_filter(array_map('trim', (array) $d['headlines'])))),
            'descriptions' => array_map(static fn ($t) => ['text' => trim($t)], array_values(array_filter(array_map('trim', (array) $d['descriptions'])))),
            'businessName' => trim((string) $d['business_name']),
            'callToActionText' => trim((string) ($d['cta'] ?? '')) ?: null,
        ] + $byField, static fn ($v) => $v !== null && $v !== '');
        $ops[] = ['adGroupAdOperation' => ['create' => ['adGroup' => $rg, 'status' => 'ENABLED', 'ad' => ['finalUrls' => [$s['final_url']], 'demandGenMultiAssetAd' => $ad]]]];

        foreach ((array) ($s['geo'] ?? [GooglePlanBuilder::INDIA]) ?: [GooglePlanBuilder::INDIA] as $g) {
            $ops[] = ['adGroupCriterionOperation' => ['create' => ['adGroup' => $rg, 'location' => ['geoTargetConstant' => 'geoTargetConstants/' . (int) $g]]]];
        }
        foreach ((array) ($s['languages'] ?? ['en']) as $l) {
            if (isset(GooglePlanBuilder::LANGUAGES[$l])) { $ops[] = ['adGroupCriterionOperation' => ['create' => ['adGroup' => $rg, 'language' => ['languageConstant' => 'languageConstants/' . GooglePlanBuilder::LANGUAGES[$l]]]]]; }
        }
        return $ops;
    }
}
