<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Ads\AdCampaignManager;
use App\Services\Ads\AdIssueService;
use App\Services\Ads\AdsMockHttp;
use App\Services\Ads\GoogleAdapter;
use App\Services\Ads\MetaAdapter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Parsers are pure; the lifecycle runs against the ADS_MOCK_MODE simulator (group "mysql"). */
#[Group('mysql')]
final class AdIssuesTest extends CIUnitTestCase
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
        foreach (['ad_issues', 'ad_campaigns', 'ad_insights_daily', 'ad_settings', 'notifications', 'push_log', 'mobile_devices', 'users', 'audit_logs', 'integrations', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'T', 'slug' => 't', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        foreach ([[5, 'owner'], [6, 'admin'], [7, 'agent']] as [$id, $role]) { $db->table('users')->insert(['id' => $id, 'tenant_id' => 1, 'name' => "U$id", 'email' => "u$id@x.test", 'password_hash' => 'x', 'role' => $role, 'created_at' => '2026-01-01 00:00:00']); }
        @unlink(WRITEPATH . 'ads-mock-1.json');
        putenv('ADS_MOCK_MODE=true'); $_ENV['ADS_MOCK_MODE'] = 'true';
    }

    protected function tearDown(): void
    {
        @unlink(WRITEPATH . 'ads-mock-1.json');
        putenv('ADS_MOCK_MODE=false'); $_ENV['ADS_MOCK_MODE'] = 'false';
        parent::tearDown();
    }

    // ---- pure parsers --------------------------------------------------------------------------------------------------

    public function testMetaReviewFeedbackIsTurnedIntoAReadableReason(): void
    {
        $i = MetaAdapter::parseIssue(['id' => '77', 'name' => 'Bali ad', 'campaign_id' => '9', 'effective_status' => 'DISAPPROVED', 'ad_review_feedback' => ['global' => ['Misleading claims' => 'You cannot guarantee savings.']]]);
        $this->assertSame(['9', '77', 'disapproved'], [$i['campaign'], $i['ad'], $i['kind']]);
        $this->assertStringContainsString('Misleading claims — You cannot guarantee savings.', $i['reason']);
        $this->assertSame('limited', MetaAdapter::parseIssue(['id' => '1', 'effective_status' => 'WITH_ISSUES'])['kind']);
        $this->assertNull(MetaAdapter::parseIssue(['id' => '1', 'effective_status' => 'ACTIVE']));
        $this->assertNotSame('', MetaAdapter::parseIssue(['id' => '1', 'effective_status' => 'DISAPPROVED'])['reason']);   // never a blank reason
    }

    public function testGooglePolicyTopicsBecomeTheReason(): void
    {
        $row = ['campaign' => ['id' => '5'], 'adGroupAd' => ['ad' => ['id' => '88', 'name' => 'RSA'], 'policySummary' => ['approvalStatus' => 'DISAPPROVED', 'policyTopicEntries' => [['topic' => 'MISLEADING_CLAIMS', 'type' => 'PROHIBITED']]]]];
        $i = GoogleAdapter::parseIssue($row);
        $this->assertSame(['5', '88', 'disapproved', 'Misleading Claims (prohibited)'], [$i['campaign'], $i['ad'], $i['kind'], $i['reason']]);
        $row['adGroupAd']['policySummary']['approvalStatus'] = 'APPROVED_LIMITED';
        $this->assertSame('limited', GoogleAdapter::parseIssue($row)['kind']);
        $row['adGroupAd']['policySummary']['approvalStatus'] = 'APPROVED';
        $this->assertNull(GoogleAdapter::parseIssue($row));
    }

    // ---- lifecycle ----------------------------------------------------------------------------------------------------------

    private function issue(string $ad = '77', string $kind = 'disapproved', string $reason = 'Misleading claims'): array { return ['campaign' => '9', 'ad' => $ad, 'ad_name' => 'Bali ad', 'kind' => $kind, 'reason' => $reason]; }

    public function testNewProblemAlertsOwnersAndAdminsOnceNeverAgents(): void
    {
        $svc = new AdIssueService(1_791_439_200);
        $r = $svc->record(1, 'meta', [$this->issue()]);
        $this->assertSame([1, 1, 0], [$r['open'], $r['new'], $r['resolved']]);
        $n = db_connect()->table('notifications')->where('type', 'ad_issue')->get()->getResultArray();
        $this->assertSame([5, 6], array_map(fn ($x) => (int) $x['user_id'], $n));
        $this->assertStringContainsString('DISAPPROVED', $n[0]['body']);
        // the next hourly sync sees the same problem: no second alert
        $again = $svc->record(1, 'meta', [$this->issue(reason: 'Misleading claims (updated text)')]);
        $this->assertSame([1, 0, 0], [$again['open'], $again['new'], $again['resolved']]);
        $this->assertSame(2, db_connect()->table('notifications')->where('type', 'ad_issue')->countAllResults());
        $this->assertSame('Misleading claims (updated text)', db_connect()->table('ad_issues')->get()->getRowArray()['reason']);
    }

    public function testFixedAdsAreResolvedAndARecurrenceAlertsAgain(): void
    {
        $svc = new AdIssueService(1_791_439_200);
        $svc->record(1, 'meta', [$this->issue('1'), $this->issue('2')]);
        $r = $svc->record(1, 'meta', [$this->issue('2')]);                         // ad 1 no longer reported
        $this->assertSame([1, 1], [$r['open'], $r['resolved']]);
        $this->assertNotNull(db_connect()->table('ad_issues')->where('ad_external_id', '1')->get()->getRowArray()['resolved_at']);
        $before = db_connect()->table('notifications')->countAllResults();
        $back = $svc->record(1, 'meta', [$this->issue('1'), $this->issue('2')]);   // ad 1 disapproved AGAIN
        $this->assertSame(1, $back['new']);
        $this->assertGreaterThan($before, db_connect()->table('notifications')->countAllResults());
    }

    public function testPlatformsAreIndependent(): void
    {
        $svc = new AdIssueService();
        $svc->record(1, 'meta', [$this->issue('1')]);
        $svc->record(1, 'google', []);                                              // an empty Google list must not resolve Meta's issues
        $this->assertSame(1, count($svc->open(1)));
    }

    public function testEndToEndThroughTheSyncAndTheMockPlatform(): void
    {
        $m = new AdCampaignManager();
        $m->saveSettings(1, ['daily_spend_cap' => 500_000]);
        $c = $m->create(1, 5, ['platform' => 'meta', 'kind' => 'lead_form', 'name' => 'Bali', 'account_id' => 'act_1000000001', 'page_id' => '1100001', 'lead_form_id' => '9001', 'daily_budget' => 100_000,
            'start_date' => '2026-11-01', 'targeting' => ['age_min' => 25, 'age_max' => 45], 'creative' => ['primary_text' => 'Bali for two from ₹55,000.', 'headline' => 'Bali Honeymoon', 'image_url' => 'https://cdn.example.com/b.jpg']]);
        $adId = json_decode($c['children'], true)['ad_id'];
        $m->syncAll(1);
        $this->assertSame([], (new AdIssueService())->open(1));                     // healthy ad: nothing
        (new AdsMockHttp(1))->flagMetaAd($adId);
        $s = $m->syncAll(1);
        $this->assertSame(1, $s['meta']['issues']['new']);
        $open = (new AdIssueService())->open(1);
        $this->assertSame(['meta', $c['external_id'], 'disapproved'], [$open[0]['platform'], $open[0]['campaign_external_id'], $open[0]['kind']]);
        (new AdsMockHttp(1))->flagMetaAd($adId, 'ACTIVE', []);                      // fixed in Meta
        $s2 = $m->syncAll(1);
        $this->assertSame([0, 1], [$s2['meta']['issues']['open'], $s2['meta']['issues']['resolved']]);
    }
}
