<?php

declare(strict_types=1);

namespace App\Services\Ads;

/**
 * Stateful simulation of the Meta Marketing API and Google Ads API for local development and demos
 * (ADS_MOCK_MODE=true). Objects persist in writable/ads-mock-{tenant}.json so create -> list -> pause -> budget
 * behave like the real thing. It mimics the REQUEST SHAPES we send; it does not replace the real-API check
 * with an actual ad account (see docs).
 */
final class AdsMockHttp
{
    public static function enabled(): bool
    {
        return filter_var(env('ADS_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function handler(int $tenantId): \Closure
    {
        return static fn (string $method, string $url, array $opt): array => (new self($tenantId))->handle($method, $url, $opt);
    }

    private string $file;
    private array $db;

    public function __construct(int $tenantId)
    {
        $this->file = WRITEPATH . "ads-mock-{$tenantId}.json";
        $this->db = is_file($this->file) ? (json_decode((string) file_get_contents($this->file), true) ?: []) : [];
        $this->db += ['seq' => 1000, 'meta' => [], 'google' => ['campaigns' => [], 'budgets' => []]];
    }

    private function save(): void { file_put_contents($this->file, json_encode($this->db)); }
    private function id(): string { return (string) ++$this->db['seq']; }
    private static function ok(array $b): array { return ['status' => 200, 'body' => json_encode($b)]; }
    private static function err(int $status, array $b): array { return ['status' => $status, 'body' => json_encode($b)]; }

    public function handle(string $method, string $url, array $opt): array
    {
        $u = parse_url($url);
        $path = (string) ($u['path'] ?? '');
        if (str_contains($url, 'oauth2.googleapis.com')) { return self::ok(['access_token' => 'mock-access']); }
        if (str_contains($url, 'googleads.googleapis.com')) { return $this->google($path, $opt['json'] ?? []); }
        $path = preg_replace('#^/v[\d.]+/#', '', $path);
        parse_str((string) ($u['query'] ?? ''), $q);
        $params = $method === 'GET' ? $q : ($opt['json'] ?? []);
        return $this->meta($method, $path, $params);
    }

    // ---- Meta -------------------------------------------------------------------------------------------

    private function meta(string $method, string $path, array $p): array
    {
        $m = &$this->db['meta'];
        if ($method === 'GET' && $path === 'me/permissions') {
            return self::ok(['data' => array_map(static fn ($x) => ['permission' => $x, 'status' => 'granted'], ['ads_read', 'ads_management', 'pages_show_list', 'pages_manage_ads', 'leads_retrieval'])]);
        }
        if ($method === 'GET' && $path === 'me/accounts') { return self::ok(['data' => [['id' => '1100001', 'name' => 'Demo Travels', 'tasks' => ['ADVERTISE']]]]); }
        if ($method === 'GET' && $path === 'me/adaccounts') { return self::ok(['data' => [['id' => 'act_1000000001', 'name' => 'Demo Travels Ads', 'currency' => 'INR', 'account_status' => 1]]]); }
        if ($method === 'GET' && str_ends_with($path, '/leadgen_forms')) {
            return self::ok(['data' => [['id' => '9001', 'name' => 'Honeymoon enquiry form', 'status' => 'ACTIVE'], ['id' => '9002', 'name' => 'Family holiday enquiry', 'status' => 'ACTIVE']]]);
        }
        if ($method === 'GET' && $path === 'search') {
            $all = [['key' => '2418956', 'name' => 'Mumbai', 'type' => 'city', 'region' => 'Maharashtra'], ['key' => '2420163', 'name' => 'Delhi', 'type' => 'city', 'region' => 'Delhi'],
                    ['key' => '2418265', 'name' => 'Bengaluru', 'type' => 'city', 'region' => 'Karnataka'], ['key' => '2419192', 'name' => 'Pune', 'type' => 'city', 'region' => 'Maharashtra'],
                    ['key' => '2419557', 'name' => 'Ahmedabad', 'type' => 'city', 'region' => 'Gujarat'], ['key' => '2418730', 'name' => 'Hyderabad', 'type' => 'city', 'region' => 'Telangana']];
            $needle = mb_strtolower((string) ($p['q'] ?? ''));
            return self::ok(['data' => array_values(array_filter($all, static fn ($c) => $needle === '' || str_contains(mb_strtolower($c['name']), $needle)))]);
        }
        if ($method === 'GET' && preg_match('#^(act_\d+)$#', $path)) { return self::ok(['id' => $path, 'name' => 'Demo Travels Ads', 'currency' => 'INR', 'account_status' => 1]); }
        if ($method === 'GET' && preg_match('#^act_\d+/(campaigns|adsets)$#', $path, $x)) {
            $rows = array_values(array_filter($m['objects'] ?? [], static fn ($o) => $o['_edge'] === $x[1]));
            return self::ok(['data' => array_map(static fn ($o) => array_diff_key($o, ['_edge' => 1]), $rows)]);
        }
        if ($method === 'GET' && preg_match('#^act_\d+/ads$#', $path)) {
            $rows = array_values(array_filter($m['objects'] ?? [], static fn ($o) => $o['_edge'] === 'ads' && in_array($o['effective_status'] ?? '', ['DISAPPROVED', 'WITH_ISSUES'], true)));
            $objs = $m['objects'] ?? [];
            // the real API returns campaign_id when asked for it; here it is derived from the ad's ad set
            return self::ok(['data' => array_map(static fn ($o) => array_diff_key($o + ['campaign_id' => $objs[$o['adset_id'] ?? '']['campaign_id'] ?? ''], ['_edge' => 1]), $rows)]);
        }
        if ($method === 'GET' && preg_match('#^act_\d+/insights$#', $path)) { return self::ok(['data' => $this->metaInsights()]); }
        if ($method === 'GET' && preg_match('#^(\d+)/adsets$#', $path, $x)) {
            return self::ok(['data' => array_values(array_map(static fn ($o) => ['id' => $o['id']], array_filter($m['objects'] ?? [], static fn ($o) => $o['_edge'] === 'adsets' && ($o['campaign_id'] ?? '') === $x[1])))]);
        }
        if ($method === 'POST' && preg_match('#^act_\d+/customaudiences$#', $path)) {
            if (empty($p['name']) || empty($p['subtype'])) { return self::err(400, ['error' => ['code' => 100, 'message' => 'Param name and subtype are required.']]); }
            if (($p['subtype'] ?? '') === 'LOOKALIKE') {
                $seed = $m['audiences'][$p['origin_audience_id'] ?? ''] ?? null;
                if (! $seed || count($seed['members']) < 100) { return self::err(400, ['error' => ['code' => 2654, 'message' => 'The seed audience needs at least 100 people.', 'error_user_msg' => 'The source audience must have at least 100 people matched.']]); }
            }
            $id = $this->id(); $m['audiences'][$id] = ['id' => $id, 'name' => $p['name'], 'subtype' => $p['subtype'], 'members' => []]; $this->save();
            return self::ok(['id' => $id]);
        }
        if ($method === 'POST' && preg_match('#^(\d+)/users$#', $path, $x) && isset($m['audiences'][$x[1]])) {
            $pl = $p['payload'] ?? []; $n = 0;
            foreach ((array) ($pl['data'] ?? []) as $row) { $m['audiences'][$x[1]]['members'][md5(json_encode($row))] = true; $n++; }
            $this->save();
            return self::ok(['audience_id' => $x[1], 'num_received' => $n, 'num_invalid_entries' => 0]);
        }
        if ($method === 'DELETE' && preg_match('#^(\d+)/users$#', $path, $x) && isset($m['audiences'][$x[1]])) {
            $n = 0;
            foreach ((array) (($p['payload'] ?? [])['data'] ?? []) as $row) { unset($m['audiences'][$x[1]]['members'][md5(json_encode($row))]); $n++; }
            $this->save();
            return self::ok(['audience_id' => $x[1], 'num_received' => $n]);
        }
        if ($method === 'GET' && preg_match('#^(\d+)$#', $path, $x) && isset($m['audiences'][$x[1]])) {
            $a = $m['audiences'][$x[1]]; $c = count($a['members']);
            return self::ok(['id' => $a['id'], 'name' => $a['name'], 'subtype' => $a['subtype'], 'approximate_count_lower_bound' => (int) floor($c * 0.7), 'approximate_count_upper_bound' => $c, 'operation_status' => ['code' => 200, 'description' => 'Normal']]);
        }
        if ($method === 'POST' && preg_match('#^act_\d+/adimages$#', $path)) {
            if (empty($p['bytes'])) { return self::err(400, ['error' => ['code' => 100, 'message' => 'Param bytes is required.']]); }
            $hash = md5((string) $p['bytes']); $m['images'][$hash] = true; $this->save();
            return self::ok(['images' => ['bytes' => ['hash' => $hash, 'url' => 'https://mock.facebook.com/' . $hash]]]);
        }
        if ($method === 'POST' && preg_match('#^act_\d+/(campaigns|adsets|adcreatives|ads)$#', $path, $x)) {
            if (in_array('validate_only', (array) ($p['execution_options'] ?? []), true)) {
                if ($x[1] === 'campaigns' && ! isset($p['special_ad_categories'])) { return self::err(400, ['error' => ['code' => 100, 'message' => 'Param special_ad_categories must be specified.']]); }
                return self::ok(['success' => true]);
            }
            $id = $this->id();
            $o = ['id' => $id, '_edge' => $x[1]] + array_diff_key($p, ['access_token' => 1]);
            $o += ['effective_status' => $o['status'] ?? 'PAUSED'];
            $m['objects'][$id] = $o;
            $this->save();
            return self::ok(['id' => $id]);
        }
        if ($method === 'POST' && preg_match('#^(\d+)$#', $path, $x) && isset($m['objects'][$x[1]])) {
            foreach (['status', 'daily_budget', 'name'] as $k) {
                if (isset($p[$k])) { $m['objects'][$x[1]][$k] = $p[$k]; }
            }
            if (isset($p['status'])) { $m['objects'][$x[1]]['effective_status'] = $p['status']; }
            $this->save();
            return self::ok(['success' => true]);
        }
        if ($method === 'DELETE' && preg_match('#^(\d+)$#', $path, $x) && isset($m['audiences'][$x[1]])) { unset($m['audiences'][$x[1]]); $this->save(); return self::ok(['success' => true]); }
        if ($method === 'DELETE' && preg_match('#^(\d+)$#', $path, $x)) { unset($m['objects'][$x[1]]); $this->save(); return self::ok(['success' => true]); }
        return self::err(400, ['error' => ['code' => 100, 'message' => "Mock Meta: unsupported {$method} /{$path}"]]);
    }

    /** Test/demo helper: flag a mock ad as disapproved on Meta. */
    public function flagMetaAd(string $adId, string $status = 'DISAPPROVED', array $feedback = ['global' => ['Misleading claims' => 'The ad makes a guarantee it cannot back up.']]): void
    {
        if (isset($this->db['meta']['objects'][$adId])) { $this->db['meta']['objects'][$adId]['effective_status'] = $status; $this->db['meta']['objects'][$adId]['ad_review_feedback'] = $feedback; $this->save(); }
    }

    private function metaInsights(): array
    {
        $rows = [];
        foreach ($this->db['meta']['objects'] ?? [] as $o) {
            if ($o['_edge'] !== 'campaigns' || ($o['status'] ?? '') !== 'ACTIVE') { continue; }
            $budget = 0;
            foreach ($this->db['meta']['objects'] as $a) { if ($a['_edge'] === 'adsets' && ($a['campaign_id'] ?? '') === $o['id']) { $budget += (int) ($a['daily_budget'] ?? 0); } }
            for ($d = 6; $d >= 0; $d--) {
                $spend = round($budget / 100 * (0.8 + (crc32($o['id'] . $d) % 20) / 100), 2);
                $leads = (int) floor($spend / (120 + crc32($o['id']) % 80));
                $rows[] = ['campaign_id' => $o['id'], 'date_start' => date('Y-m-d', strtotime("-{$d} days")), 'spend' => (string) $spend, 'impressions' => (string) (int) ($spend * 90), 'clicks' => (string) (int) ($spend / 6),
                    'actions' => [['action_type' => 'lead', 'value' => (string) $leads]]];
            }
        }
        return $rows;
    }

    /** Mimic Google's PMax asset-group requirements so a bad spec fails the dry run, not just the real account. */
    private function pmaxProblem(array $ops): ?string
    {
        $isPmax = false; $n = [];
        foreach ($ops as $op) {
            if (($op['campaignOperation']['create']['advertisingChannelType'] ?? '') === 'PERFORMANCE_MAX') { $isPmax = true; }
            if (isset($op['assetGroupAssetOperation'])) { $f = $op['assetGroupAssetOperation']['create']['fieldType']; $n[$f] = ($n[$f] ?? 0) + 1; }
            if (isset($op['assetOperation']['create']['imageAsset']) && trim((string) ($op['assetOperation']['create']['imageAsset']['data'] ?? '')) === '') { return 'Image asset data is empty.'; }
        }
        if (! $isPmax) { return null; }
        foreach (['HEADLINE' => 3, 'LONG_HEADLINE' => 1, 'DESCRIPTION' => 2, 'BUSINESS_NAME' => 1, 'MARKETING_IMAGE' => 1, 'SQUARE_MARKETING_IMAGE' => 1] as $f => $min) {
            if (($n[$f] ?? 0) < $min) { return "Asset group needs at least {$min} {$f} asset(s)."; }
        }
        return null;
    }

    /** Mimic Google's Demand Gen requirements (multi-asset image ad + ad group channel controls) so a bad spec fails the dry run. */
    private function demandGenProblem(array $ops): ?string
    {
        $dg = false; $ad = null; $group = null; $camp = null;
        foreach ($ops as $op) {
            if (($op['campaignOperation']['create']['advertisingChannelType'] ?? '') === 'DEMAND_GEN') { $dg = true; $camp = $op['campaignOperation']['create']; }
            if (isset($op['adGroupOperation'])) { $group = $op['adGroupOperation']['create']; }
            if (isset($op['adGroupAdOperation']['create']['ad']['demandGenMultiAssetAd'])) { $ad = $op['adGroupAdOperation']['create']['ad']; }
        }
        if (! $dg) { return null; }
        if (($camp['status'] ?? '') !== 'PAUSED') { return 'Demand Gen campaign must be created paused.'; }
        if (empty($camp['containsEuPoliticalAdvertising'])) { return 'containsEuPoliticalAdvertising is required.'; }
        $cc = $group['demandGenAdGroupSettings']['channelControls'] ?? null;
        if (! $cc) { return 'Demand Gen ad group needs channel controls.'; }
        if (isset($cc['selectedChannels']) && ! in_array(true, array_values((array) $cc['selectedChannels']), true)) { return 'At least one channel must be selected.'; }
        if ($ad === null) { return 'Demand Gen campaign needs a multi-asset ad.'; }
        $m = $ad['demandGenMultiAssetAd'];
        if (empty($ad['finalUrls'])) { return 'Ad needs a final URL.'; }
        if (empty($m['businessName']) || mb_strlen((string) $m['businessName']) > 25) { return 'Business name is required (max 25).'; }
        foreach (['headlines' => [1, 5, 30], 'descriptions' => [1, 5, 90]] as $f => [$min, $max, $len]) {
            $n = count((array) ($m[$f] ?? []));
            if ($n < $min || $n > $max) { return "Demand Gen ad needs {$min} to {$max} {$f}."; }
            foreach ($m[$f] as $t) { if (mb_strlen((string) ($t['text'] ?? '')) > $len) { return "A {$f} item is longer than {$len} characters."; } }
        }
        if (count((array) ($m['logoImages'] ?? [])) < 1) { return 'Demand Gen ad needs at least one logo image.'; }
        if (count((array) ($m['marketingImages'] ?? [])) + count((array) ($m['squareMarketingImages'] ?? [])) < 1) { return 'Demand Gen ad needs a landscape or a square marketing image.'; }
        $total = 0; foreach (['marketingImages', 'squareMarketingImages', 'portraitMarketingImages', 'tallPortraitMarketingImages'] as $f) { $total += count((array) ($m[$f] ?? [])); }
        if ($total > 20) { return 'Too many marketing images (max 20).'; }
        return null;
    }

    // ---- Google -----------------------------------------------------------------------------------------

    private function google(string $path, array $j): array
    {
        $g = &$this->db['google'];
        if (str_ends_with($path, 'googleAds:search')) {
            $q = (string) ($j['query'] ?? '');
            if (str_contains($q, 'FROM customer')) { return self::ok(['results' => [['customer' => ['currencyCode' => 'INR', 'status' => 'ENABLED', 'descriptiveName' => 'Demo Travels']]]]); }
            if (str_contains($q, 'conversion_action')) { return self::ok(['results' => []]); }
            if (str_contains($q, 'policy_summary')) { return self::ok(['results' => $g['policy'] ?? []]); }
            if (str_contains($q, 'metrics.cost_micros')) {
                $rows = [];
                foreach ($g['campaigns'] as $c) {
                    if ($c['status'] !== 'ENABLED') { continue; }
                    $micros = (int) ($g['budgets'][$c['budget']]['amountMicros'] ?? 0);
                    for ($d = 6; $d >= 0; $d--) {
                        $cost = (int) ($micros * (0.8 + (crc32($c['id'] . $d) % 20) / 100));
                        $rows[] = ['campaign' => ['id' => $c['id']], 'segments' => ['date' => date('Y-m-d', strtotime("-{$d} days"))],
                            'metrics' => ['costMicros' => (string) $cost, 'impressions' => (string) (int) ($cost / 20000), 'clicks' => (string) (int) ($cost / 6_000_000), 'conversions' => (float) floor($cost / 1_000_000 / 180)]];
                    }
                }
                return self::ok(['results' => $rows]);
            }
            return self::ok(['results' => array_values(array_map(static fn ($c) => ['campaign' => ['id' => $c['id'], 'name' => $c['name'], 'status' => $c['status'], 'advertisingChannelType' => $c['type'] ?? 'SEARCH', 'campaignBudget' => $c['budget']],
                'campaignBudget' => ['amountMicros' => $g['budgets'][$c['budget']]['amountMicros'] ?? '0', 'explicitlyShared' => false], 'customer' => ['currencyCode' => 'INR']], $g['campaigns']))]);
        }
        if (str_ends_with($path, 'googleAds:mutate')) {
            if ($bad = $this->pmaxProblem((array) ($j['mutateOperations'] ?? []))) { return self::err(400, ['error' => ['code' => 400, 'message' => $bad, 'status' => 'INVALID_ARGUMENT']]); }
            if ($bad = $this->demandGenProblem((array) ($j['mutateOperations'] ?? []))) { return self::err(400, ['error' => ['code' => 400, 'message' => $bad, 'status' => 'INVALID_ARGUMENT']]); }
            if (! empty($j['validateOnly'])) { return self::ok([]); }
            $resp = []; $tmp = [];
            foreach ($j['mutateOperations'] as $op) {
                if (isset($op['campaignBudgetOperation'])) {
                    $c = $op['campaignBudgetOperation']['create']; $id = $this->id(); $rn = preg_replace('#/-\d+$#', "/{$id}", $c['resourceName']);
                    $tmp[$c['resourceName']] = $rn; $g['budgets'][$rn] = ['amountMicros' => $c['amountMicros']]; $resp[] = ['campaignBudgetResult' => ['resourceName' => $rn]];
                } elseif (isset($op['campaignOperation'])) {
                    $c = $op['campaignOperation']['create']; $id = $this->id(); $rn = preg_replace('#/-\d+$#', "/{$id}", $c['resourceName']);
                    $tmp[$c['resourceName']] = $rn; $g['campaigns'][$id] = ['id' => $id, 'name' => $c['name'], 'status' => $c['status'], 'type' => $c['advertisingChannelType'] ?? 'SEARCH', 'budget' => $tmp[$c['campaignBudget']] ?? $c['campaignBudget']];
                    $resp[] = ['campaignResult' => ['resourceName' => $rn]];
                } elseif (isset($op['adGroupOperation'])) {
                    $c = $op['adGroupOperation']['create']; $id = $this->id(); $rn = preg_replace('#/-\d+$#', "/{$id}", $c['resourceName']);
                    $tmp[$c['resourceName']] = $rn; $resp[] = ['adGroupResult' => ['resourceName' => $rn]];
                } elseif (isset($op['adGroupAdOperation'])) {
                    $resp[] = ['adGroupAdResult' => ['resourceName' => 'customers/x/adGroupAds/' . $this->id()]];
                } elseif (isset($op['assetGroupOperation'])) {
                    $c = $op['assetGroupOperation']['create']; $id = $this->id(); $rn = preg_replace('#/-\d+$#', "/{$id}", $c['resourceName']);
                    $tmp[$c['resourceName']] = $rn; $resp[] = ['assetGroupResult' => ['resourceName' => $rn]];
                } else { $resp[] = []; }
            }
            $this->save();
            return self::ok(['mutateOperationResponses' => $resp]);
        }
        if (str_ends_with($path, 'campaigns:mutate')) {
            foreach ($j['operations'] as $op) { $id = substr($op['update']['resourceName'], (int) strrpos($op['update']['resourceName'], '/') + 1); if (isset($g['campaigns'][$id])) { $g['campaigns'][$id]['status'] = $op['update']['status']; } }
            $this->save(); return self::ok(['results' => []]);
        }
        if (str_ends_with($path, 'campaignBudgets:mutate')) {
            foreach ($j['operations'] as $op) { $g['budgets'][$op['update']['resourceName']]['amountMicros'] = $op['update']['amountMicros']; }
            $this->save(); return self::ok(['results' => []]);
        }
        if (str_ends_with($path, 'geoTargetConstants:suggest')) {
            return self::ok(['geoTargetConstantSuggestions' => [['geoTargetConstant' => ['resourceName' => 'geoTargetConstants/9040210', 'canonicalName' => 'Mumbai,Maharashtra,India', 'targetType' => 'City']]]]);
        }
        return self::err(400, ['error' => ['code' => 400, 'message' => "Mock Google: unsupported {$path}", 'status' => 'INVALID_ARGUMENT']]);
    }
}
