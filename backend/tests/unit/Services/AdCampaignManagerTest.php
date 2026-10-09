<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Ads\AdCampaignManager;
use App\Services\Ads\AdsApiException;
use App\Services\Ads\AdsGuardrailException;
use App\Services\Ads\AdsMockHttp;
use App\Services\Ads\MetaAdapter;
use App\Services\Ads\MetaMarketingClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh. Uses the stateful ADS_MOCK_MODE platform simulator. */
#[Group('mysql')]
final class AdCampaignManagerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['ad_campaigns', 'ad_insights_daily', 'ad_settings', 'ad_rules', 'audit_logs', 'integrations', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'T', 'slug' => 't', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        @unlink(WRITEPATH . 'ads-mock-1.json');
        putenv('ADS_MOCK_MODE=true'); $_ENV['ADS_MOCK_MODE'] = 'true';
    }

    protected function tearDown(): void
    {
        @unlink(WRITEPATH . 'ads-mock-1.json');
        putenv('ADS_MOCK_MODE=false'); $_ENV['ADS_MOCK_MODE'] = 'false';
        parent::tearDown();
    }

    private function m(): AdCampaignManager { return new AdCampaignManager(); }

    private function cap(int $rupees = 5000): void { $this->m()->saveSettings(1, ['daily_spend_cap' => $rupees * 100]); }

    private function meta(array $o = []): array
    {
        return $o + ['platform' => 'meta', 'kind' => 'lead_form', 'name' => 'Bali Honeymoon', 'account_id' => 'act_1000000001', 'page_id' => '1100001', 'lead_form_id' => '9001',
            'daily_budget' => 100_000, 'start_date' => '2026-11-01', 'targeting' => ['age_min' => 25, 'age_max' => 45],
            'creative' => ['primary_text' => 'Bali for two from ₹55,000 per person.', 'headline' => 'Bali Honeymoon Packages', 'image_url' => 'https://cdn.example.com/b.jpg']];
    }

    private function google(array $o = []): array
    {
        return $o + ['platform' => 'google', 'kind' => 'search', 'name' => 'Bali Search', 'customer_id' => '1234567890', 'daily_budget' => 100_000, 'final_url' => 'https://example.com/bali',
            'headlines' => ['Bali Honeymoon Packages', 'Custom Bali Itinerary', 'Free Quote in 1 Hour'], 'descriptions' => ['Handpicked villas, private transfers and spa. Get your custom quote.', 'Trusted travel experts since 2012. Flexible payments available.'],
            'keywords' => [['text' => 'bali honeymoon package', 'match' => 'PHRASE']]];
    }

    private function remote(): array { return json_decode((string) file_get_contents(WRITEPATH . 'ads-mock-1.json'), true); }

    // ---- guardrails through the real path --------------------------------------------------------------

    public function testNothingCanBeCreatedUntilASpendCapIsSet(): void
    {
        try { $this->m()->create(1, 7, $this->meta()); $this->fail('should be refused'); }
        catch (AdsGuardrailException $e) { $this->assertStringContainsString('daily spend cap', $e->getMessage()); }
        $this->assertSame(0, db_connect()->table('ad_campaigns')->countAllResults());   // refused BEFORE any local/remote write
        $this->assertFileDoesNotExist(WRITEPATH . 'ads-mock-1.json');
    }

    public function testCreateMakesEverythingPausedLocallyAndRemotely(): void
    {
        $this->cap();
        $row = $this->m()->create(1, 7, $this->meta());
        $this->assertSame('PAUSED', $row['status']);
        $this->assertSame('travelpilot', $row['origin']);
        $this->assertMatchesRegularExpression('/^\d+$/', $row['external_id']);
        $kids = json_decode($row['children'], true);
        $this->assertNotEmpty($kids['adset_id']);
        $this->assertNotEmpty($kids['ad_id']);

        foreach ($this->remote()['meta']['objects'] as $o) { $this->assertSame('PAUSED', $o['status'] ?? 'PAUSED', $o['_edge']); }   // nothing can serve
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'ad_campaign.create')->countAllResults());
    }

    public function testLaunchRespectsTheCapAcrossCampaigns(): void
    {
        $this->cap(1500);                                              // ₹1,500/day total
        $a = $this->m()->create(1, 7, $this->meta(['name' => 'A']));   // ₹1,000
        $b = $this->m()->create(1, 7, $this->meta(['name' => 'B']));   // ₹1,000
        $this->assertSame('ACTIVE', $this->m()->launch(1, (int) $a['id'], 7)['status']);

        try { $this->m()->launch(1, (int) $b['id'], 7); $this->fail('second launch should exceed the cap'); }
        catch (AdsGuardrailException $e) { $this->assertStringContainsString('above your cap', $e->getMessage()); }
        $this->assertSame('PAUSED', db_connect()->table('ad_campaigns')->where('id', $b['id'])->get()->getRowArray()['status']);
        $remoteB = $this->remote()['meta']['objects'][$b['external_id']];
        $this->assertSame('PAUSED', $remoteB['status']);                // the platform was never touched
    }

    public function testBudgetIncreaseLimitReductionsAndPauseAreHandledSafely(): void
    {
        $this->cap(5000);
        $c = $this->m()->create(1, 7, $this->meta());                  // ₹1,000
        $id = (int) $c['id'];
        $this->m()->launch(1, $id, 7);

        try { $this->m()->setBudget(1, $id, 140_000, 7); $this->fail('+40% must be refused'); }
        catch (AdsGuardrailException $e) { $this->assertStringContainsString('at most 30%', $e->getMessage()); }

        $up = $this->m()->setBudget(1, $id, 120_000, 7);               // +20% ok
        $this->assertSame(120_000, (int) $up['daily_budget']);
        $adset = json_decode($up['children'], true)['adset_id'];
        $this->assertSame(120_000, (int) $this->remote()['meta']['objects'][$adset]['daily_budget']);

        $this->cap(100);                                               // cap now far below current spend
        $this->assertSame(60_000, (int) $this->m()->setBudget(1, $id, 60_000, 7)['daily_budget']);   // cutting is always allowed
        $this->assertSame('PAUSED', $this->m()->pause(1, $id, 7)['status']);                          // so is pausing
        $this->assertGreaterThanOrEqual(4, db_connect()->table('audit_logs')->like('action', 'ad_campaign.')->countAllResults());
    }

    // ---- failure handling --------------------------------------------------------------------------------

    public function testPartialFailureRollsBackAndRecordsTheError(): void
    {
        $this->cap();
        $deleted = [];
        $mock = AdsMockHttp::handler(1);
        $http = function (string $method, string $url, array $opt) use ($mock, &$deleted): array {
            if ($method === 'POST' && str_ends_with($url, '/ads') && ! in_array('validate_only', (array) ($opt['json']['execution_options'] ?? []), true)) {
                return ['status' => 400, 'body' => json_encode(['error' => ['code' => 100, 'error_user_msg' => 'Your ad creative was rejected.', 'message' => 'x']])];
            }
            if ($method === 'DELETE') { $deleted[] = $url; }
            return $mock($method, $url, $opt);
        };
        $mgr = new AdCampaignManager(fn (string $p, int $t) => new MetaAdapter($t, new MetaMarketingClient('tok', $http)));

        try { $mgr->create(1, 7, $this->meta()); $this->fail('expected failure'); }
        catch (AdsApiException $e) { $this->assertStringContainsString('rejected the ad', $e->getMessage()); }

        $this->assertCount(3, $deleted);                                // creative, ad set, campaign cleaned up
        $row = db_connect()->table('ad_campaigns')->get()->getRowArray();
        $this->assertSame('ERROR', $row['effective_status']);
        $this->assertStringContainsString('creative was rejected', $row['last_error']);
        $this->assertStringStartsWith('pending_', $row['external_id']);   // never mistaken for a live campaign
        $this->assertEmpty($this->remote()['meta']['objects'] ?? []);
    }

    public function testNotConnectedGivesAnActionableError(): void
    {
        putenv('ADS_MOCK_MODE=false'); $_ENV['ADS_MOCK_MODE'] = 'false';
        $this->cap();
        try { $this->m()->create(1, 7, $this->meta()); $this->fail('should need a connection'); }
        catch (AdsApiException $e) { $this->assertSame(AdsApiException::AUTH, $e->kind); $this->assertStringContainsString('Connect it first', $e->getMessage()); }
    }

    // ---- sync ------------------------------------------------------------------------------------------------

    public function testSyncPullsCampaignsAndInsightsAndMarksRemovedOnes(): void
    {
        $this->cap();
        $c = $this->m()->create(1, 7, $this->meta());
        $this->m()->launch(1, (int) $c['id'], 7);

        $r = $this->m()->syncAll(1);
        $this->assertSame(1, $r['meta']['campaigns']);
        $this->assertSame(7, $r['meta']['insight_days']);               // active: 7 simulated days
        $ins = db_connect()->table('ad_insights_daily')->get()->getResultArray();
        $this->assertCount(7, $ins);
        $this->assertGreaterThan(0, (int) $ins[0]['spend']);
        $this->m()->syncAll(1);                                         // idempotent: upsert, not duplicate
        $this->assertSame(7, db_connect()->table('ad_insights_daily')->countAllResults());
        $this->assertSame(1, db_connect()->table('ad_campaigns')->countAllResults());

        // Removed on the platform -> kept locally (history) but marked DELETED.
        $f = WRITEPATH . 'ads-mock-1.json';
        $db = json_decode(file_get_contents($f), true); unset($db['meta']['objects'][$c['external_id']]); file_put_contents($f, json_encode($db));
        $this->m()->syncAll(1);
        $this->assertSame('DELETED', db_connect()->table('ad_campaigns')->get()->getRowArray()['status']);
    }

    // ---- Google ---------------------------------------------------------------------------------------------------

    public function testGoogleCreateLaunchBudgetAndSync(): void
    {
        $this->cap();
        $row = $this->m()->create(1, 7, $this->google());
        $this->assertSame('PAUSED', $row['status']);
        $this->assertSame('SEARCH', $row['objective']);
        $this->assertNotEmpty(json_decode($row['children'], true)['budget_resource']);

        $this->assertSame('ACTIVE', $this->m()->launch(1, (int) $row['id'], 7)['status']);
        $this->assertSame('ENABLED', array_values($this->remote()['google']['campaigns'])[0]['status']);

        $this->assertSame(120_000, (int) $this->m()->setBudget(1, (int) $row['id'], 120_000, 7)['daily_budget']);
        $budgets = array_values($this->remote()['google']['budgets']);
        $this->assertSame('1200000000', $budgets[0]['amountMicros']);

        $this->m()->syncAll(1);
        $this->assertSame(120_000, (int) db_connect()->table('ad_campaigns')->where('platform', 'google')->get()->getRowArray()['daily_budget']);   // micros/10,000 round-trips
        $this->assertGreaterThan(0, db_connect()->table('ad_insights_daily')->where('platform', 'google')->countAllResults());
    }

    public function testPreviewValidatesWithoutCreatingAnything(): void
    {
        $this->cap();
        $p = $this->m()->preview(1, $this->meta());
        $this->assertTrue($p['ok']);
        $this->assertSame(3_000_000, $p['monthly_estimate']);
        $this->assertSame([], $p['launch_blockers']);
        $this->assertSame(0, db_connect()->table('ad_campaigns')->countAllResults());
        $this->assertFileDoesNotExist(WRITEPATH . 'ads-mock-1.json');       // the platform stored nothing

        $bad = $this->m()->preview(1, $this->meta(['name' => '', 'daily_budget' => 0]));
        $this->assertFalse($bad['ok']);
        $this->assertNotEmpty($bad['errors']);

        $this->m()->saveSettings(1, ['daily_spend_cap' => 50_000]);   // ₹500 cap < ₹1,000 budget
        $blocked = $this->m()->preview(1, $this->meta());
        $this->assertNotEmpty($blocked['launch_blockers']);           // creation allowed (paused) but the review warns it can't launch
    }
}
