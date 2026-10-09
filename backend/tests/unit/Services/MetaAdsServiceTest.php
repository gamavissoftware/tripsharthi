<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Analytics\MetaAdsService;
use App\Services\Social\GraphClient;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Meta ad spend from account-level insights.
 *
 * The user token from a finished Facebook Login is kept encrypted on the
 * meta_ads integration and never leaves the server; insights land in
 * ad_spend by account × month; a re-sync replaces the window; every month
 * in the range is listed; tenants are isolated.
 */
class MetaAdsServiceTest extends CIUnitTestCase
{
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = gmmktime(12, 0, 0, 9, 25, 2026);
        $db = db_connect();
        $p  = $db->DBPrefix;
        foreach (['ad_spend', 'integrations', 'social_oauth_states'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$p}{$t}");
        }
        $db->query("CREATE TABLE {$p}integrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, type TEXT, page_id TEXT, verify_token TEXT,
            config TEXT, status TEXT DEFAULT 'active', created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("CREATE TABLE {$p}social_oauth_states (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, state TEXT, user_token_enc TEXT,
            pages_json TEXT, status TEXT DEFAULT 'pending', return_to TEXT DEFAULT 'social', error TEXT, expires_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("CREATE TABLE {$p}ad_spend (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, ad_account_id TEXT, account_name TEXT, month TEXT,
            spend REAL DEFAULT 0, impressions INTEGER DEFAULT 0, clicks INTEGER DEFAULT 0, reach INTEGER DEFAULT 0,
            leads INTEGER DEFAULT 0, currency TEXT DEFAULT 'INR', fetched_at TEXT, created_at TEXT, updated_at TEXT,
            UNIQUE(tenant_id, ad_account_id, month))");
        $db->table('social_oauth_states')->insert([
            'tenant_id' => 1, 'user_id' => 7, 'state' => 'st-ads', 'status' => 'ready', 'return_to' => 'ads',
            'user_token_enc' => TokenCipher::encrypt('user-token'), 'pages_json' => '[]',
            'expires_at' => '2026-09-25 12:10:00', 'created_at' => '2026-09-25 12:00:00', 'updated_at' => '2026-09-25 12:00:00',
        ]);
    }

    private function service(?FakeAdsGraph $g = null): MetaAdsService
    {
        return new MetaAdsService($g ?? new FakeAdsGraph(), $this->now);
    }

    private function connected(?FakeAdsGraph $g = null): MetaAdsService
    {
        $s = $this->service($g);
        $s->connect('st-ads', 1, 7);
        $s->select(1, [['id' => 'act_2067768434076039', 'name' => 'Gamavis Softech', 'currency' => 'INR'], ['id' => 'bogus']]);

        return $s;
    }

    public function testConnectKeepsTheUserTokenEncryptedAndConsumesTheState(): void
    {
        $status = $this->service()->connect('st-ads', 1, 7);

        $this->assertTrue($status['connected']);
        $this->assertSame([], $status['ad_accounts']);
        $row    = db_connect()->table('integrations')->where('type', 'meta_ads')->get()->getRowArray();
        $config = json_decode($row['config'], true);
        $this->assertSame('user-token', TokenCipher::decrypt($config['user_token_enc']));
        $this->assertSame(7, $config['connected_by']);
        $state = db_connect()->table('social_oauth_states')->where('state', 'st-ads')->get()->getRowArray();
        $this->assertSame('consumed', $state['status']);
        $this->assertEmpty($state['user_token_enc']);
    }

    public function testConnectIsTenantScoped(): void
    {
        $this->expectExceptionMessageMatches('/Unknown connection attempt/');
        $this->service()->connect('st-ads', 2, 7);
    }

    public function testAccountsAreReadWithTheUserToken(): void
    {
        $g = new FakeAdsGraph();
        $s = $this->service($g);
        $s->connect('st-ads', 1, 7);
        $accounts = $s->accounts(1);

        $this->assertSame('me/adaccounts', $g->calls[0]['path']);
        $this->assertSame('user-token', $g->calls[0]['params']['access_token']);
        $this->assertSame('act_2067768434076039', $accounts[0]['id']);
        $this->assertSame('INR', $accounts[0]['currency']);
    }

    public function testSelectKeepsOnlyRealAccountIds(): void
    {
        $status = $this->connected()->status(1);
        $this->assertSame(['act_2067768434076039'], array_column($status['ad_accounts'], 'id'));
    }

    public function testSyncStoresMonthlyInsightsPerAccount(): void
    {
        $g = new FakeAdsGraph();
        $r = $this->connected($g)->sync(1, 3);

        $this->assertSame(2, $r['rows']);
        $ins = array_values(array_filter($g->calls, static fn ($c) => str_ends_with($c['path'], '/insights')))[0];
        $this->assertSame('monthly', $ins['params']['time_increment']);
        $this->assertSame(['since' => '2026-07-01', 'until' => '2026-09-25'], json_decode($ins['params']['time_range'], true));

        $rows = db_connect()->table('ad_spend')->orderBy('month')->get()->getResultArray();
        $this->assertSame('2026-08', $rows[0]['month']);
        $this->assertSame(1250.5, (float) $rows[0]['spend']);
        $this->assertSame(7, (int) $rows[0]['leads'], 'overlapping lead action types are not summed — the largest counts');
        $this->assertSame('Gamavis Softech', $rows[0]['account_name']);
    }

    public function testResyncReplacesTheWindow(): void
    {
        $g = new FakeAdsGraph();
        $s = $this->connected($g);
        $s->sync(1, 3);
        db_connect()->table('ad_spend')->insert([
            'tenant_id' => 1, 'ad_account_id' => 'act_2067768434076039', 'month' => '2026-07', 'spend' => 9,
            'currency' => 'INR', 'fetched_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01', 'updated_at' => '2026-09-01',
        ]);
        $g->septSpend = 999.0;
        $s->sync(1, 3);

        $this->assertSame(2, db_connect()->table('ad_spend')->countAllResults(), 'ghost July row removed, no duplicates');
        $this->assertSame(999.0, (float) db_connect()->table('ad_spend')->where('month', '2026-09')->get()->getRowArray()['spend']);
    }

    public function testDeselectingAnAccountRemovesItsSpend(): void
    {
        $s = $this->connected();
        $s->sync(1, 3);
        $this->assertSame(2, db_connect()->table('ad_spend')->countAllResults());

        $s->select(1, [['id' => 'act_1837540430273601', 'name' => 'Other', 'currency' => 'USD']]);

        $this->assertSame(0, db_connect()->table('ad_spend')->where('ad_account_id', 'act_2067768434076039')->countAllResults());
    }

    public function testReportListsEveryMonthWithMoMAndCostPerLead(): void
    {
        $s = $this->connected();
        $s->sync(1, 3);
        $rep = $s->report(1, 3);

        $this->assertTrue($rep['connected']);
        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($rep['months'], 'month'));
        $this->assertSame(0.0, $rep['months'][0]['spend']);
        $this->assertSame(1250.5, $rep['months'][1]['spend']);
        $this->assertSame(178.64, $rep['months'][1]['cost_per_lead']);
        $this->assertSame(2000.0, $rep['this_month']['spend']);
        $this->assertSame(59.9, $rep['mom_change_pct']);
        $this->assertSame(3250.5, $rep['totals']['spend']);
        $this->assertSame('Gamavis Softech', $rep['months'][2]['by_account']['act_2067768434076039']['name']);
    }

    public function testReportIsTenantScopedAndHonestWhenNotConnected(): void
    {
        $this->connected()->sync(1, 3);
        $rep = $this->service()->report(2, 3);

        $this->assertFalse($rep['connected']);
        $this->assertSame(0.0, $rep['totals']['spend']);
    }

    public function testSyncRefusesWithoutAccounts(): void
    {
        $s = $this->service();
        $s->connect('st-ads', 1, 7);
        $this->expectExceptionMessageMatches('/No ad account selected/');
        $s->sync(1, 3);
    }
}

class FakeAdsGraph extends GraphClient
{
    /** @var array<int,array{path:string,params:array<string,mixed>}> */
    public array $calls = [];
    public float $septSpend = 2000.0;

    public function __construct()
    {
    }

    public function get(string $path, array $params = []): array
    {
        $this->calls[] = ['path' => $path, 'params' => $params];
        if ($path === 'me/adaccounts') {
            return ['data' => [
                ['id' => 'act_2067768434076039', 'name' => 'Gamavis Softech', 'currency' => 'INR', 'account_status' => 1, 'amount_spent' => '325050'],
                ['id' => 'act_1837540430273601', 'name' => 'Manglesh Upadhyay', 'currency' => 'USD', 'account_status' => 1, 'amount_spent' => '0'],
            ]];
        }
        if (str_ends_with($path, '/insights')) {
            return ['data' => [
                ['date_start' => '2026-08-01', 'date_stop' => '2026-08-31', 'spend' => '1250.50', 'impressions' => '50000', 'clicks' => '900', 'reach' => '30000', 'account_currency' => 'INR',
                 'actions' => [['action_type' => 'lead', 'value' => '7'], ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '5'], ['action_type' => 'link_click', 'value' => '800']]],
                ['date_start' => '2026-09-01', 'date_stop' => '2026-09-25', 'spend' => (string) $this->septSpend, 'impressions' => '80000', 'clicks' => '1500', 'reach' => '45000', 'account_currency' => 'INR',
                 'actions' => [['action_type' => 'lead', 'value' => '20']]],
            ]];
        }

        return [];
    }
}
