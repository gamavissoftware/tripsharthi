<?php

declare(strict_types=1);

namespace App\Services\Ads;

/**
 * Meta campaign plans: spec -> the exact Marketing API requests. PURE (no I/O) so the request shapes are
 * contract-tested. Hierarchy: Campaign > Ad Set (budget lives here) > Creative > Ad. Everything is created PAUSED.
 */
final class MetaPlanBuilder
{
    public const KINDS = ['lead_form' => 'Lead form (Instant Form)', 'click_to_whatsapp' => 'Click-to-WhatsApp'];

    /** @return list<string> validation errors (empty = ok) */
    public static function validate(array $s): array
    {
        $e = [];
        $kind = $s['kind'] ?? '';
        if (! isset(self::KINDS[$kind])) { $e[] = 'Choose a campaign type.'; }
        if (trim((string) ($s['name'] ?? '')) === '') { $e[] = 'Give the campaign a name.'; }
        if (! preg_match('/^act_\d+$/', (string) ($s['account_id'] ?? ''))) { $e[] = 'Choose an ad account.'; }
        if (! preg_match('/^\d+$/', (string) ($s['page_id'] ?? ''))) { $e[] = 'Choose the Facebook Page the ads run as.'; }
        if ((int) ($s['daily_budget'] ?? 0) <= 0) { $e[] = 'Enter a daily budget.'; }
        $c = $s['creative'] ?? [];
        $text = trim((string) ($c['primary_text'] ?? ''));
        if ($text === '') { $e[] = 'Add the ad text.'; }
        if (mb_strlen($text) > 500) { $e[] = 'Ad text is too long (max 500 characters).'; }
        if (mb_strlen((string) ($c['headline'] ?? '')) > 40) { $e[] = 'Headline is too long (max 40 characters).'; }
        if (! preg_match('#^https://#', (string) ($c['image_url'] ?? '')) && (int) ($c['image_asset_id'] ?? 0) <= 0 && empty($c['image_hash'])) { $e[] = 'Add an image: upload one or paste a public https:// link.'; }
        if ($kind === 'lead_form' && ! preg_match('/^\d+$/', (string) ($s['lead_form_id'] ?? ''))) { $e[] = 'Choose the lead form.'; }
        if ($kind === 'click_to_whatsapp' && ! preg_match('/^\d{10,15}$/', preg_replace('/\D/', '', (string) ($s['whatsapp_number'] ?? '')))) { $e[] = 'Enter the WhatsApp number (with country code).'; }
        $t = $s['targeting'] ?? [];
        $min = (int) ($t['age_min'] ?? 18); $max = (int) ($t['age_max'] ?? 65);
        if ($min < 18 || $max > 65 || $min > $max) { $e[] = 'Age range must be between 18 and 65.'; }
        if (! empty($s['start_date']) && ! empty($s['end_date']) && $s['end_date'] < $s['start_date']) { $e[] = 'End date is before the start date.'; }
        $e = array_merge($e, self::validateSets($s));
        return $e;
    }

    public const MAX_SETS = 5;
    public const MIN_SET_BUDGET = 10_000;   // ₹100/day per ad set: below this Meta delivers almost nothing

    /** @return list<string> */
    private static function validateSets(array $s): array
    {
        $sets = $s['adsets'] ?? null;
        if ($sets === null || $sets === []) { return []; }
        $e = [];
        if (! is_array($sets) || count($sets) > self::MAX_SETS) { return ['Use at most ' . self::MAX_SETS . ' ad sets per campaign.']; }
        $shares = self::shares($s);
        if ($shares === null) { $e[] = 'The ad-set budget shares must add up to exactly 100%.'; }
        else {
            foreach ($shares as $i => $paise) { if ($paise < self::MIN_SET_BUDGET) { $e[] = 'Ad set ' . ($i + 1) . ' would get ₹' . number_format($paise / 100, 0) . ' a day — the minimum is ₹' . (self::MIN_SET_BUDGET / 100) . '. Use fewer ad sets or a bigger budget.'; } }
        }
        foreach ($sets as $i => $set) {
            $t = (array) ($set['targeting'] ?? []);
            $min = (int) ($t['age_min'] ?? 18); $max = (int) ($t['age_max'] ?? 65);
            if ($min < 18 || $max > 65 || $min > $max) { $e[] = 'Ad set ' . ($i + 1) . ': age range must be between 18 and 65.'; }
            if (mb_strlen((string) ($set['creative']['headline'] ?? '')) > 40) { $e[] = 'Ad set ' . ($i + 1) . ': headline is too long (max 40 characters).'; }
            if (isset($set['creative']['image_url']) && $set['creative']['image_url'] !== '' && ! preg_match('#^https://#', (string) $set['creative']['image_url'])) { $e[] = 'Ad set ' . ($i + 1) . ': image must be a https:// link.'; }
        }
        return $e;
    }

    /**
     * Daily budget (paise) per ad set. Percentages must be whole numbers summing to 100; with none given the budget is split equally.
     * Remainder paise go to the first set so the parts ALWAYS add up to the campaign total exactly.
     * @return list<int>|null null when the shares are invalid
     */
    public static function shares(array $s): ?array
    {
        $sets = array_values((array) ($s['adsets'] ?? []));
        $n = count($sets);
        if ($n === 0) { return [(int) $s['daily_budget']]; }
        $pcts = array_map(static fn ($x) => $x['budget_pct'] ?? null, $sets);
        $given = array_filter($pcts, static fn ($p) => $p !== null && $p !== '');
        if (count($given) === 0) { $pcts = array_fill(0, $n, 100 / $n); }
        elseif (count($given) !== $n) { return null; }
        $pcts = array_map('floatval', $pcts);
        if (abs(array_sum($pcts) - 100.0) > 0.001 || min($pcts) <= 0) { return null; }
        $total = (int) $s['daily_budget']; $out = array_map(static fn ($p) => (int) floor($total * $p / 100), $pcts);
        $out[0] += $total - array_sum($out);
        return $out;
    }

    /**
     * @return array{campaign:array,adset:array,creative:array,ad:array,sets:list<array{adset:array,creative:array,ad:array,budget:int}>}
     *   params per edge; ids are filled in at execution. adset/creative/ad are the FIRST set (kept for single-ad-set callers).
     */
    public static function plan(array $s, ?int $now = null): array
    {
        $kind = $s['kind'];
        $page = (string) $s['page_id'];
        $name = trim((string) $s['name']);

        $campaign = [
            'name' => $name, 'status' => 'PAUSED', 'special_ad_categories' => [], 'buying_type' => 'AUCTION',
            'objective' => $kind === 'lead_form' ? 'OUTCOME_LEADS' : 'OUTCOME_ENGAGEMENT',
            'is_adset_budget_sharing_enabled' => false,
        ];

        $defs = array_values((array) ($s['adsets'] ?? [])) ?: [[]];
        $budgets = self::shares($s) ?? [(int) $s['daily_budget']];
        $sets = [];
        foreach ($defs as $i => $def) {
            $label = count($defs) > 1 ? (trim((string) ($def['name'] ?? '')) ?: 'Ad set ' . ($i + 1)) : 'Ad set';
            $targeting = array_merge((array) ($s['targeting'] ?? []), (array) ($def['targeting'] ?? []));
            $c = array_merge((array) $s['creative'], array_filter((array) ($def['creative'] ?? []), static fn ($v) => $v !== null && $v !== ''));
            $adset = [
                'name' => $name . ' · ' . $label, 'status' => 'PAUSED', 'daily_budget' => (int) $budgets[$i],
                'billing_event' => 'IMPRESSIONS', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
                'targeting' => self::targeting($targeting, (array) ($s['audiences'] ?? []), (array) ($def['audiences'] ?? [])),
                'start_time' => self::time($s['start_date'] ?? null, false, $now),
            ] + ($kind === 'lead_form' ? [
                'optimization_goal' => 'LEAD_GENERATION', 'destination_type' => 'ON_AD', 'promoted_object' => ['page_id' => $page],
            ] : [
                'optimization_goal' => 'CONVERSATIONS', 'destination_type' => 'WHATSAPP',
                'promoted_object' => ['page_id' => $page, 'whatsapp_phone_number' => preg_replace('/\D/', '', (string) $s['whatsapp_number'])],
            ]);
            if (! empty($s['end_date'])) { $adset['end_time'] = self::time($s['end_date'], true, $now); }

            $link = ['message' => trim((string) $c['primary_text']), 'name' => trim((string) ($c['headline'] ?? ''))];
            // An uploaded image is referenced by the hash Meta returned for it; otherwise the public URL is used.
            if (! empty($c['image_hash'])) { $link['image_hash'] = (string) $c['image_hash']; } else { $link['picture'] = $c['image_url'] ?? ''; }
            if (! empty($c['description'])) { $link['description'] = trim((string) $c['description']); }
            if ($kind === 'lead_form') {
                $link['link'] = $c['link'] ?? "https://www.facebook.com/{$page}";
                $link['call_to_action'] = ['type' => 'SIGN_UP', 'value' => ['lead_gen_form_id' => (string) $s['lead_form_id']]];
            } else {
                $link['link'] = 'https://api.whatsapp.com/send';
                $link['call_to_action'] = ['type' => 'WHATSAPP_MESSAGE', 'value' => ['app_destination' => 'WHATSAPP']];
            }
            $creative = [
                'name' => $name . ' · Creative' . (count($defs) > 1 ? ' ' . ($i + 1) : ''),
                'object_story_spec' => ['page_id' => $page, 'link_data' => array_filter($link, static fn ($v) => $v !== '')],
                // Campaign/ad ids are substituted by Meta, so website leads carry the campaign for attribution.
                'url_tags' => 'utm_source=facebook&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}&utm_id={{campaign.id}}',
            ];
            $sets[] = ['adset' => $adset, 'creative' => $creative, 'ad' => ['name' => $name . ' · Ad' . (count($defs) > 1 ? ' ' . ($i + 1) : ''), 'status' => 'PAUSED'], 'budget' => (int) $budgets[$i]];
        }

        return ['campaign' => $campaign, 'adset' => $sets[0]['adset'], 'creative' => $sets[0]['creative'], 'ad' => $sets[0]['ad'], 'sets' => $sets];
    }

    /**
     * @param list<array{id:string,mode?:string}> $audiences campaign-wide custom audiences: ['id' => meta audience id, 'mode' => 'include'|'exclude']
     * @param list<array{id:string,mode?:string}> $extra     added for one ad set
     */
    public static function targeting(array $t, array $audiences = [], array $extra = []): array
    {
        $geo = [];
        $regions = array_values(array_filter((array) ($t['regions'] ?? []), static fn ($r) => ! empty($r['key'])));
        $cities  = array_values(array_filter((array) ($t['cities'] ?? []), static fn ($r) => ! empty($r['key'])));
        if ($regions) { $geo['regions'] = array_map(static fn ($r) => ['key' => (string) $r['key']], $regions); }
        if ($cities)  { $geo['cities'] = array_map(static fn ($r) => ['key' => (string) $r['key'], 'radius' => (int) ($r['radius'] ?? 25), 'distance_unit' => 'kilometer'], $cities); }
        if (! $geo)   { $geo['countries'] = ['IN']; }
        $out = [
            'geo_locations' => $geo, 'age_min' => (int) ($t['age_min'] ?? 21), 'age_max' => (int) ($t['age_max'] ?? 65),
            'targeting_automation' => ['advantage_audience' => 1],
        ];
        $inc = []; $exc = [];
        foreach (array_merge($audiences, $extra) as $a) {
            $id = (string) ($a['id'] ?? '');
            if (! preg_match('/^\d+$/', $id)) { continue; }
            if (($a['mode'] ?? 'include') === 'exclude') { $exc[$id] = ['id' => $id]; } else { $inc[$id] = ['id' => $id]; }
        }
        if ($inc) { $out['custom_audiences'] = array_values($inc); }
        if ($exc) { $out['excluded_custom_audiences'] = array_values($exc); }
        return $out;
    }

    private static function time(?string $date, bool $endOfDay, ?int $now): string
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $min = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz)->modify('+10 minutes');
        if (! $date) { return $min->format('c'); }
        $d = new \DateTimeImmutable($date . ($endOfDay ? ' 23:59:59' : ' 00:00:00'), $tz);
        return (! $endOfDay && $d < $min) ? $min->format('c') : $d->format('c');
    }
}
