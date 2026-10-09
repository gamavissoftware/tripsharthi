<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\IntegrationModel;
use App\Models\SocialOauthStateModel;
use App\Services\Social\GraphClient;
use App\Services\WhatsApp\TokenCipher;
use RuntimeException;

/**
 * Meta ad spend (Facebook / Instagram ads), month on month, from the
 * Marketing API's account-level insights.
 *
 * Needs a USER token with ads_read — a Page token cannot read insights —
 * so the Facebook Login flow (return_to=ads) hands its long-lived user token
 * here instead of discarding it, and it is kept encrypted on an
 * integrations row of type 'meta_ads' together with the chosen accounts.
 *
 *   connect()   — take the user token from a finished login attempt
 *   accounts()  — the ad accounts that token can see (for the picker)
 *   select()    — which of them to report on
 *   sync()      — pull the last N months into ad_spend (replace-the-window)
 *   report()    — shape for the Analytics page
 */
class MetaAdsService
{
    public const TYPE = 'meta_ads';

    /**
     * Meta reports the same lead under several action types ("lead" is the
     * total; "leadgen_grouped" / "onsite_conversion.lead_grouped" are the
     * on-platform subset), so they are NOT summed — the largest is taken.
     * Messaging conversations are not leads and are left out.
     */
    private const LEAD_ACTIONS = ['lead', 'onsite_conversion.lead_grouped', 'leadgen_grouped'];

    public function __construct(private ?GraphClient $graph = null, private ?int $now = null)
    {
        $this->graph ??= new GraphClient();
        $this->now   ??= time();
    }

    // ── Connection ──────────────────────────────────────────────────────

    /**
     * Store the login attempt's long-lived user token and consume the state.
     *
     * @return array<string,mixed> connection status (no token)
     */
    public function connect(string $state, int $tenantId, int $userId): array
    {
        $states = new SocialOauthStateModel();
        $row    = $states->setTenant($tenantId)->where('state', $state)->first();
        if (! $row) {
            throw new RuntimeException('Unknown connection attempt.');
        }
        $row = (array) $row;
        if ($row['status'] !== 'ready') {
            throw new RuntimeException($row['status'] === 'failed'
                ? (string) ($row['error'] ?: 'The Facebook connection attempt failed.')
                : 'This connection attempt is no longer valid. Start again.');
        }
        $token = $states->getDecryptedUserToken($row);
        if ($token === '') {
            throw new RuntimeException('The Facebook session for this attempt has expired. Start again.');
        }

        $existing = (new IntegrationModel())->findActiveByType($tenantId, self::TYPE);
        $config   = $existing ? (json_decode((string) ((array) $existing)['config'], true) ?: []) : [];
        $config['user_token_enc'] = TokenCipher::encrypt($token);
        $config['connected_by']   = $userId;
        $config['connected_at']   = date('Y-m-d H:i:s', $this->now);
        $config['ad_accounts']  ??= [];

        (new IntegrationModel())->saveConfig($tenantId, self::TYPE, ['config' => json_encode($config)]);

        $states->withoutTenantScope()->update((int) $row['id'], [
            'status' => 'consumed', 'user_token_enc' => null, 'pages_json' => null,
        ]);

        return $this->status($tenantId);
    }

    /** @return array<string,mixed> */
    public function status(int $tenantId): array
    {
        $row = (new IntegrationModel())->findActiveByType($tenantId, self::TYPE);
        if (! $row) {
            return ['connected' => false, 'ad_accounts' => [], 'connected_at' => null];
        }
        $config = json_decode((string) ((array) $row)['config'], true) ?: [];

        return [
            'connected'    => ! empty($config['user_token_enc']),
            'ad_accounts'  => array_values((array) ($config['ad_accounts'] ?? [])),
            'connected_at' => $config['connected_at'] ?? null,
            'last_sync_at' => $config['last_sync_at'] ?? null,
            'last_error'   => $config['last_error'] ?? null,
        ];
    }

    /**
     * Ad accounts the user token can read.
     *
     * @return array<int,array{id:string,name:string,currency:string,status:int,amount_spent:string}>
     */
    public function accounts(int $tenantId): array
    {
        $token = $this->token($tenantId);
        $resp  = $this->graph->get('me/adaccounts', [
            'fields'       => 'id,name,currency,account_status,amount_spent',
            'limit'        => 100,
            'access_token' => $token,
        ]);
        $out = [];
        foreach ((array) ($resp['data'] ?? []) as $a) {
            $a     = (array) $a;
            $out[] = [
                'id'           => (string) ($a['id'] ?? ''),
                'name'         => (string) ($a['name'] ?? ''),
                'currency'     => (string) ($a['currency'] ?? ''),
                'status'       => (int) ($a['account_status'] ?? 0),
                'amount_spent' => (string) ($a['amount_spent'] ?? '0'),
            ];
        }

        return $out;
    }

    /**
     * Choose the accounts to report on (id + name pairs from accounts()).
     *
     * @param array<int,array{id:string,name?:string,currency?:string}> $accounts
     */
    public function select(int $tenantId, array $accounts): array
    {
        $row = (new IntegrationModel())->findActiveByType($tenantId, self::TYPE);
        if (! $row) {
            throw new RuntimeException('Connect Meta Ads with Facebook first.');
        }
        $config = json_decode((string) ((array) $row)['config'], true) ?: [];
        $clean  = [];
        foreach ($accounts as $a) {
            $a  = (array) $a;
            $id = trim((string) ($a['id'] ?? ''));
            if ($id === '' || ! preg_match('/^act_\d+$/', $id)) {
                continue;
            }
            $clean[] = ['id' => $id, 'name' => (string) ($a['name'] ?? $id), 'currency' => (string) ($a['currency'] ?? '')];
        }
        $config['ad_accounts'] = $clean;
        (new IntegrationModel())->saveConfig($tenantId, self::TYPE, ['config' => json_encode($config)]);
        $q = db_connect()->table('ad_spend')->where('tenant_id', $tenantId);
        if ($clean !== []) {
            $q->whereNotIn('ad_account_id', array_column($clean, 'id'));
        }
        $q->delete();

        return $this->status($tenantId);
    }

    public function disconnect(int $tenantId): void
    {
        $model = new IntegrationModel();
        $row   = $model->findActiveByType($tenantId, self::TYPE);
        if ($row) {
            $model->setTenant($tenantId)->delete((int) ((array) $row)['id']);
        }
    }

    // ── Sync ────────────────────────────────────────────────────────────

    /**
     * Pull monthly account-level insights for every chosen account.
     *
     * @return array{accounts:int,rows:int,months:int}
     */
    public function sync(int $tenantId, int $months = 12): array
    {
        $months = max(1, min(24, $months));
        $status = $this->status($tenantId);
        if (! $status['connected']) {
            throw new RuntimeException('Meta Ads is not connected.');
        }
        if ($status['ad_accounts'] === []) {
            throw new RuntimeException('No ad account selected yet.');
        }
        $token = $this->token($tenantId);
        [$since, $until] = self::window($months, $this->now);

        $db   = db_connect();
        $now  = date('Y-m-d H:i:s', $this->now);
        $n    = 0;
        $errs = [];
        foreach ($status['ad_accounts'] as $acct) {
            try {
                $resp = $this->graph->get($acct['id'] . '/insights', [
                    'level'          => 'account',
                    'time_increment' => 'monthly',
                    'time_range'     => json_encode(['since' => $since, 'until' => $until]),
                    'fields'         => 'spend,impressions,clicks,reach,actions,account_currency,date_start,date_stop',
                    'limit'          => 100,
                    'access_token'   => $token,
                ]);
            } catch (\Throwable $e) {
                $errs[] = $acct['id'] . ': ' . $e->getMessage();
                continue;
            }
            foreach (self::parse((array) ($resp['data'] ?? [])) as $r) {
                $key  = ['tenant_id' => $tenantId, 'ad_account_id' => $acct['id'], 'month' => $r['month']];
                $vals = $r + ['account_name' => $acct['name'], 'fetched_at' => $now, 'updated_at' => $now];
                unset($vals['month']);
                $existing = $db->table('ad_spend')->where($key)->get()->getRowArray();
                if ($existing) {
                    $db->table('ad_spend')->where('id', $existing['id'])->update($vals);
                } else {
                    $db->table('ad_spend')->insert($key + $vals + ['created_at' => $now]);
                }
                $n++;
            }
            // Replace-the-window: what Meta no longer reports is gone.
            $db->table('ad_spend')->where('tenant_id', $tenantId)->where('ad_account_id', $acct['id'])
                ->where('month >=', substr($since, 0, 7))->where('fetched_at <', $now)->delete();
        }

        // An account the owner has since deselected must stop counting.
        $db->table('ad_spend')->where('tenant_id', $tenantId)
            ->whereNotIn('ad_account_id', array_column($status['ad_accounts'], 'id'))->delete();

        $model  = new IntegrationModel();
        $row    = (array) $model->findActiveByType($tenantId, self::TYPE);
        $config = json_decode((string) $row['config'], true) ?: [];
        $config['last_sync_at'] = $now;
        $config['last_error']   = $errs === [] ? null : implode(' | ', $errs);
        $model->setTenant($tenantId)->update((int) $row['id'], ['config' => json_encode($config)]);

        if ($n === 0 && $errs !== []) {
            throw new RuntimeException(implode(' | ', $errs));
        }
        log_message('info', "MetaAdsService: tenant {$tenantId} synced {$n} ad-spend rows.");

        return ['accounts' => count($status['ad_accounts']), 'rows' => $n, 'months' => $months, 'errors' => $errs];
    }

    /**
     * @param array<int,mixed> $data insights rows
     * @return array<int,array{month:string,spend:float,impressions:int,clicks:int,reach:int,leads:int,currency:string}>
     */
    public static function parse(array $data): array
    {
        $rows = [];
        foreach ($data as $r) {
            $r     = (array) $r;
            $start = (string) ($r['date_start'] ?? '');
            if (strlen($start) < 7) {
                continue;
            }
            $leads = 0;
            foreach ((array) ($r['actions'] ?? []) as $a) {
                $a = (array) $a;
                if (in_array((string) ($a['action_type'] ?? ''), self::LEAD_ACTIONS, true)) {
                    $leads = max($leads, (int) ($a['value'] ?? 0));
                }
            }
            $rows[] = [
                'month'       => substr($start, 0, 7),
                'spend'       => round((float) ($r['spend'] ?? 0), 2),
                'impressions' => (int) ($r['impressions'] ?? 0),
                'clicks'      => (int) ($r['clicks'] ?? 0),
                'reach'       => (int) ($r['reach'] ?? 0),
                'leads'       => $leads,
                'currency'    => (string) ($r['account_currency'] ?? 'INR'),
            ];
        }

        return $rows;
    }

    /** ['YYYY-MM-01' of the earliest month, 'YYYY-MM-DD' today] */
    public static function window(int $months, int $now): array
    {
        $months = max(1, min(24, $months));
        $first  = (int) gmmktime(0, 0, 0, (int) gmdate('n', $now) - ($months - 1), 1, (int) gmdate('Y', $now));

        return [gmdate('Y-m-d', $first), gmdate('Y-m-d', $now)];
    }

    // ── Report ──────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function report(int $tenantId, int $months = 12): array
    {
        $months = max(1, min(24, $months));
        [$since] = self::window($months, $this->now);
        $status  = $this->status($tenantId);

        $rows = db_connect()->table('ad_spend')
            ->where('tenant_id', $tenantId)->where('month >=', substr($since, 0, 7))
            ->orderBy('month', 'ASC')->get()->getResultArray();

        $currency  = 'INR';
        $fetchedAt = null;
        $byMonth   = [];
        foreach ($rows as $r) {
            $currency  = $r['currency'] ?: $currency;
            $fetchedAt = max((string) $fetchedAt, (string) $r['fetched_at']);
            $m = $r['month'];
            $byMonth[$m] ??= self::emptyMonth($m);
            foreach (['spend', 'impressions', 'clicks', 'reach', 'leads'] as $k) {
                $byMonth[$m][$k] += $k === 'spend' ? (float) $r[$k] : (int) $r[$k];
            }
            $byMonth[$m]['by_account'][$r['ad_account_id']] = [
                'name' => (string) ($r['account_name'] ?? $r['ad_account_id']), 'spend' => round((float) $r['spend'], 2), 'leads' => (int) $r['leads'],
            ];
        }

        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $m = gmdate('Y-m', (int) gmmktime(0, 0, 0, (int) substr($since, 5, 2) + $i, 1, (int) substr($since, 0, 4)));
            if ($m > gmdate('Y-m', $this->now)) {
                break;
            }
            $row = $byMonth[$m] ?? self::emptyMonth($m);
            $row['spend']          = round($row['spend'], 2);
            $row['cost_per_lead']  = $row['leads'] > 0 ? round($row['spend'] / $row['leads'], 2) : null;
            $out[]                 = $row;
        }

        $n      = count($out);
        $this_  = $n ? $out[$n - 1] : self::emptyMonth(gmdate('Y-m', $this->now));
        $last   = $n > 1 ? $out[$n - 2] : null;
        $change = ($last && $last['spend'] > 0) ? round((($this_['spend'] - $last['spend']) / $last['spend']) * 100, 1) : null;

        return [
            'connected'      => $status['connected'],
            'ad_accounts'    => $status['ad_accounts'],
            'currency'       => $currency,
            'synced_at'      => $fetchedAt,
            'last_error'     => $status['last_error'] ?? null,
            'months'         => $out,
            'totals'         => [
                'spend' => round(array_sum(array_column($out, 'spend')), 2),
                'leads' => array_sum(array_column($out, 'leads')),
                'clicks' => array_sum(array_column($out, 'clicks')),
            ],
            'this_month'     => $this_,
            'last_month'     => $last,
            'mom_change_pct' => $change,
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function token(int $tenantId): string
    {
        $row = (new IntegrationModel())->findActiveByType($tenantId, self::TYPE);
        if (! $row) {
            throw new RuntimeException('Meta Ads is not connected — connect with Facebook first.');
        }
        $config = json_decode((string) ((array) $row)['config'], true) ?: [];
        $token  = ! empty($config['user_token_enc']) ? TokenCipher::decrypt((string) $config['user_token_enc']) : '';
        if ($token === '') {
            throw new RuntimeException('The Meta Ads token is missing — reconnect with Facebook.');
        }

        return $token;
    }

    /** @return array<string,mixed> */
    private static function emptyMonth(string $m): array
    {
        return ['month' => $m, 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'reach' => 0, 'leads' => 0, 'by_account' => []];
    }
}
