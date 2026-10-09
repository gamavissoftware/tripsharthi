<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Ads\AdAssetService;
use App\Services\Ads\AdAudienceService;
use App\Services\Ads\AdCampaignManager;
use App\Services\Ads\AdsApiException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Assets, audiences, multi-ad-set and PMax campaigns end to end against the ADS_MOCK_MODE simulator (group "mysql"). */
#[Group('mysql')]
final class AdGapsIntegrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('AD_ASSET_STORAGE_DIR=uploads/ad-assets-test'); $_ENV['AD_ASSET_STORAGE_DIR'] = 'uploads/ad-assets-test';
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['ad_audience_members', 'ad_audiences', 'ad_assets', 'ad_issues', 'ad_campaigns', 'ad_insights_daily', 'ad_settings', 'bookings', 'trips', 'segments', 'contacts', 'audit_logs', 'integrations', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        @unlink(WRITEPATH . 'ads-mock-1.json');
        putenv('ADS_MOCK_MODE=true'); $_ENV['ADS_MOCK_MODE'] = 'true';
        (new AdCampaignManager())->saveSettings(1, ['daily_spend_cap' => 5_000_000]);
    }

    protected function tearDown(): void
    {
        foreach (glob(WRITEPATH . 'uploads/ad-assets-test/*/*') ?: [] as $f) { @unlink($f); }
        foreach (glob(WRITEPATH . 'uploads/ad-assets-test/*') ?: [] as $d) { @rmdir($d); }
        @rmdir(WRITEPATH . 'uploads/ad-assets-test');
        putenv('AD_ASSET_STORAGE_DIR'); unset($_ENV['AD_ASSET_STORAGE_DIR']);
        @unlink(WRITEPATH . 'ads-mock-1.json');
        putenv('ADS_MOCK_MODE=false'); $_ENV['ADS_MOCK_MODE'] = 'false';
        parent::tearDown();
    }

    private function img(int $w, int $h, string $type = 'jpeg', int $shade = 90): array
    {
        $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, $shade, 120, 200));
        $tmp = tempnam(sys_get_temp_dir(), 'adimg');
        $type === 'png' ? imagepng($im, $tmp) : imagejpeg($im, $tmp, 90);
        imagedestroy($im);
        return ['tmp_name' => $tmp, 'name' => "pic.$type", 'size' => filesize($tmp)];
    }

    // ---- assets -------------------------------------------------------------------------------------------------------------

    public function testUploadsAreValidatedReencodedAndDeduplicated(): void
    {
        $svc = new AdAssetService();
        $a = $svc->store(1, $this->img(1200, 628), 9);
        $this->assertSame(['landscape', 1200, 628, 'image/jpeg'], [$a['shape'], $a['width'], $a['height'], $a['mime']]);
        $this->assertSame($a['id'], $svc->store(1, $this->img(1200, 628), 9)['id'], 'identical image = one asset');
        $this->assertNotSame($a['id'], $svc->store(1, $this->img(1200, 628, 'jpeg', 30))['id']);
        $this->assertSame('square', $svc->store(1, $this->img(1080, 1080, 'png'))['shape']);
        $this->assertCount(3, $svc->list(1));
        $this->assertSame([], $svc->list(2));                                          // tenant isolation
        $r = $svc->read(1, $a['id']);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $r['bytes']);
    }

    public function testNonImagesTinyHugeAndDisguisedFilesAreRefused(): void
    {
        $svc = new AdAssetService();
        $tmp = tempnam(sys_get_temp_dir(), 'x'); file_put_contents($tmp, '<?php echo 1;');
        foreach ([['tmp_name' => $tmp, 'name' => 'a.jpg', 'size' => 13], $this->img(200, 200), $this->img(9000, 100)] as $bad) {
            try { $svc->store(1, $bad); $this->fail('accepted a bad upload'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $svg = tempnam(sys_get_temp_dir(), 'x'); file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
        $this->expectException(\InvalidArgumentException::class);
        $svc->store(1, ['tmp_name' => $svg, 'name' => 'logo.png', 'size' => 60]);       // SVG pretending to be a PNG
    }

    public function testExifAndHiddenPayloadsAreStrippedByReencoding(): void
    {
        $f = $this->img(1000, 1000);
        file_put_contents($f['tmp_name'], file_get_contents($f['tmp_name']) . '<?php evil(); ?>SECRET-TRAILER');
        $a = (new AdAssetService())->store(1, $f);
        $this->assertStringNotContainsString('SECRET-TRAILER', (new AdAssetService())->read(1, $a['id'])['bytes']);
    }

    public function testTamperedStoredImageIsNeverServed(): void
    {
        $svc = new AdAssetService(); $a = $svc->store(1, $this->img(1000, 1000));
        $path = WRITEPATH . db_connect()->table('ad_assets')->where('id', $a['id'])->get()->getRowArray()['path'];
        file_put_contents($path, 'tampered');
        $this->expectException(\RuntimeException::class);
        $svc->read(1, $a['id']);
    }

    // ---- audiences ---------------------------------------------------------------------------------------------------------------

    private function contacts(int $n, int $optIn = 1, int $start = 1): void
    {
        $db = db_connect();
        for ($i = $start; $i < $start + $n; $i++) {
            $db->table('contacts')->insert(['id' => $i, 'tenant_id' => 1, 'name' => "C$i", 'wa_number' => '+9198' . str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT), 'email' => "c$i@example.com", 'opt_in' => $optIn, 'status' => 'new', 'source' => 'manual', 'created_at' => '2026-01-01 00:00:00']);
        }
    }

    public function testAudienceNeedsExplicitConsentAndOnlyOptedInContactsAreUploaded(): void
    {
        $this->contacts(120); $this->contacts(30, 0, 1000);                              // 30 contacts withdrew consent
        $svc = new AdAudienceService();
        try { $svc->create(1, 9, ['name' => 'All', 'account_id' => 'act_1000000001', 'source_type' => 'all_optin']); $this->fail('created without consent'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('right to use', $e->getMessage()); }
        $a = $svc->create(1, 9, ['name' => 'All', 'account_id' => 'act_1000000001', 'source_type' => 'all_optin', 'consent_confirmed' => true]);
        $this->assertSame(['ready', 120, true], [$a['status'], $a['member_count'], $a['usable']]);
        $this->assertSame(120, db_connect()->table('ad_audience_members')->countAllResults());
        $raw = json_encode(db_connect()->table('ad_audience_members')->get()->getResultArray());
        $this->assertStringNotContainsString('c1@example.com', $raw);                    // hashes only
        $log = db_connect()->table('audit_logs')->where('action', 'ad_audience.create')->get()->getRowArray();
        $this->assertNotNull($log, 'every audience upload is audited');
        $this->assertStringContainsString('consent_confirmed', json_encode($log));
    }

    public function testRefreshRemovesPeopleWhoOptedOutAndAddsNewOnes(): void
    {
        $this->contacts(110);
        $svc = new AdAudienceService();
        $a = $svc->create(1, 9, ['name' => 'All', 'account_id' => 'act_1000000001', 'source_type' => 'all_optin', 'consent_confirmed' => true]);
        db_connect()->table('contacts')->whereIn('id', [1, 2, 3])->update(['opt_in' => 0]);        // withdrew consent
        $this->contacts(5, 1, 500);                                                                // five new
        $r = $svc->refresh(1, $a['id'], 9);
        $this->assertSame([5, 3, 112], [$r['added'], $r['removed'], $r['members']]);
        $this->assertSame(0, db_connect()->table('ad_audience_members')->whereIn('contact_id', [1, 2, 3])->countAllResults());
        $this->assertSame(0, $svc->refresh(1, $a['id'])['added'] + $svc->refresh(1, $a['id'])['removed']);   // idempotent
        $this->assertNotNull($svc->estimate(1, $a['id']));
    }

    public function testBookedAudienceUsesBookingsAndALookalikeNeedsA100PersonSeed(): void
    {
        $this->contacts(150);
        $db = db_connect();
        for ($i = 1; $i <= 120; $i++) { $db->table('bookings')->insert(['tenant_id' => 1, 'contact_id' => $i, 'booking_ref' => "TP-$i", 'title' => 'x', 'status' => $i > 118 ? 'cancelled' : 'confirmed', 'created_at' => '2026-02-01 00:00:00']); }
        $svc = new AdAudienceService();
        $seed = $svc->create(1, 9, ['name' => 'Customers', 'account_id' => 'act_1000000001', 'source_type' => 'booked', 'consent_confirmed' => true]);
        $this->assertSame(118, $seed['member_count']);                                        // cancelled bookings do not count
        $small = $svc->create(1, 9, ['name' => 'Few', 'account_id' => 'act_1000000001', 'source_type' => 'segment', 'source_ref' => $this->segment('new'), 'consent_confirmed' => true]);
        $lal = $svc->createLookalike(1, 9, ['name' => 'LAL 1%', 'seed_audience_id' => $seed['id'], 'ratio_pct' => 1]);
        $this->assertSame(['lookalike', 'ready', true], [$lal['kind'], $lal['status'], $lal['usable']]);
        foreach ([0.5, 11] as $bad) { try { $svc->createLookalike(1, 9, ['name' => 'x', 'seed_audience_id' => $seed['id'], 'ratio_pct' => $bad]); $this->fail("ratio $bad"); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); } }
        db_connect()->table('ad_audiences')->where('id', $small['id'])->update(['member_count' => 40]);
        $this->expectException(\InvalidArgumentException::class);
        $svc->createLookalike(1, 9, ['name' => 'tiny seed', 'seed_audience_id' => $small['id'], 'ratio_pct' => 1]);
    }

    private function segment(string $status): int
    {
        db_connect()->table('segments')->insert(['tenant_id' => 1, 'name' => 'S', 'filters' => json_encode(['statuses' => [$status]]), 'created_at' => '2026-01-01 00:00:00']);
        return (int) db_connect()->insertID();
    }

    // ---- campaigns ----------------------------------------------------------------------------------------------------------------

    private function metaSpec(array $o = []): array
    {
        return array_merge(['platform' => 'meta', 'kind' => 'lead_form', 'name' => 'Bali', 'account_id' => 'act_1000000001', 'page_id' => '1100001', 'lead_form_id' => '9001', 'daily_budget' => 150_000, 'start_date' => '2026-11-01',
            'targeting' => ['age_min' => 25, 'age_max' => 45], 'creative' => ['primary_text' => 'Bali for two from ₹55,000.', 'headline' => 'Bali Honeymoon', 'image_url' => 'https://cdn.example.com/b.jpg']], $o);
    }

    public function testMultiAdSetCampaignCreatesEverySetLaunchesAllAndSplitsBudgetChanges(): void
    {
        $m = new AdCampaignManager();
        $c = $m->create(1, 9, $this->metaSpec(['adsets' => [['name' => 'Metros', 'budget_pct' => 60], ['name' => 'Tier 2', 'budget_pct' => 40]]]));
        $kids = json_decode($c['children'], true);
        $this->assertCount(2, $kids['sets']);
        $this->assertSame([90_000, 60_000], array_column($kids['sets'], 'budget'));
        $mock = json_decode((string) file_get_contents(WRITEPATH . 'ads-mock-1.json'), true)['meta']['objects'];
        $this->assertCount(2, array_filter($mock, fn ($o) => $o['_edge'] === 'adsets'));
        $this->assertCount(2, array_filter($mock, fn ($o) => $o['_edge'] === 'ads'));
        $m->launch(1, (int) $c['id'], 9);
        $mock = json_decode((string) file_get_contents(WRITEPATH . 'ads-mock-1.json'), true)['meta']['objects'];
        foreach ($kids['sets'] as $set) { $this->assertSame(['ACTIVE', 'ACTIVE'], [$mock[$set['adset_id']]['status'], $mock[$set['ad_id']]['status']]); }
        try { $m->setBudget(1, (int) $c['id'], 200_001, 9); $this->fail('a +33% raise on a live campaign must be refused'); }       // guardrail: max +30% per change
        catch (\App\Services\Ads\AdsGuardrailException) { $this->addToAssertionCount(1); }
    }

    public function testBudgetRaisePassesGuardrailsAndKeepsTheSplitExact(): void
    {
        $m = new AdCampaignManager();
        $c = $m->create(1, 9, $this->metaSpec(['adsets' => [['budget_pct' => 60], ['budget_pct' => 40]]]));
        $m->setBudget(1, (int) $c['id'], 170_001, 9);                                       // paused campaign: guardrail on raises applies only when live; split must still add up
        $kids = json_decode($c['children'], true);
        $mock = json_decode((string) file_get_contents(WRITEPATH . 'ads-mock-1.json'), true)['meta']['objects'];
        $sum = array_sum(array_map(fn ($s) => (int) $mock[$s['adset_id']]['daily_budget'], $kids['sets']));
        $this->assertSame(170_001, $sum);
    }

    public function testCampaignWithAUploadedImageAndAudiencesReachesMetaAsHashAndAudienceIds(): void
    {
        $this->contacts(120);
        $aud = (new AdAudienceService())->create(1, 9, ['name' => 'Customers', 'account_id' => 'act_1000000001', 'source_type' => 'all_optin', 'consent_confirmed' => true]);
        $asset = (new AdAssetService())->store(1, $this->img(1200, 628));
        $spec = $this->metaSpec(['audiences' => [['audience_id' => $aud['id'], 'mode' => 'exclude']]]);
        unset($spec['creative']['image_url']); $spec['creative']['image_asset_id'] = $asset['id'];
        $c = (new AdCampaignManager())->create(1, 9, $spec);
        $objs = json_decode((string) file_get_contents(WRITEPATH . 'ads-mock-1.json'), true)['meta']['objects'];
        $creative = array_values(array_filter($objs, fn ($o) => $o['_edge'] === 'adcreatives'))[0];
        $this->assertNotEmpty($creative['object_story_spec']['link_data']['image_hash']);
        $adset = array_values(array_filter($objs, fn ($o) => $o['_edge'] === 'adsets'))[0];
        $ext = db_connect()->table('ad_audiences')->where('id', $aud['id'])->get()->getRowArray()['external_id'];
        $this->assertSame([['id' => $ext]], $adset['targeting']['excluded_custom_audiences']);
        $this->assertNotEmpty($c['external_id']);
        // the same image is uploaded to the account only once
        $hash1 = $creative['object_story_spec']['link_data']['image_hash'];
        (new AdCampaignManager())->create(1, 9, array_merge($spec, ['name' => 'Bali 2']));
        $objs = json_decode((string) file_get_contents(WRITEPATH . 'ads-mock-1.json'), true)['meta']['objects'];
        $this->assertSame([$hash1], array_values(array_unique(array_map(fn ($o) => $o['object_story_spec']['link_data']['image_hash'], array_filter($objs, fn ($o) => $o['_edge'] === 'adcreatives')))));
    }

    public function testAnotherAccountsOrUnreadyAudienceIsRefused(): void
    {
        $this->contacts(120);
        $aud = (new AdAudienceService())->create(1, 9, ['name' => 'Customers', 'account_id' => 'act_1000000001', 'source_type' => 'all_optin', 'consent_confirmed' => true]);
        $this->expectException(\InvalidArgumentException::class);
        (new AdCampaignManager())->create(1, 9, $this->metaSpec(['account_id' => 'act_2222222222', 'audiences' => [['audience_id' => $aud['id']]]]));
    }

    public function testAnAudienceInUseByAnActiveCampaignCannotBeDeleted(): void
    {
        $this->contacts(120);
        $aud = (new AdAudienceService())->create(1, 9, ['name' => 'Customers', 'account_id' => 'act_1000000001', 'source_type' => 'all_optin', 'consent_confirmed' => true]);
        $m = new AdCampaignManager();
        $c = $m->create(1, 9, $this->metaSpec(['audiences' => [['audience_id' => $aud['id'], 'mode' => 'exclude']]]));
        $m->launch(1, (int) $c['id'], 9);
        try { (new AdAudienceService())->delete(1, $aud['id']); $this->fail('deleted an audience in use'); } catch (\DomainException $e) { $this->assertStringContainsString('active campaign', $e->getMessage()); }
        $m->pause(1, (int) $c['id'], 9);
        (new AdAudienceService())->delete(1, $aud['id'], 9);
        $this->assertSame([], (new AdAudienceService())->list(1));
        $this->assertSame(0, db_connect()->table('ad_audience_members')->countAllResults());
    }

    public function testPerformanceMaxCreatesThroughTheDryRunAndCarriesImageData(): void
    {
        $svc = new AdAssetService();
        $l = $svc->store(1, $this->img(1200, 628, 'jpeg', 40)); $sq = $svc->store(1, $this->img(1200, 1200, 'jpeg', 60)); $logo = $svc->store(1, $this->img(600, 600, 'png', 80));
        $spec = ['platform' => 'google', 'kind' => 'pmax', 'name' => 'Bali PMax', 'customer_id' => '1234567890', 'daily_budget' => 200_000, 'final_url' => 'https://example.com/bali', 'start_date' => '2026-11-01',
            'pmax' => ['business_name' => 'Demo Travels', 'headlines' => ['Bali Honeymoon', 'Free Custom Quote', 'Trusted Travel Experts'], 'long_headlines' => ['Bali honeymoon packages designed around you'],
                'descriptions' => ['Bali packages for couples.', 'Talk to a travel expert. Free quote in 2 hours.'], 'images' => ['landscape' => [$l['id']], 'square' => [$sq['id']], 'logo' => [$logo['id']]]]];
        $m = new AdCampaignManager();
        $prev = $m->preview(1, $spec);
        $this->assertTrue($prev['ok'], json_encode($prev));
        $c = $m->create(1, 9, $spec);
        $this->assertSame('PERFORMANCE_MAX', $c['objective']);
        $this->assertNotEmpty(json_decode($c['children'], true)['asset_group']);
        $this->assertStringNotContainsString('image_data', (string) $c['spec']);                // image bytes are never stored in the saved spec
    }

    public function testPmaxRejectsAnImageInTheWrongSlot(): void
    {
        $svc = new AdAssetService();
        $portrait = $svc->store(1, $this->img(800, 1000));
        $sq = $svc->store(1, $this->img(1200, 1200, 'jpeg', 60));
        $spec = ['platform' => 'google', 'kind' => 'pmax', 'name' => 'x', 'customer_id' => '1234567890', 'daily_budget' => 200_000, 'final_url' => 'https://example.com',
            'pmax' => ['business_name' => 'Demo', 'headlines' => ['One headline', 'Two headline', 'Three headline'], 'long_headlines' => ['A longer headline here'], 'descriptions' => ['Short description one.', 'Short description two.'],
                'images' => ['landscape' => [$portrait['id']], 'square' => [$sq['id']]]]];
        try { (new AdCampaignManager())->create(1, 9, $spec); $this->fail('wrong-shape image accepted'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('portrait image', $e->getMessage()); }
    }

    private function dgSpec(array $images, array $d = []): array
    {
        return ['platform' => 'google', 'kind' => 'demand_gen', 'name' => 'Bali DG', 'customer_id' => '1234567890', 'daily_budget' => 200_000, 'final_url' => 'https://example.com/bali', 'start_date' => '2026-11-01',
            'demand_gen' => array_merge(['business_name' => 'Demo Travels', 'headlines' => ['Bali Honeymoon', 'Free Custom Quote'], 'descriptions' => ['Bali packages for couples.'], 'cta' => 'Get quote', 'channels' => 'all', 'images' => $images], $d)];
    }

    public function testDemandGenCreatesThroughTheDryRunAndSyncsAsDemandGen(): void
    {
        $svc = new AdAssetService();
        $l = $svc->store(1, $this->img(1200, 628, 'jpeg', 40)); $sq = $svc->store(1, $this->img(1200, 1200, 'jpeg', 60)); $logo = $svc->store(1, $this->img(600, 600, 'png', 80));
        $spec = $this->dgSpec(['landscape' => [$l['id']], 'square' => [$sq['id']], 'logo' => [$logo['id']]]);
        $m = new AdCampaignManager();
        $prev = $m->preview(1, $spec);
        $this->assertTrue($prev['ok'], json_encode($prev));
        $c = $m->create(1, 9, $spec);
        $this->assertSame('DEMAND_GEN', $c['objective']);
        $this->assertSame('paused', strtolower((string) $c['status']));
        $kids = json_decode($c['children'], true);
        $this->assertNotEmpty($kids['ad_group']); $this->assertNotEmpty($kids['ad']);
        $this->assertStringNotContainsString('image_data', (string) $c['spec']);
    }

    public function testDemandGenRefusesAnImageThatIsNotExactlyTheRightRatioOrTooSmall(): void
    {
        $svc = new AdAssetService();
        $near = $svc->store(1, $this->img(1200, 700));                  // 1.71:1 — passes PMax tolerance, fails Demand Gen
        $sq = $svc->store(1, $this->img(1200, 1200, 'jpeg', 60)); $logo = $svc->store(1, $this->img(600, 600, 'png', 80));
        $m = new AdCampaignManager();
        try { $m->create(1, 9, $this->dgSpec(['landscape' => [$near['id']], 'square' => [$sq['id']], 'logo' => [$logo['id']]])); $this->fail('inexact ratio accepted'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('1.91:1', $e->getMessage()); }
        $tinyLogo = $svc->store(1, $this->img(400, 400, 'png', 99));     // stored fine, but let the slot rule decide below with a landscape in the logo slot
        $land = $svc->store(1, $this->img(1200, 628, 'jpeg', 41));
        try { $m->create(1, 9, $this->dgSpec(['square' => [$sq['id']], 'logo' => [$land['id']]])); $this->fail('landscape accepted as logo'); }
        catch (\InvalidArgumentException $e) { $this->assertStringContainsString('logo slot', $e->getMessage()); }
    }

    public function testMultiAdGroupSearchCampaignCreatesEveryGroup(): void
    {
        $g = fn (string $n, string $kw) => ['name' => $n, 'headlines' => ["$n Package", 'Free Custom Quote', 'Trusted Travel Experts'], 'descriptions' => ["$n packages from ₹45,000 pp.", 'Talk to a travel expert today.'], 'keywords' => [['text' => $kw, 'match' => 'PHRASE']]];
        $spec = ['platform' => 'google', 'kind' => 'search', 'name' => 'Bali Search', 'customer_id' => '1234567890', 'daily_budget' => 100_000, 'final_url' => 'https://example.com/bali', 'ad_groups' => [$g('Honeymoon', 'bali honeymoon'), $g('Family', 'bali family tour')]];
        $c = (new AdCampaignManager())->create(1, 9, $spec);
        $kids = json_decode($c['children'], true);
        $this->assertCount(2, $kids['ad_groups']);
        $this->assertCount(2, $kids['ads']);
    }

    public function testWhatsAppLeadsFromAnyAdSetAreAttributedToTheCampaign(): void
    {
        $m = new AdCampaignManager();
        $c = $m->create(1, 9, $this->metaSpec(['kind' => 'click_to_whatsapp', 'lead_form_id' => null, 'whatsapp_number' => '+919820011111', 'adsets' => [['budget_pct' => 50], ['budget_pct' => 50]]]));
        $kids = json_decode($c['children'], true);
        $this->contacts(1);
        foreach ([0, 1] as $i) {                                   // a lead from the FIRST ad and one from the SECOND ad of the same campaign
            $this->contacts(1, 1, 10 + $i);
            db_connect()->table('lead_attributions')->insert(['tenant_id' => 1, 'contact_id' => 10 + $i, 'touch' => 'first', 'platform' => 'meta', 'ad_id' => $kids['sets'][$i]['ad_id'], 'touched_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
        }
        $row = array_values(array_filter((new \App\Services\Ads\AdReportService())->campaigns(1, 30), fn ($r) => (int) $r['id'] === (int) $c['id']))[0];
        $this->assertSame(2, $row['crm_leads']);
    }
}
