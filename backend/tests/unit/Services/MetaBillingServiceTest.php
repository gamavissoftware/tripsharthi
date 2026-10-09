<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Analytics\MetaBillingService;
use App\Services\Social\GraphClient;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Month-on-month WhatsApp spend from Meta's pricing_analytics.
 *
 * The numbers on this report are the customer's real Meta bill, so the
 * things to pin down are: Meta's buckets land in the right month and
 * category, a re-sync updates rather than duplicates, every month in the
 * window is present even when nothing was billed, and one tenant never sees
 * another's WABA.
 */
class MetaBillingServiceTest extends CIUnitTestCase
{
    /** 2026-09-25 12:00 UTC */
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = gmmktime(12, 0, 0, 9, 25, 2026);

        $db = db_connect();
        $p  = $db->DBPrefix;
        foreach (['waba_billing', 'waba_accounts', 'messages'] as $t) {
            $db->query("DROP TABLE IF EXISTS {$p}{$t}");
        }
        $db->query("CREATE TABLE {$p}waba_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, waba_id TEXT, business_id TEXT, app_id TEXT,
            access_token_enc TEXT, display_name TEXT, verify_token TEXT, status TEXT DEFAULT 'active',
            provider TEXT, provider_config_json TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("CREATE TABLE {$p}waba_billing (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, waba_id TEXT, month TEXT, category TEXT,
            pricing_type TEXT, volume INTEGER DEFAULT 0, cost REAL DEFAULT 0, currency TEXT DEFAULT 'INR',
            source TEXT, fetched_at TEXT, created_at TEXT, updated_at TEXT,
            UNIQUE(tenant_id, waba_id, month, category, pricing_type))");
        $db->query("CREATE TABLE {$p}messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, contact_id INTEGER, direction TEXT,
            type TEXT, category TEXT, billable INTEGER DEFAULT 0, status TEXT, created_at TEXT)");

        $db->table('waba_accounts')->insert([
            'tenant_id' => 1, 'waba_id' => '2026154451611028', 'access_token_enc' => TokenCipher::encrypt('waba-token'),
            'status' => 'active', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
        ]);
        $db->table('messages')->insert(['tenant_id' => 1, 'direction' => 'out', 'billable' => 1, 'created_at' => '2026-09-10 10:00:00']);
        $db->table('messages')->insert(['tenant_id' => 1, 'direction' => 'out', 'billable' => 0, 'created_at' => '2026-09-11 10:00:00']);
        $db->table('messages')->insert(['tenant_id' => 2, 'direction' => 'out', 'billable' => 1, 'created_at' => '2026-09-12 10:00:00']);
    }

    private function service(?FakeBillingGraph $g = null): MetaBillingService
    {
        return new MetaBillingService($g ?? new FakeBillingGraph(), $this->now);
    }

    public function testWindowStartsOnTheFirstOfTheEarliestMonth(): void
    {
        [$s, $e] = MetaBillingService::window(3, $this->now);
        $this->assertSame('2026-07-01 00:00', gmdate('Y-m-d H:i', $s));
        $this->assertSame($this->now, $e);
        // Year boundary
        [$s] = MetaBillingService::window(12, gmmktime(0, 0, 0, 2, 15, 2026));
        $this->assertSame('2025-03-01', gmdate('Y-m-d', $s));
    }

    public function testSyncStoresMetaBucketsByMonthAndCategory(): void
    {
        $g = new FakeBillingGraph();
        $r = $this->service($g)->sync(1, 3);

        $this->assertSame('INR', $r['currency']);
        $this->assertSame('pricing_analytics', $r['source']);
        $this->assertSame(4, $r['rows']);
        // The token went to Meta, never the ciphertext.
        $this->assertSame('waba-token', $g->calls[0]['params']['access_token']);
        $this->assertStringContainsString('granularity(MONTHLY)', $g->calls[1]['params']['fields']);
        // The open month is read day by day, from its first day.
        $daily = array_values(array_filter($g->calls, static fn ($c) => str_contains((string) ($c['params']['fields'] ?? ''), 'DAILY')));
        $this->assertCount(1, $daily);
        $this->assertStringContainsString('start(' . gmmktime(0, 0, 0, 9, 1, 2026) . ')', $daily[0]['params']['fields']);

        $rows = db_connect()->table('waba_billing')->orderBy('month')->orderBy('category')->get()->getResultArray();
        $this->assertSame(['2026-08', 'marketing', 'regular', 120, 96.0], [
            $rows[0]['month'], $rows[0]['category'], $rows[0]['pricing_type'], (int) $rows[0]['volume'], (float) $rows[0]['cost'],
        ]);
        $this->assertSame('2026-09', $rows[2]['month']);
        $this->assertSame('free_customer_service', $rows[2]['pricing_type']);
    }

    public function testAggregateSumsDailyBucketsIntoTheirMonth(): void
    {
        $rows = MetaBillingService::aggregate([
            ['month' => '2026-09', 'category' => 'marketing', 'pricing_type' => 'regular', 'volume' => 2, 'cost' => 1.5],
            ['month' => '2026-09', 'category' => 'marketing', 'pricing_type' => 'regular', 'volume' => 3, 'cost' => 2.25],
            ['month' => '2026-09', 'category' => 'utility',   'pricing_type' => 'regular', 'volume' => 1, 'cost' => 0.1],
        ]);
        $this->assertCount(2, $rows);
        $this->assertSame([5, 3.75], [$rows[0]['volume'], $rows[0]['cost']]);
    }

    public function testResyncUpdatesInsteadOfDuplicating(): void
    {
        $g = new FakeBillingGraph();
        $this->service($g)->sync(1, 3);
        $g->septMarketingCost = 250.5;
        $this->service($g)->sync(1, 3);

        $this->assertSame(4, db_connect()->table('waba_billing')->countAllResults());
        $row = db_connect()->table('waba_billing')->where('month', '2026-09')->where('category', 'marketing')->get()->getRowArray();
        $this->assertSame(250.5, (float) $row['cost']);
    }

    public function testSyncDropsBucketsMetaNoLongerReports(): void
    {
        // A row from an earlier sync that Meta's current answer does not
        // contain (e.g. keyed to the wrong month by an old build) must go.
        db_connect()->table('waba_billing')->insert([
            'tenant_id' => 1, 'waba_id' => '2026154451611028', 'month' => '2026-07', 'category' => 'marketing',
            'pricing_type' => 'regular', 'volume' => 9, 'cost' => 9.0, 'currency' => 'INR',
            'source' => 'pricing_analytics', 'fetched_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01', 'updated_at' => '2026-09-01',
        ]);
        $this->service()->sync(1, 3);

        $this->assertSame(0, db_connect()->table('waba_billing')->where('month', '2026-07')->countAllResults());
        $this->assertSame(4, db_connect()->table('waba_billing')->countAllResults());
    }

    public function testReportFillsEveryMonthAndComputesMonthOnMonth(): void
    {
        $this->service()->sync(1, 3);
        $rep = $this->service()->report(1, 3);

        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($rep['months'], 'month'));
        $this->assertSame(0.0, $rep['months'][0]['cost'], 'a silent month is still listed');
        $sep = $rep['months'][2];
        $this->assertSame(210.0, $sep['cost']);          // 200 marketing + 10 utility + 0 free service
        $this->assertSame(330, $sep['volume']);
        $this->assertSame(30, $sep['free_volume']);
        $this->assertSame(200.0, $sep['by_category']['marketing']['cost']);
        $this->assertSame(2, $sep['travelpilot_sent'], 'only this tenant\'s outbound');
        $this->assertSame(1, $sep['travelpilot_billable']);
        $this->assertSame(306.0, $rep['totals']['cost']);
        $this->assertSame(118.8, $rep['mom_change_pct']);  // (210 - 96) / 96
        $this->assertSame('INR', $rep['currency']);
        $this->assertNotEmpty($rep['synced_at']);
    }

    public function testReportIsTenantScoped(): void
    {
        $this->service()->sync(1, 3);
        $rep = $this->service()->report(2, 3);

        $this->assertSame(0.0, $rep['totals']['cost']);
        $this->assertNull($rep['synced_at']);
    }

    public function testFallsBackToConversationAnalyticsWhenPricingIsRefused(): void
    {
        $g = new FakeBillingGraph();
        $g->pricingFails = true;
        $r = $this->service($g)->sync(1, 3);

        $this->assertSame('conversation_analytics', $r['source']);
        $row = db_connect()->table('waba_billing')->where('month', '2026-08')->get()->getRowArray();
        $this->assertSame('marketing', $row['category']);
        $this->assertSame(50, (int) $row['volume']);
    }

    public function testSyncRefusesWithoutAConnectedWaba(): void
    {
        $this->expectExceptionMessageMatches('/No active WhatsApp Business Account/');
        $this->service()->sync(2, 3);
    }
}

class FakeBillingGraph extends GraphClient
{
    /** @var array<int,array{path:string,params:array<string,mixed>}> */
    public array $calls = [];
    public bool $pricingFails = false;
    public float $septMarketingCost = 200.0;

    public function __construct()
    {
    }

    public function get(string $path, array $params = []): array
    {
        $this->calls[] = ['path' => $path, 'params' => $params];
        $fields = (string) ($params['fields'] ?? '');
        $aug    = gmmktime(0, 0, 0, 8, 1, 2026);
        $sep    = gmmktime(0, 0, 0, 9, 1, 2026);

        if ($fields === 'currency') {
            return ['currency' => 'INR', 'id' => $path];
        }
        if (str_starts_with($fields, 'pricing_analytics')) {
            if ($this->pricingFails) {
                throw new \RuntimeException('Graph API error 100: (#100) Tried accessing nonexisting field (pricing_analytics)');
            }
            if (str_contains($fields, 'granularity(DAILY)')) {
                // The open month, as Meta actually serves it: one point per day.
                return ['pricing_analytics' => ['data' => [['data_points' => [
                    ['start' => $sep + 86400 * 9,  'end' => $sep + 86400 * 10, 'volume' => 100, 'cost' => 80.0,  'pricing_category' => 'MARKETING', 'pricing_type' => 'REGULAR'],
                    ['start' => $sep + 86400 * 14, 'end' => $sep + 86400 * 15, 'volume' => 150, 'cost' => $this->septMarketingCost - 80.0, 'pricing_category' => 'MARKETING', 'pricing_type' => 'REGULAR'],
                    ['start' => $sep + 86400 * 14, 'end' => $sep + 86400 * 15, 'volume' => 50,  'cost' => 10.0,  'pricing_category' => 'UTILITY',   'pricing_type' => 'REGULAR'],
                    ['start' => $sep + 86400 * 3,  'end' => $sep + 86400 * 4,  'volume' => 20,  'cost' => 0.0,   'pricing_category' => 'SERVICE',   'pricing_type' => 'FREE_CUSTOMER_SERVICE'],
                    ['start' => $sep + 86400 * 20, 'end' => $sep + 86400 * 21, 'volume' => 10,  'cost' => 0.0,   'pricing_category' => 'SERVICE',   'pricing_type' => 'FREE_CUSTOMER_SERVICE'],
                ]]]], 'id' => $path];
            }
            // MONTHLY: Meta omits the month that has not closed yet.
            return ['pricing_analytics' => ['data' => [['data_points' => [
                ['start' => $aug, 'end' => $sep, 'volume' => 120, 'cost' => 96.0,   'pricing_category' => 'MARKETING', 'pricing_type' => 'REGULAR'],
            ]]]], 'id' => $path];

        }
        if (str_starts_with($fields, 'conversation_analytics')) {
            return ['conversation_analytics' => ['data' => [['data_points' => [
                ['start' => $aug, 'end' => $sep, 'conversation' => 50, 'cost' => 40.0, 'conversation_category' => 'MARKETING', 'conversation_type' => 'REGULAR'],
            ]]]], 'id' => $path];
        }

        return [];
    }
}
