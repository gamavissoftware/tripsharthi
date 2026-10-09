<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\WabaAccountModel;
use App\Services\Social\GraphClient;
use RuntimeException;

/**
 * Month-on-month WhatsApp spend, from Meta's own books.
 *
 * Meta bills the customer's WABA directly (§1: TravelPilot is never in the
 * cost loop), so the only truthful "what did WhatsApp marketing cost us" is
 * what Meta reports: the WABA's `pricing_analytics` edge, per-message pricing
 * since July 2025, bucketed by month × category × pricing type. Older months
 * fall back to `conversation_analytics` (conversation-based pricing).
 *
 * sync() pulls the last N months into waba_billing (idempotent upsert);
 * report() reads that table and shapes it for the Analytics page. The table
 * exists so the page is instant and so a Meta outage does not blank the
 * history the owner already saw.
 */
class MetaBillingService
{
    public const CATEGORIES = ['marketing', 'utility', 'authentication', 'service'];

    public function __construct(private ?GraphClient $graph = null, private ?int $now = null)
    {
        $this->graph ??= new GraphClient();
        $this->now   ??= time();
    }

    // ── Fetch + store ───────────────────────────────────────────────────

    /**
     * Refresh the last $months months for the tenant's active WABA.
     *
     * @return array{waba_id:string,currency:string,months:int,rows:int,source:string}
     */
    public function sync(int $tenantId, int $months = 12): array
    {
        $wabaModel = new WabaAccountModel();
        $account   = $wabaModel->findActive($tenantId);
        if (! $account) {
            throw new RuntimeException('No active WhatsApp Business Account is connected.');
        }
        $account = (array) $account;
        $token   = $wabaModel->getDecryptedToken($account);
        $wabaId  = (string) ($account['waba_id'] ?? '');
        if ($token === '' || $wabaId === '') {
            throw new RuntimeException('The connected WhatsApp account has no usable access token.');
        }

        // MONTHLY granularity only returns months that have closed — the
        // current month is silently absent — so it is read DAILY and summed.
        [$start, $end] = self::window($months, $this->now);
        $monthStart    = (int) gmmktime(0, 0, 0, (int) gmdate('n', $this->now), 1, (int) gmdate('Y', $this->now));
        $fetched       = $this->fetch($wabaId, $token, $start, $end);
        $thisMonth     = $this->fetch($wabaId, $token, $monthStart, $end, $fetched['source'] === 'conversation_analytics' ? 'conversation' : 'auto', 'DAILY');
        $currentKey    = gmdate('Y-m', $this->now);
        $closed        = array_filter($fetched['rows'],   static fn (array $r): bool => $r['month'] !== $currentKey);
        $open          = array_filter($thisMonth['rows'], static fn (array $r): bool => $r['month'] === $currentKey);
        $fetched['rows'] = self::aggregate(array_merge(array_values($closed), array_values($open)));

        $db  = db_connect();
        $now = date('Y-m-d H:i:s', $this->now);
        $n   = 0;
        foreach ($fetched['rows'] as $r) {
            $key = [
                'tenant_id' => $tenantId, 'waba_id' => $wabaId, 'month' => $r['month'],
                'category' => $r['category'], 'pricing_type' => $r['pricing_type'],
            ];
            $vals = [
                'volume' => $r['volume'], 'cost' => $r['cost'], 'currency' => $fetched['currency'],
                'source' => $fetched['source'], 'fetched_at' => $now, 'updated_at' => $now,
            ];
            $existing = $db->table('waba_billing')->where($key)->get()->getRowArray();
            if ($existing) {
                $db->table('waba_billing')->where('id', $existing['id'])->update($vals);
            } else {
                $db->table('waba_billing')->insert($key + $vals + ['created_at' => $now]);
            }
            $n++;
        }

        // Anything in the window Meta no longer reports is stale (a bucket
        // that moved month after a fix, or a corrected figure) — drop it, so
        // the table is exactly Meta's current answer for these months.
        $db->table('waba_billing')
            ->where('tenant_id', $tenantId)->where('waba_id', $wabaId)
            ->where('month >=', gmdate('Y-m', $start))
            ->where('fetched_at <', $now)
            ->delete();

        log_message('info', "MetaBillingService: tenant {$tenantId} synced {$n} billing rows for WABA {$wabaId} ({$fetched['source']}).");

        return ['waba_id' => $wabaId, 'currency' => $fetched['currency'], 'months' => $months, 'rows' => $n, 'source' => $fetched['source']];
    }

    /**
     * One Graph read. pricing_analytics first (per-message billing, the
     * current model); if Meta refuses it, conversation_analytics.
     *
     * @return array{currency:string,source:string,rows:array<int,array{month:string,category:string,pricing_type:string,volume:int,cost:float}>}
     */
    public function fetch(string $wabaId, string $token, int $start, int $end, string $edge = 'auto', string $granularity = 'MONTHLY'): array
    {
        $currency = 'INR';
        try {
            $meta     = $this->graph->get($wabaId, ['fields' => 'currency', 'access_token' => $token]);
            $currency = (string) ($meta['currency'] ?? 'INR');
        } catch (\Throwable $e) {
            // Currency is cosmetic; the numbers still matter.
        }

        try {
            if ($edge === 'conversation') {
                throw new RuntimeException('conversation_analytics requested explicitly');
            }
            $resp = $this->graph->get($wabaId, [
                'fields'       => "pricing_analytics.start({$start}).end({$end}).granularity({$granularity})"
                                . '.metric_types(["COST","VOLUME"]).dimensions(["PRICING_CATEGORY","PRICING_TYPE"])',
                'access_token' => $token,
            ]);
            $rows = self::parse($resp['pricing_analytics'] ?? [], 'pricing');

            return ['currency' => $currency, 'source' => 'pricing_analytics', 'rows' => $rows];
        } catch (\Throwable $e) {
            log_message('warning', 'MetaBillingService: pricing_analytics refused (' . $e->getMessage() . '); trying conversation_analytics.');
        }

        $resp = $this->graph->get($wabaId, [
            'fields'       => "conversation_analytics.start({$start}).end({$end}).granularity({$granularity})"
                            . '.dimensions(["CONVERSATION_CATEGORY","CONVERSATION_TYPE"]).metric_types(["COST","CONVERSATION"])',
            'access_token' => $token,
        ]);

        return ['currency' => $currency, 'source' => 'conversation_analytics', 'rows' => self::parse($resp['conversation_analytics'] ?? [], 'conversation')];
    }

    /**
     * Flatten Meta's data_points into month rows. Both edges share the shape
     * {data:[{data_points:[{start,end,cost,volume|conversation,…}]}]}.
     *
     * @param array<string,mixed> $edge
     * @return array<int,array{month:string,category:string,pricing_type:string,volume:int,cost:float}>
     */
    public static function parse(array $edge, string $kind): array
    {
        $rows = [];
        foreach ((array) ($edge['data'] ?? []) as $block) {
            foreach ((array) (((array) $block)['data_points'] ?? []) as $p) {
                $p     = (array) $p;
                $start = (int) ($p['start'] ?? 0);
                if ($start <= 0) {
                    continue;
                }
                $category = strtolower((string) ($p['pricing_category'] ?? $p['conversation_category'] ?? 'unknown'));
                $type     = strtolower((string) ($p['pricing_type'] ?? $p['conversation_type'] ?? 'regular'));
                $rows[]   = [
                    // Meta buckets in the WABA's timezone; +12h keeps a
                    // bucket starting at local midnight inside its own month/day.
                    'month'        => gmdate('Y-m', $start + 43200),
                    'category'     => in_array($category, self::CATEGORIES, true) ? $category : 'unknown',
                    'pricing_type' => $type === '' ? 'regular' : $type,
                    'volume'       => (int) ($p['volume'] ?? $p['conversation'] ?? 0),
                    'cost'         => round((float) ($p['cost'] ?? 0), 4),
                ];
            }
        }

        return $rows;
    }

    /**
     * Sum rows sharing month × category × pricing type — daily buckets into
     * their month.
     *
     * @param array<int,array{month:string,category:string,pricing_type:string,volume:int,cost:float}> $rows
     * @return array<int,array{month:string,category:string,pricing_type:string,volume:int,cost:float}>
     */
    public static function aggregate(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = $r['month'] . '|' . $r['category'] . '|' . $r['pricing_type'];
            $out[$k] ??= ['month' => $r['month'], 'category' => $r['category'], 'pricing_type' => $r['pricing_type'], 'volume' => 0, 'cost' => 0.0];
            $out[$k]['volume'] += $r['volume'];
            $out[$k]['cost']    = round($out[$k]['cost'] + $r['cost'], 4);
        }

        return array_values($out);
    }

    /** [start, end] unix: the first day of the month $months-1 months ago → now. */
    public static function window(int $months, int $now): array
    {
        // Meta answers "Invalid parameter" to a window longer than a year.
        $months = max(1, min(12, $months));
        $first  = (int) gmmktime(0, 0, 0, (int) gmdate('n', $now) - ($months - 1), 1, (int) gmdate('Y', $now));

        return [$first, $now];
    }

    // ── Report ──────────────────────────────────────────────────────────

    /**
     * Month-on-month table for the Analytics page.
     *
     * @return array<string,mixed>
     */
    public function report(int $tenantId, int $months = 12): array
    {
        $months = max(1, min(12, $months));
        [$start] = self::window($months, $this->now);
        $fromMonth = gmdate('Y-m', $start);

        $db   = db_connect();
        $rows = $db->table('waba_billing')
            ->where('tenant_id', $tenantId)->where('month >=', $fromMonth)
            ->orderBy('month', 'ASC')->get()->getResultArray();

        $currency  = 'INR';
        $fetchedAt = null;
        $source    = null;
        $byMonth   = [];
        foreach ($rows as $r) {
            $currency  = $r['currency'] ?: $currency;
            $fetchedAt = max((string) $fetchedAt, (string) $r['fetched_at']);
            $source    = $r['source'] ?: $source;
            $m         = $r['month'];
            $byMonth[$m] ??= self::emptyMonth($m);
            $cat  = $r['category'];
            $free = str_starts_with((string) $r['pricing_type'], 'free');
            $byMonth[$m]['cost']   += (float) $r['cost'];
            $byMonth[$m]['volume'] += (int) $r['volume'];
            $byMonth[$m]['by_category'][$cat] ??= ['cost' => 0.0, 'volume' => 0];
            $byMonth[$m]['by_category'][$cat]['cost']   += (float) $r['cost'];
            $byMonth[$m]['by_category'][$cat]['volume'] += (int) $r['volume'];
            if ($free) {
                $byMonth[$m]['free_volume'] += (int) $r['volume'];
            }
        }

        // Every month in the window appears, even a silent one, so the chart
        // does not quietly skip a month with nothing billed.
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $m = gmdate('Y-m', (int) gmmktime(0, 0, 0, (int) gmdate('n', $start) + $i, 1, (int) gmdate('Y', $start)));
            if ($m > gmdate('Y-m', $this->now)) {
                break;
            }
            $out[] = $byMonth[$m] ?? self::emptyMonth($m);
        }

        // What TravelPilot itself sent each month, so a gap between the two is
        // visible (messages sent from elsewhere on the same WABA, or a stale sync).
        $sent  = [];
        $monthExpr = $db->DBDriver === 'SQLite3' ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')";
        foreach ($db->table('messages')
            ->select("{$monthExpr} AS m, COUNT(*) AS n, SUM(billable) AS b", false)
            ->where('tenant_id', $tenantId)->where('direction', 'out')
            ->where("created_at >= '" . gmdate('Y-m-01', $start) . "'", null, false)
            ->groupBy('m')->get()->getResultArray() as $r) {
            $sent[$r['m']] = ['sent' => (int) $r['n'], 'billable' => (int) $r['b']];
        }
        foreach ($out as &$m) {
            $m['travelpilot_sent']     = $sent[$m['month']]['sent'] ?? 0;
            $m['travelpilot_billable'] = $sent[$m['month']]['billable'] ?? 0;
            $m['cost']               = round($m['cost'], 2);
            foreach ($m['by_category'] as &$c) {
                $c['cost'] = round($c['cost'], 2);
            }
            unset($c);
        }
        unset($m);

        $n       = count($out);
        $this_   = $n ? $out[$n - 1] : self::emptyMonth(gmdate('Y-m', $this->now));
        $last    = $n > 1 ? $out[$n - 2] : null;
        $change  = ($last && $last['cost'] > 0) ? round((($this_['cost'] - $last['cost']) / $last['cost']) * 100, 1) : null;

        return [
            'currency'   => $currency,
            'source'     => $source,
            'synced_at'  => $fetchedAt,
            'months'     => $out,
            'totals'     => [
                'cost'   => round(array_sum(array_column($out, 'cost')), 2),
                'volume' => array_sum(array_column($out, 'volume')),
            ],
            'this_month' => $this_,
            'last_month' => $last,
            'mom_change_pct' => $change,
        ];
    }

    /** @return array<string,mixed> */
    private static function emptyMonth(string $m): array
    {
        return ['month' => $m, 'cost' => 0.0, 'volume' => 0, 'free_volume' => 0, 'by_category' => []];
    }
}
