<?php

declare(strict_types=1);

namespace App\Services\Ads;

/**
 * Google Performance Max: one atomic mutate = budget + campaign + assets + ONE asset group + asset links (+ geo/language). PURE and contract-tested.
 * Everything is PAUSED. Image bytes are not known here: GoogleAdapter injects `pmax.image_data` (asset id => base64) before planning.
 * Limits follow Google's asset-group requirements: 3-15 headlines (30), 1-5 long headlines (90), 2-5 descriptions (90, at least one <= 60),
 * business name (25), at least one landscape (1.91:1) and one square (1:1) image, optional square logo.
 * NOTE: field names are written from Google's documented REST shape and verified only against the simulator and Google's validate-only
 * call at preview time — the preview step is what proves a spec is acceptable on a real account.
 */
final class PmaxPlanBuilder
{
    public const IMAGE_SLOTS = ['landscape' => ['MARKETING_IMAGE', 1, 20], 'square' => ['SQUARE_MARKETING_IMAGE', 1, 20], 'logo' => ['LOGO', 0, 5]];

    /** @return array{errors:list<string>,warnings:list<string>} */
    public static function validate(array $s): array
    {
        $e = []; $w = [];
        $p = (array) ($s['pmax'] ?? []);
        $lists = ['headlines' => [3, 15, 30, 'headline'], 'long_headlines' => [1, 5, 90, 'long headline'], 'descriptions' => [2, 5, 90, 'description']];
        $fields = []; $limits = [];
        foreach ($lists as $k => [$min, $max, $len, $label]) {
            $items = array_values(array_filter(array_map('trim', (array) ($p[$k] ?? []))));
            if (count($items) < $min || count($items) > $max) { $e[] = "Provide {$min} to {$max} {$label}s."; }
            if (count($items) !== count(array_unique(array_map('mb_strtolower', $items)))) { $e[] = ucfirst($label) . 's must be different from each other.'; }
            foreach ($items as $i => $t) { $fields["{$label} " . ($i + 1)] = $t; $limits["{$label} " . ($i + 1)] = $len; }
        }
        $short = array_filter(array_map('trim', (array) ($p['descriptions'] ?? [])), static fn ($d) => $d !== '' && mb_strlen($d) <= 60);
        if (count(array_filter((array) ($p['descriptions'] ?? []))) >= 2 && count($short) < 1) { $e[] = 'At least one description must be 60 characters or fewer.'; }
        $bn = trim((string) ($p['business_name'] ?? ''));
        if ($bn === '' || mb_strlen($bn) > 25) { $e[] = 'Enter your business name (up to 25 characters).'; }
        $lint = AdCopyLint::checkAll($fields, 'google', $limits);
        $e = array_merge($e, $lint['errors']); $w = array_merge($w, $lint['warnings']);

        foreach (self::IMAGE_SLOTS as $slot => [, $min, $max]) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($p['images'][$slot] ?? [])))));
            if (count($ids) < $min || count($ids) > $max) { $e[] = $min > 0 ? "Add {$min} to {$max} {$slot} images." : "Add at most {$max} logo images."; }
        }
        return ['errors' => $e, 'warnings' => $w];
    }

    /** Every image asset id the spec references, with the slot it is meant for. @return array<int,string> id => slot */
    public static function imageRefs(array $s): array
    {
        $out = [];
        foreach (array_keys(self::IMAGE_SLOTS) as $slot) { foreach ((array) ($s['pmax']['images'][$slot] ?? []) as $id) { if ((int) $id > 0) { $out[(int) $id] = $out[(int) $id] ?? $slot; } } }
        return $out;
    }

    public static function plan(array $s): array
    {
        $c = "customers/{$s['customer_id']}"; $p = (array) $s['pmax'];
        $rb = "{$c}/campaignBudgets/-1"; $rc = "{$c}/campaigns/-2"; $rg = "{$c}/assetGroups/-3";
        $name = trim((string) $s['name']);

        $campaign = [
            'resourceName' => $rc, 'name' => $name, 'status' => 'PAUSED', 'advertisingChannelType' => 'PERFORMANCE_MAX', 'campaignBudget' => $rb,
            'maximizeConversions' => new \stdClass(), 'urlExpansionOptOut' => false,
            'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
            'finalUrlSuffix' => 'utm_source=google&utm_medium=pmax&utm_campaign={campaignid}',
        ];
        if (! empty($s['start_date'])) { $campaign['startDate'] = $s['start_date']; }
        if (! empty($s['end_date']))   { $campaign['endDate'] = $s['end_date']; }

        $ops = [
            ['campaignBudgetOperation' => ['create' => ['resourceName' => $rb, 'name' => $name . ' · budget ' . bin2hex(random_bytes(3)), 'amountMicros' => (string) AdGuardrails::paiseToMicros((int) $s['daily_budget']), 'deliveryMethod' => 'STANDARD', 'explicitlyShared' => false]]],
            ['campaignOperation' => ['create' => $campaign]],
            ['assetGroupOperation' => ['create' => ['resourceName' => $rg, 'name' => $name . ' · assets', 'campaign' => $rc, 'finalUrls' => [$s['final_url']], 'status' => 'PAUSED']]],
        ];

        $tmp = -10; $links = [];
        $text = function (string $value, string $field) use (&$ops, &$tmp, &$links, $c): void {
            $ra = "{$c}/assets/" . $tmp--;
            $ops[] = ['assetOperation' => ['create' => ['resourceName' => $ra, 'textAsset' => ['text' => $value]]]];
            $links[] = [$ra, $field];
        };
        foreach (array_values(array_filter(array_map('trim', (array) $p['headlines']))) as $t)      { $text($t, 'HEADLINE'); }
        foreach (array_values(array_filter(array_map('trim', (array) $p['long_headlines']))) as $t) { $text($t, 'LONG_HEADLINE'); }
        foreach (array_values(array_filter(array_map('trim', (array) $p['descriptions']))) as $t)   { $text($t, 'DESCRIPTION'); }
        $text(trim((string) $p['business_name']), 'BUSINESS_NAME');

        $made = [];                                    // one image asset per distinct stored image, even if linked to two slots
        foreach (self::IMAGE_SLOTS as $slot => [$field]) {
            foreach (array_values(array_unique(array_filter(array_map('intval', (array) ($p['images'][$slot] ?? []))))) as $id) {
                if (! isset($made[$id])) {
                    $ra = "{$c}/assets/" . $tmp--; $made[$id] = $ra;
                    $ops[] = ['assetOperation' => ['create' => ['resourceName' => $ra, 'name' => $name . ' · image ' . $id, 'imageAsset' => ['data' => (string) ($p['image_data'][$id] ?? '')]]]];
                }
                $links[] = [$made[$id], $field];
            }
        }
        foreach ($links as [$ra, $field]) { $ops[] = ['assetGroupAssetOperation' => ['create' => ['assetGroup' => $rg, 'asset' => $ra, 'fieldType' => $field]]]; }

        foreach ((array) ($s['geo'] ?? [GooglePlanBuilder::INDIA]) ?: [GooglePlanBuilder::INDIA] as $g) {
            $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $rc, 'location' => ['geoTargetConstant' => 'geoTargetConstants/' . (int) $g]]]];
        }
        foreach ((array) ($s['languages'] ?? ['en']) as $l) {
            if (isset(GooglePlanBuilder::LANGUAGES[$l])) { $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $rc, 'language' => ['languageConstant' => 'languageConstants/' . GooglePlanBuilder::LANGUAGES[$l]]]]]; }
        }
        return $ops;
    }
}
