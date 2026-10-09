<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Ads\AdAssetService;
use App\Services\Ads\AdAudienceService;
use App\Services\Ads\GooglePlanBuilder;
use App\Services\Ads\MetaPlanBuilder;
use App\Services\Ads\DemandGenPlanBuilder;
use App\Services\Ads\PmaxPlanBuilder;
use PHPUnit\Framework\TestCase;

/** Contract tests for multi-ad-set / multi-ad-group campaigns, audiences in targeting, uploaded images and Performance Max. */
final class AdGapsPlansTest extends TestCase
{
    private function meta(array $o = []): array
    {
        return array_merge(['platform' => 'meta', 'kind' => 'lead_form', 'name' => 'Bali', 'account_id' => 'act_123', 'page_id' => '555', 'lead_form_id' => '9001', 'daily_budget' => 150_001, 'start_date' => '2026-11-01',
            'targeting' => ['age_min' => 25, 'age_max' => 45], 'creative' => ['primary_text' => 'Bali for two from ₹55,000.', 'headline' => 'Bali Honeymoon', 'image_url' => 'https://cdn.example.com/b.jpg']], $o);
    }

    private function now(): int { return strtotime('2026-10-08 12:00:00 UTC'); }

    // ---- multi ad set ---------------------------------------------------------------------------------------------

    public function testSingleSpecStillProducesExactlyOneSetAndTheLegacyKeys(): void
    {
        $p = MetaPlanBuilder::plan($this->meta(), $this->now());
        $this->assertCount(1, $p['sets']);
        $this->assertSame($p['adset'], $p['sets'][0]['adset']);
        $this->assertSame(150_001, $p['adset']['daily_budget']);
        $this->assertSame('Bali · Ad set', $p['adset']['name']);
    }

    public function testBudgetIsSplitByShareAndAlwaysAddsUpToTheTotalExactly(): void
    {
        $s = $this->meta(['adsets' => [['name' => 'Metros', 'budget_pct' => 50], ['name' => 'Tier 2', 'budget_pct' => 30], ['name' => 'Retarget', 'budget_pct' => 20]]]);
        $shares = MetaPlanBuilder::shares($s);
        $this->assertSame(150_001, array_sum($shares));                          // remainder paise go to the first set
        $this->assertSame([75_001, 45_000, 30_000], $shares);
        $equal = MetaPlanBuilder::shares($this->meta(['adsets' => [[], [], []]]));
        $this->assertSame(150_001, array_sum($equal));
        $this->assertNull(MetaPlanBuilder::shares($this->meta(['adsets' => [['budget_pct' => 60], ['budget_pct' => 60]]])));      // not 100
        $this->assertNull(MetaPlanBuilder::shares($this->meta(['adsets' => [['budget_pct' => 100], ['budget_pct' => null]]])));   // partial
    }

    public function testEachSetGetsItsOwnTargetingCreativeAndAudiences(): void
    {
        $s = $this->meta(['audiences' => [['id' => '777', 'mode' => 'exclude']], 'adsets' => [
            ['name' => 'Metros', 'budget_pct' => 60, 'targeting' => ['cities' => [['key' => '2418956']]], 'audiences' => [['id' => '888', 'mode' => 'include']]],
            ['name' => 'Wide', 'budget_pct' => 40, 'targeting' => ['age_min' => 30, 'age_max' => 55], 'creative' => ['headline' => 'Bali From ₹55k']]]]);
        $p = MetaPlanBuilder::plan($s, $this->now());
        $this->assertCount(2, $p['sets']);
        [$a, $b] = [$p['sets'][0], $p['sets'][1]];
        $this->assertSame([90_001, 60_000], [$a['budget'], $b['budget']]);
        $this->assertSame('Bali · Metros', $a['adset']['name']);
        $this->assertSame([['key' => '2418956', 'radius' => 25, 'distance_unit' => 'kilometer']], $a['adset']['targeting']['geo_locations']['cities']);
        $this->assertSame([['id' => '888']], $a['adset']['targeting']['custom_audiences']);
        $this->assertSame([['id' => '777']], $a['adset']['targeting']['excluded_custom_audiences']);    // campaign-wide exclusion reaches every set
        $this->assertSame([30, 55], [$b['adset']['targeting']['age_min'], $b['adset']['targeting']['age_max']]);
        $this->assertArrayNotHasKey('custom_audiences', $b['adset']['targeting']);
        $this->assertSame('Bali From ₹55k', $b['creative']['object_story_spec']['link_data']['name']);
        $this->assertSame('Bali Honeymoon', $a['creative']['object_story_spec']['link_data']['name']);   // inherits the shared creative
        $this->assertNotSame($a['creative']['name'], $b['creative']['name']);
    }

    public function testAdSetValidation(): void
    {
        $this->assertSame([], MetaPlanBuilder::validate($this->meta()));
        $this->assertNotSame([], MetaPlanBuilder::validate($this->meta(['adsets' => array_fill(0, 6, [])])));                                            // more than 5
        $e = implode(' ', MetaPlanBuilder::validate($this->meta(['daily_budget' => 30_000, 'adsets' => [[], [], []]])));                                  // ₹100 each = 3 × 10,000: exactly OK
        $this->assertStringNotContainsString('minimum', $e);
        $e = implode(' ', MetaPlanBuilder::validate($this->meta(['daily_budget' => 25_000, 'adsets' => [[], [], []]])));
        $this->assertStringContainsString('minimum is ₹100', $e);
        $this->assertStringContainsString('add up to exactly 100%', implode(' ', MetaPlanBuilder::validate($this->meta(['adsets' => [['budget_pct' => 70], ['budget_pct' => 70]]]))));
        $this->assertStringContainsString('age range', implode(' ', MetaPlanBuilder::validate($this->meta(['adsets' => [['targeting' => ['age_min' => 16, 'age_max' => 30]]]]))));
    }

    // ---- uploaded images ----------------------------------------------------------------------------------------------------

    public function testAnUploadedImageIsReferencedByItsMetaHashAndNeedsNoPublicUrl(): void
    {
        $s = $this->meta(); unset($s['creative']['image_url']);
        $this->assertStringContainsString('Add an image', implode(' ', MetaPlanBuilder::validate($s)));
        $s['creative']['image_asset_id'] = 12;
        $this->assertSame([], MetaPlanBuilder::validate($s));
        $s['creative']['image_hash'] = 'abc123hash';
        $link = MetaPlanBuilder::plan($s, $this->now())['creative']['object_story_spec']['link_data'];
        $this->assertSame('abc123hash', $link['image_hash']);
        $this->assertArrayNotHasKey('picture', $link);
    }

    public function testImageShapesAreClassifiedByRatio(): void
    {
        $this->assertSame('landscape', AdAssetService::shape(1200, 628));
        $this->assertSame('square', AdAssetService::shape(1080, 1080));
        $this->assertSame('portrait', AdAssetService::shape(1080, 1350));
        $this->assertSame('other', AdAssetService::shape(1000, 700));
    }

    // ---- audiences in targeting + hashing ---------------------------------------------------------------------------------------

    public function testAudienceTargetingIgnoresNonNumericIdsAndSeparatesIncludeFromExclude(): void
    {
        $t = MetaPlanBuilder::targeting(['age_min' => 25], [['id' => '1', 'mode' => 'include'], ['id' => 'x; DROP', 'mode' => 'include'], ['id' => '2', 'mode' => 'exclude'], ['id' => '1']]);
        $this->assertSame([['id' => '1']], $t['custom_audiences']);              // junk dropped, duplicates collapsed
        $this->assertSame([['id' => '2']], $t['excluded_custom_audiences']);
        $this->assertArrayNotHasKey('custom_audiences', MetaPlanBuilder::targeting([]));
    }

    public function testContactDetailsAreNormalisedThenHashedBeforeTheyLeaveTheServer(): void
    {
        $this->assertSame(hash('sha256', '919876543210'), AdAudienceService::hashPhone('+91 98765-43210'));
        $this->assertSame(AdAudienceService::hashPhone('9876543210'), AdAudienceService::hashPhone('09876543210'));
        $this->assertNull(AdAudienceService::hashPhone('12345'));
        $this->assertSame(hash('sha256', 'asha@example.com'), AdAudienceService::hashEmail('  Asha@Example.COM '));
        $this->assertNull(AdAudienceService::hashEmail('not-an-email'));
        $ids = AdAudienceService::identifiers([['id' => 1, 'wa_number' => '9876543210', 'email' => null], ['id' => 2, 'wa_number' => null, 'email' => 'b@x.in'], ['id' => 3, 'wa_number' => '12', 'email' => 'bad']]);
        $this->assertSame([1, 2], array_keys($ids));                              // a contact with nothing usable is dropped
        $this->assertStringNotContainsString('9876543210', json_encode($ids));    // no raw values anywhere
    }

    // ---- Google: multiple ad groups ------------------------------------------------------------------------------------------------

    private function google(array $o = []): array
    {
        $g = ['headlines' => ['Bali Honeymoon', 'Free Custom Quote', 'Trusted Travel Experts'], 'descriptions' => ['Bali packages for couples from ₹55,000 pp.', 'Talk to a travel expert. Free quote in 2 hours.'], 'keywords' => [['text' => 'bali honeymoon package', 'match' => 'PHRASE']]];
        return array_merge(['platform' => 'google', 'kind' => 'search', 'name' => 'Bali Search', 'customer_id' => '1234567890', 'daily_budget' => 100_000, 'final_url' => 'https://example.com/bali', 'start_date' => '2026-11-01'], $g, $o);
    }

    public function testSingleGroupSearchPlanIsUnchanged(): void
    {
        $ops = GooglePlanBuilder::plan($this->google());
        $this->assertSame(['campaignBudgetOperation', 'campaignOperation', 'adGroupOperation', 'adGroupAdOperation', 'adGroupCriterionOperation'], array_slice(array_map(fn ($o) => array_key_first($o), $ops), 0, 5));
        $this->assertSame('customers/1234567890/adGroups/-3', $ops[2]['adGroupOperation']['create']['resourceName']);
        $this->assertSame([], GooglePlanBuilder::validate($this->google())['errors']);
    }

    public function testEachAdGroupGetsItsOwnTempIdsAdsAndKeywords(): void
    {
        $s = $this->google(['ad_groups' => [
            ['name' => 'Honeymoon'] + $this->google(),
            ['name' => 'Family', 'final_url' => 'https://example.com/bali-family', 'headlines' => ['Bali Family Tour', 'Kids Stay Free', 'Family Friendly Resorts'], 'descriptions' => ['Family packages from ₹45,000 pp.', 'Kid-friendly hotels and transfers.'], 'keywords' => [['text' => 'bali family tour', 'match' => 'EXACT'], ['text' => 'bali with kids', 'match' => 'PHRASE']]]]]);
        $this->assertSame([], GooglePlanBuilder::validate($s)['errors']);
        $ops = GooglePlanBuilder::plan($s);
        $groups = array_values(array_filter($ops, fn ($o) => isset($o['adGroupOperation'])));
        $this->assertSame(['customers/1234567890/adGroups/-3', 'customers/1234567890/adGroups/-4'], array_map(fn ($o) => $o['adGroupOperation']['create']['resourceName'], $groups));
        $ads = array_values(array_filter($ops, fn ($o) => isset($o['adGroupAdOperation'])));
        $this->assertSame('customers/1234567890/adGroups/-4', $ads[1]['adGroupAdOperation']['create']['adGroup']);
        $this->assertSame(['https://example.com/bali-family'], $ads[1]['adGroupAdOperation']['create']['ad']['finalUrls']);
        $this->assertSame(['https://example.com/bali'], $ads[0]['adGroupAdOperation']['create']['ad']['finalUrls']);      // inherits the campaign page
        $kw4 = array_filter($ops, fn ($o) => ($o['adGroupCriterionOperation']['create']['adGroup'] ?? '') === 'customers/1234567890/adGroups/-4');
        $this->assertCount(2, $kw4);
        $this->assertSame(1, count(array_filter($ops, fn ($o) => isset($o['campaignOperation']))), 'one campaign, one budget');
    }

    public function testGroupValidationNamesTheGroupAtFault(): void
    {
        $bad = $this->google(['ad_groups' => [$this->google(), ['headlines' => ['one'], 'descriptions' => ['x'], 'keywords' => []]]]);
        $e = implode(' | ', GooglePlanBuilder::validate($bad)['errors']);
        $this->assertStringContainsString('Ad group 2: Provide 3 to 15 headlines', $e);
        $this->assertStringNotContainsString('Ad group 1', $e);
        $this->assertStringContainsString('at most 5 ad groups', implode(' ', GooglePlanBuilder::validate($this->google(['ad_groups' => array_fill(0, 6, $this->google())]))['errors']));
    }

    public function testIdsCollectEveryGroupAndAd(): void
    {
        $ids = GooglePlanBuilder::ids(['mutateOperationResponses' => [['campaignBudgetResult' => ['resourceName' => 'b/1']], ['campaignResult' => ['resourceName' => 'c/2']], ['adGroupResult' => ['resourceName' => 'g/3']], ['adGroupAdResult' => ['resourceName' => 'a/4']], ['adGroupResult' => ['resourceName' => 'g/5']], ['adGroupAdResult' => ['resourceName' => 'a/6']]]]);
        $this->assertSame(['g/3', 'g/5'], $ids['ad_groups']);
        $this->assertSame(['a/4', 'a/6'], $ids['ads']);
        $this->assertSame(['g/3', 'a/4'], [$ids['ad_group'], $ids['ad']]);
    }

    // ---- Performance Max ----------------------------------------------------------------------------------------------------------

    private function pmax(array $o = []): array
    {
        return array_merge(['platform' => 'google', 'kind' => 'pmax', 'name' => 'Bali PMax', 'customer_id' => '1234567890', 'daily_budget' => 200_000, 'final_url' => 'https://example.com/bali', 'start_date' => '2026-11-01',
            'pmax' => ['business_name' => 'Demo Travels', 'headlines' => ['Bali Honeymoon', 'Free Custom Quote', 'Trusted Travel Experts'], 'long_headlines' => ['Bali honeymoon packages designed around you, from ₹55,000 pp'],
                'descriptions' => ['Bali packages for couples.', 'Talk to a travel expert. Free quote in 2 hours.'], 'images' => ['landscape' => [11], 'square' => [12], 'logo' => [13]], 'image_data' => [11 => 'QUJD', 12 => 'REVG', 13 => 'R0hJ']]], $o);
    }

    public function testPmaxPlanIsOneAtomicMutateWithAssetGroupAndLinks(): void
    {
        $this->assertSame([], GooglePlanBuilder::validate($this->pmax())['errors']);
        $ops = GooglePlanBuilder::plan($this->pmax());
        $campaign = $ops[1]['campaignOperation']['create'];
        $this->assertSame(['PERFORMANCE_MAX', 'PAUSED'], [$campaign['advertisingChannelType'], $campaign['status']]);
        $this->assertArrayHasKey('maximizeConversions', $campaign);
        $this->assertSame('customers/1234567890/assetGroups/-3', $ops[2]['assetGroupOperation']['create']['resourceName']);
        $this->assertSame('PAUSED', $ops[2]['assetGroupOperation']['create']['status']);
        $links = array_values(array_filter($ops, fn ($o) => isset($o['assetGroupAssetOperation'])));
        $count = array_count_values(array_map(fn ($o) => $o['assetGroupAssetOperation']['create']['fieldType'], $links));
        $this->assertSame(['HEADLINE' => 3, 'LONG_HEADLINE' => 1, 'DESCRIPTION' => 2, 'BUSINESS_NAME' => 1, 'MARKETING_IMAGE' => 1, 'SQUARE_MARKETING_IMAGE' => 1, 'LOGO' => 1], $count);
        $imgs = array_values(array_filter($ops, fn ($o) => isset($o['assetOperation']['create']['imageAsset'])));
        $this->assertSame(['QUJD', 'REVG', 'R0hJ'], array_map(fn ($o) => $o['assetOperation']['create']['imageAsset']['data'], $imgs));
        // every temp id is unique and every link points at an asset created in the same request
        $created = array_map(fn ($o) => $o['assetOperation']['create']['resourceName'], array_filter($ops, fn ($o) => isset($o['assetOperation'])));
        $this->assertSame(count($created), count(array_unique($created)));
        foreach ($links as $l) { $this->assertContains($l['assetGroupAssetOperation']['create']['asset'], $created); }
    }

    public function testOneImageUsedInTwoSlotsIsUploadedOnce(): void
    {
        $s = $this->pmax(); $s['pmax']['images'] = ['landscape' => [11], 'square' => [12], 'logo' => [12]];
        $ops = GooglePlanBuilder::plan($s);
        $this->assertCount(2, array_filter($ops, fn ($o) => isset($o['assetOperation']['create']['imageAsset'])));
        $by = [];
        foreach ($ops as $o) { if (isset($o['assetGroupAssetOperation'])) { $by[$o['assetGroupAssetOperation']['create']['fieldType']][] = $o['assetGroupAssetOperation']['create']['asset']; } }
        $this->assertSame($by['LOGO'], $by['SQUARE_MARKETING_IMAGE']);     // the same uploaded asset serves both slots
    }

    public function testPmaxValidationEnforcesGoogleAssetRequirements(): void
    {
        $e = implode(' | ', GooglePlanBuilder::validate($this->pmax(['pmax' => ['business_name' => '', 'headlines' => ['a', 'b'], 'long_headlines' => [], 'descriptions' => [str_repeat('x', 80), str_repeat('y', 80)], 'images' => ['landscape' => [], 'square' => [12]]]]))['errors']);
        foreach (['Provide 3 to 15 headlines', 'Provide 1 to 5 long headlines', 'business name', 'At least one description must be 60', 'Add 1 to 20 landscape images'] as $needle) { $this->assertStringContainsString($needle, $e, $needle); }
        $this->assertSame([11 => 'landscape', 12 => 'square', 13 => 'logo'], PmaxPlanBuilder::imageRefs($this->pmax()));
        $this->assertStringContainsString('Search, Performance Max or Demand Gen', implode(' ', GooglePlanBuilder::validate($this->pmax(['kind' => 'video']))['errors']));
    }

    // ---- Demand Gen ----------------------------------------------------------------------------------------------------------------

    private function dg(array $o = [], array $d = []): array
    {
        return array_merge(['platform' => 'google', 'kind' => 'demand_gen', 'name' => 'Bali DG', 'customer_id' => '1234567890', 'daily_budget' => 200_000, 'final_url' => 'https://example.com/bali', 'start_date' => '2026-11-01',
            'demand_gen' => array_merge(['business_name' => 'Demo Travels', 'headlines' => ['Bali Honeymoon', 'Free Custom Quote'], 'descriptions' => ['Bali packages for couples.'], 'cta' => 'Get quote', 'channels' => 'all', 'bidding' => 'conversions',
                'images' => ['landscape' => [11], 'square' => [12], 'portrait' => [14], 'logo' => [13]], 'image_data' => [11 => 'QUJD', 12 => 'REVG', 13 => 'R0hJ', 14 => 'S0xN']], $d)], $o);
    }

    public function testDemandGenPlanIsOneAtomicPausedMutateWithMultiAssetAd(): void
    {
        $this->assertSame([], GooglePlanBuilder::validate($this->dg())['errors']);
        $ops = GooglePlanBuilder::plan($this->dg());
        $camp = $ops[1]['campaignOperation']['create'];
        $this->assertSame(['DEMAND_GEN', 'PAUSED', 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING'], [$camp['advertisingChannelType'], $camp['status'], $camp['containsEuPoliticalAdvertising']]);
        $this->assertArrayHasKey('maximizeConversions', $camp);
        $this->assertSame('ALL_CHANNELS', $ops[2]['adGroupOperation']['create']['demandGenAdGroupSettings']['channelControls']['channelStrategy']);
        $ad = array_values(array_filter($ops, fn ($o) => isset($o['adGroupAdOperation'])))[0]['adGroupAdOperation']['create']['ad'];
        $m = $ad['demandGenMultiAssetAd'];
        $this->assertSame(['https://example.com/bali'], $ad['finalUrls']);
        $this->assertSame([['text' => 'Bali Honeymoon'], ['text' => 'Free Custom Quote']], $m['headlines']);
        $this->assertSame(['Demo Travels', 'Get quote'], [$m['businessName'], $m['callToActionText']]);
        $this->assertCount(1, $m['marketingImages']); $this->assertCount(1, $m['squareMarketingImages']); $this->assertCount(1, $m['portraitMarketingImages']); $this->assertCount(1, $m['logoImages']);
        $created = array_map(fn ($o) => $o['assetOperation']['create']['resourceName'], array_filter($ops, fn ($o) => isset($o['assetOperation'])));
        $this->assertSame(4, count(array_unique($created)));
        foreach (['marketingImages', 'squareMarketingImages', 'portraitMarketingImages', 'logoImages'] as $f) { $this->assertContains($m[$f][0]['asset'], $created); }
        $this->assertCount(1, array_filter($ops, fn ($o) => isset($o['adGroupCriterionOperation']['create']['location'])));
        $this->assertNotEmpty(array_filter($ops, fn ($o) => isset($o['adGroupCriterionOperation']['create']['language'])));
    }

    public function testDemandGenSelectedChannelsAndClickBidding(): void
    {
        $ops = GooglePlanBuilder::plan($this->dg([], ['channels' => ['youtube_shorts', 'discover', 'bogus'], 'bidding' => 'clicks']));
        $this->assertArrayHasKey('targetSpend', $ops[1]['campaignOperation']['create']);
        $this->assertSame(['youtubeShorts' => true, 'discover' => true], $ops[2]['adGroupOperation']['create']['demandGenAdGroupSettings']['channelControls']['selectedChannels']);
        $this->assertSame('ALL_OWNED_AND_OPERATED_CHANNELS', GooglePlanBuilder::plan($this->dg([], ['channels' => 'owned']))[2]['adGroupOperation']['create']['demandGenAdGroupSettings']['channelControls']['channelStrategy']);
    }

    public function testDemandGenValidationEnforcesGoogleLimits(): void
    {
        $e = implode(' | ', GooglePlanBuilder::validate($this->dg([], ['business_name' => '', 'headlines' => [], 'descriptions' => [str_repeat('d', 95)], 'cta' => 'Buy now!!', 'channels' => [], 'images' => ['portrait' => [14]]]))['errors']);
        foreach (['business name', 'Provide 1 to 5 headlines', 'button text', 'at least one place', 'at least one landscape', '1 to 5 square logos'] as $needle) { $this->assertStringContainsString($needle, $e, $needle); }
        $this->assertStringContainsString('Provide 1 to 5 headlines', implode(' ', GooglePlanBuilder::validate($this->dg([], ['headlines' => ['a', 'b', 'c', 'd', 'e', 'f']]))['errors']));
        $this->assertStringContainsString('at most 20', implode(' ', GooglePlanBuilder::validate($this->dg([], ['images' => ['landscape' => range(1, 21), 'logo' => [13]]]))['errors']));
        $this->assertSame([11 => 'landscape', 12 => 'square', 14 => 'portrait', 13 => 'logo'], DemandGenPlanBuilder::imageRefs($this->dg()));
    }

    public function testStrictImageRulesAreExactRatioAndMinimumSize(): void
    {
        $this->assertNull(AdAssetService::strictProblem(1200, 628, 'landscape'));
        $this->assertStringContainsString('1.91:1', (string) AdAssetService::strictProblem(1200, 700, 'landscape'));      // 1.71 — fine for PMax tolerance, not for Demand Gen
        $this->assertStringContainsString('at least 600×314', (string) AdAssetService::strictProblem(573, 300, 'landscape'));
        $this->assertNull(AdAssetService::strictProblem(128, 128, 'logo'));
        $this->assertStringContainsString('at least 128×128', (string) AdAssetService::strictProblem(100, 100, 'logo'));
        $this->assertNull(AdAssetService::strictProblem(480, 600, 'portrait'));
        $this->assertNull(AdAssetService::strictProblem(1080, 1920, 'tall'));
    }
}
