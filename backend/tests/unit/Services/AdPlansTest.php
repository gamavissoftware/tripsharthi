<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Ads\AdCopyLint;
use App\Services\Ads\GoogleAdsClient;
use App\Services\Ads\GooglePlanBuilder;
use App\Services\Ads\MetaMarketingClient;
use App\Services\Ads\MetaPlanBuilder;
use App\Services\Ads\AdsApiException;
use PHPUnit\Framework\TestCase;

/** Contract tests: the exact request shapes we send, and how platform errors are classified. */
final class AdPlansTest extends TestCase
{
    private function metaSpec(array $o = []): array
    {
        return $o + ['platform' => 'meta', 'kind' => 'lead_form', 'name' => 'Bali Honeymoon Nov', 'account_id' => 'act_123', 'page_id' => '555', 'lead_form_id' => '9001',
            'daily_budget' => 150_000, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30',
            'targeting' => ['age_min' => 25, 'age_max' => 45, 'cities' => [['key' => '2418956', 'radius' => 40]]],
            'creative' => ['primary_text' => 'Bali for two, from ₹55,000 per person. Get a free custom quote.', 'headline' => 'Bali Honeymoon Packages', 'image_url' => 'https://cdn.example.com/bali.jpg']];
    }

    // ---- Meta ---------------------------------------------------------------------------------------

    public function testMetaLeadFormPlanMatchesTheMarketingApiShape(): void
    {
        $p = MetaPlanBuilder::plan($this->metaSpec(), strtotime('2026-10-08 12:00:00 UTC'));

        $this->assertSame('OUTCOME_LEADS', $p['campaign']['objective']);
        $this->assertSame('PAUSED', $p['campaign']['status']);
        $this->assertSame([], $p['campaign']['special_ad_categories']);           // mandatory, empty for travel
        $this->assertFalse($p['campaign']['is_adset_budget_sharing_enabled']);
        $this->assertArrayNotHasKey('daily_budget', $p['campaign']);               // budget lives on the ad set

        $a = $p['adset'];
        $this->assertSame(150_000, $a['daily_budget']);                            // paise, INR minor units
        $this->assertSame('LEAD_GENERATION', $a['optimization_goal']);
        $this->assertSame('ON_AD', $a['destination_type']);
        $this->assertSame(['page_id' => '555'], $a['promoted_object']);
        $this->assertSame('PAUSED', $a['status']);
        $this->assertSame(1, $a['targeting']['targeting_automation']['advantage_audience']);
        $this->assertSame([['key' => '2418956', 'radius' => 40, 'distance_unit' => 'kilometer']], $a['targeting']['geo_locations']['cities']);
        $this->assertStringEndsWith('+05:30', $a['end_time']);
        $this->assertStringStartsWith('2026-11-30T23:59:59', $a['end_time']);

        $cta = $p['creative']['object_story_spec']['link_data']['call_to_action'];
        $this->assertSame('SIGN_UP', $cta['type']);
        $this->assertSame('9001', $cta['value']['lead_gen_form_id']);
        $this->assertStringContainsString('utm_id={{campaign.id}}', $p['creative']['url_tags']);
        $this->assertSame('PAUSED', $p['ad']['status']);
    }

    public function testMetaClickToWhatsAppPlan(): void
    {
        $s = $this->metaSpec(['kind' => 'click_to_whatsapp', 'whatsapp_number' => '+91 98765 43210', 'lead_form_id' => null]);
        $p = MetaPlanBuilder::plan($s);
        $this->assertSame('OUTCOME_ENGAGEMENT', $p['campaign']['objective']);
        $this->assertSame('CONVERSATIONS', $p['adset']['optimization_goal']);
        $this->assertSame('WHATSAPP', $p['adset']['destination_type']);
        $this->assertSame(['page_id' => '555', 'whatsapp_phone_number' => '919876543210'], $p['adset']['promoted_object']);
        $this->assertSame('WHATSAPP_MESSAGE', $p['creative']['object_story_spec']['link_data']['call_to_action']['type']);
    }

    public function testMetaDefaultsToWholeIndiaAndNeverStartsInThePast(): void
    {
        $t = MetaPlanBuilder::targeting([]);
        $this->assertSame(['IN'], $t['geo_locations']['countries']);
        $now = strtotime('2026-10-08 12:00:00 UTC');
        $p = MetaPlanBuilder::plan($this->metaSpec(['start_date' => '2026-10-01', 'end_date' => null]), $now);
        $this->assertGreaterThan($now, strtotime($p['adset']['start_time']));
        $this->assertArrayNotHasKey('end_time', $p['adset']);
    }

    public function testMetaValidationCatchesTheCommonMistakes(): void
    {
        $e = MetaPlanBuilder::validate($this->metaSpec(['account_id' => '123', 'page_id' => '', 'daily_budget' => 0, 'lead_form_id' => '',
            'creative' => ['primary_text' => '', 'headline' => str_repeat('x', 41), 'image_url' => 'http://insecure/x.jpg'], 'targeting' => ['age_min' => 16, 'age_max' => 70]]));
        $joined = implode(' | ', $e);
        foreach (['ad account', 'Page', 'daily budget', 'lead form', 'ad text', 'Headline is too long', 'https://', 'Age range'] as $needle) {
            $this->assertStringContainsString($needle, $joined);
        }
        $this->assertSame([], MetaPlanBuilder::validate($this->metaSpec()));
        $this->assertNotEmpty(MetaPlanBuilder::validate($this->metaSpec(['kind' => 'click_to_whatsapp', 'whatsapp_number' => '12'])));
    }

    public function testMetaErrorClassification(): void
    {
        $auth = MetaMarketingClient::classify(['error' => ['code' => 190, 'message' => 'Error validating access token']]);
        $this->assertSame(AdsApiException::AUTH, $auth->kind);
        $this->assertStringContainsString('reconnect', $auth->getMessage());
        $this->assertSame(AdsApiException::RATE_LIMIT, MetaMarketingClient::classify(['error' => ['code' => 80004, 'message' => 'x']])->kind);
        $this->assertSame(AdsApiException::PERMISSION, MetaMarketingClient::classify(['error' => ['code' => 200, 'message' => 'x']])->kind);
        $bad = MetaMarketingClient::classify(['error' => ['code' => 100, 'error_user_msg' => 'Budget too low', 'message' => 'Invalid parameter', 'error_subcode' => 1815]]);
        $this->assertSame(AdsApiException::INVALID, $bad->kind);
        $this->assertSame('Budget too low', $bad->getMessage());          // user-facing message preferred
        $this->assertSame('100/1815', $bad->platformCode);
    }

    public function testMetaClientSendsTokenAndEncodesNestedParams(): void
    {
        $calls = [];
        $c = new MetaMarketingClient('TOK', function (string $m, string $u, array $o) use (&$calls) { $calls[] = [$m, $u, $o]; return ['status' => 200, 'body' => '{"id":"42"}']; }, 'v25.0');
        $c->create('act_1', 'campaigns', ['name' => 'x', 'special_ad_categories' => []], true);
        $this->assertSame('POST', $calls[0][0]);
        $this->assertSame('https://graph.facebook.com/v25.0/act_1/campaigns', $calls[0][1]);
        $this->assertSame(['validate_only'], $calls[0][2]['json']['execution_options']);
        $this->assertSame('TOK', $calls[0][2]['json']['access_token']);
        $c->request('GET', 'search', ['location_types' => ['city']]);
        $this->assertStringContainsString('location_types=%5B%22city%22%5D', $calls[1][1]);
    }

    // ---- Google ---------------------------------------------------------------------------------------

    private function googleSpec(array $o = []): array
    {
        return $o + ['platform' => 'google', 'kind' => 'search', 'name' => 'Bali Search', 'customer_id' => '1234567890', 'daily_budget' => 100_000, 'final_url' => 'https://example.com/bali',
            'headlines' => ['Bali Honeymoon Packages', 'Custom Bali Itinerary', 'Free Quote in 1 Hour'], 'descriptions' => ['Handpicked villas, private transfers and spa. Get your custom quote.', 'Trusted travel experts since 2012. Flexible payments available.'],
            'keywords' => [['text' => 'bali honeymoon package', 'match' => 'PHRASE'], ['text' => 'bali tour packages from india', 'match' => 'BROAD']], 'negatives' => ['free', 'jobs'],
            'start_date' => '2026-11-01', 'languages' => ['en', 'hi']];
    }

    public function testGooglePlanIsOneAtomicMutateWithTempIdsAndEverythingPaused(): void
    {
        $ops = GooglePlanBuilder::plan($this->googleSpec());
        $c = 'customers/1234567890';

        $budget = $ops[0]['campaignBudgetOperation']['create'];
        $this->assertSame("{$c}/campaignBudgets/-1", $budget['resourceName']);
        $this->assertSame('1000000000', $budget['amountMicros']);                  // ₹1,000/day = 1,000,000,000 micros
        $this->assertFalse($budget['explicitlyShared']);

        $camp = $ops[1]['campaignOperation']['create'];
        $this->assertSame('PAUSED', $camp['status']);
        $this->assertSame('SEARCH', $camp['advertisingChannelType']);
        $this->assertSame("{$c}/campaignBudgets/-1", $camp['campaignBudget']);      // temp-id reference, resolved atomically
        $this->assertFalse($camp['networkSettings']['targetContentNetwork']);
        $this->assertStringContainsString('utm_campaign={campaignid}', $camp['finalUrlSuffix']);

        $this->assertSame("{$c}/campaigns/-2", $ops[2]['adGroupOperation']['create']['campaign']);
        $rsa = $ops[3]['adGroupAdOperation']['create']['ad']['responsiveSearchAd'];
        $this->assertCount(3, $rsa['headlines']);
        $this->assertSame('Bali Honeymoon Packages', $rsa['headlines'][0]['text']);

        $kinds = array_map(static fn ($o) => array_key_first($o), $ops);
        $this->assertSame(2, count(array_keys($kinds, 'adGroupCriterionOperation')));          // 2 keywords
        $criteria = array_filter($ops, static fn ($o) => isset($o['campaignCriterionOperation']));
        $this->assertCount(2 + 1 + 2, $criteria);                                              // 2 negatives + India + 2 languages
        $json = json_encode($ops, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('geoTargetConstants/2356', $json);
        $this->assertStringContainsString('languageConstants/1023', $json);
    }

    public function testGoogleValidationAndHeadlinePolicy(): void
    {
        $this->assertSame([], GooglePlanBuilder::validate($this->googleSpec())['errors']);

        $bad = GooglePlanBuilder::validate($this->googleSpec(['headlines' => ['Great Bali Deals!', str_repeat('x', 31), 'Same', 'same'], 'descriptions' => ['one'], 'keywords' => [], 'final_url' => 'http://x']));
        $joined = implode(' | ', $bad['errors']);
        foreach (['“!”', '31 characters', 'different from each other', '2 to 4 descriptions', 'keywords', 'landing page'] as $needle) {
            $this->assertStringContainsString($needle, $joined);
        }
    }

    public function testGoogleIdsAreExtractedFromTheMutateResponse(): void
    {
        $ids = GooglePlanBuilder::ids(['mutateOperationResponses' => [
            ['campaignBudgetResult' => ['resourceName' => 'customers/1/campaignBudgets/11']], ['campaignResult' => ['resourceName' => 'customers/1/campaigns/22']],
            ['adGroupResult' => ['resourceName' => 'customers/1/adGroups/33']], ['adGroupAdResult' => ['resourceName' => 'customers/1/adGroupAds/33~44']], [],
        ]]);
        // legacy single-group keys are unchanged; ad_groups/ads (added for multi-ad-group campaigns) list every one
        $this->assertSame(['budget' => 'customers/1/campaignBudgets/11', 'campaign' => 'customers/1/campaigns/22', 'ad_group' => 'customers/1/adGroups/33', 'ad' => 'customers/1/adGroupAds/33~44'],
            array_diff_key($ids, ['ad_groups' => 1, 'ads' => 1]));
        $this->assertSame(['customers/1/adGroups/33'], $ids['ad_groups']);
    }

    public function testGoogleErrorClassificationUsesTheDetailMessage(): void
    {
        $e = GoogleAdsClient::classify(400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Request contains an invalid argument.',
            'details' => [['errors' => [['message' => 'The headline text is too long.']]]]]]);
        $this->assertSame(AdsApiException::INVALID, $e->kind);
        $this->assertSame('The headline text is too long.', $e->getMessage());
        $this->assertSame(AdsApiException::AUTH, GoogleAdsClient::classify(401, [])->kind);
        $this->assertSame(AdsApiException::PERMISSION, GoogleAdsClient::classify(403, ['error' => ['status' => 'PERMISSION_DENIED']])->kind);
        $this->assertSame(AdsApiException::RATE_LIMIT, GoogleAdsClient::classify(429, [])->kind);
    }

    // ---- lint -------------------------------------------------------------------------------------------

    public function testAdCopyLintFlagsUnverifiableClaimsAndShouting(): void
    {
        $r = AdCopyLint::check('GUARANTEED lowest price Bali trip!!', 'ad text', 'meta');
        $this->assertNotEmpty($r['errors']);                    // !! and ALL-CAPS
        $this->assertNotEmpty($r['warnings']);                  // guaranteed / lowest price
        $ok = AdCopyLint::check('Bali for two from ₹55,000 per person. Custom quote in 1 hour.', 'ad text', 'meta');
        $this->assertSame([], $ok['errors']);
        $this->assertSame([], $ok['warnings']);
        $this->assertNotEmpty(AdCopyLint::check('Short and sweet', 'headline 1', 'meta', 5)['errors']);   // length
    }

    // ---- attribution + AI copy + rules ------------------------------------------------------------------

    public function testWebFormAttributionCarriesTheCampaignIdFromUtm(): void
    {
        $g = \App\Services\Travel\AttributionService::fromForm(['gclid' => 'abc', 'utm_source' => 'google', 'utm_campaign' => '2290001', 'landing_url' => 'https://x.test/bali']);
        $this->assertSame('google', $g['platform']);
        $this->assertSame('2290001', $g['campaign_id']);          // Google {campaignid} is numeric -> matches ad_campaigns.external_id
        $this->assertNull($g['campaign_name']);
        $m = \App\Services\Travel\AttributionService::fromForm(['fbclid' => 'f', 'utm_id' => '1201', 'utm_campaign' => 'Bali Honeymoon']);
        $this->assertSame('1201', $m['campaign_id']);
        $this->assertSame('Bali Honeymoon', $m['campaign_name']);
    }

    public function testAiCopyIsLintedAndCannotInventPrices(): void
    {
        $raw = ['headlines' => ['Bali Honeymoon Packages', 'GUARANTEED BEST DEAL', 'Great Offers!', str_repeat('x', 31), 'Bali from ₹9,999', 'Bali from ₹55,000', 'Bali Honeymoon Packages'],
                'descriptions' => ['Custom Bali plan within an hour. Flexible payments.'], 'keywords' => [['text' => 'Bali Package', 'match' => 'EXACT'], ['text' => 'bali package', 'match' => 'PHRASE'], ['text' => 'x', 'match' => 'WEIRD']],
                'negatives' => ['jobs', 'jobs', ' ']];
        $c = \App\Services\Ads\AdCopyAiService::clean('google', $raw, ['destination' => 'Bali', 'price_from' => 55000]);
        $this->assertSame(['Bali Honeymoon Packages', 'Bali from ₹55,000'], $c['headlines']);   // caps, "!", too long, invented ₹9,999 and the duplicate are dropped
        $this->assertCount(4, $c['dropped']);
        $this->assertCount(2, $c['keywords']);                                                   // 'Bali Package' de-duplicated case-insensitively
        $this->assertSame('PHRASE', $c['keywords'][1]['match']);                                 // unknown match type normalised
        $this->assertSame(['jobs'], $c['negatives']);

        $this->assertTrue(\App\Services\Ads\AdCopyAiService::badPrice('Only ₹49,999', null));        // no price supplied -> any price is invented
        $this->assertFalse(\App\Services\Ads\AdCopyAiService::badPrice('Bali from ₹55,000', 55000));
    }

    public function testFallbackCopyAlwaysSatisfiesPlatformLimits(): void
    {
        foreach (['Bali', 'Andaman & Nicobar Islands', 'Rajasthan'] as $dest) {
            $c = \App\Services\Ads\AdCopyAiService::clean('google', \App\Services\Ads\AdCopyAiService::fallback('google', ['destination' => $dest, 'trip_type' => 'honeymoon']), ['destination' => $dest]);
            $this->assertGreaterThanOrEqual(3, count($c['headlines']), $dest);   // Google needs >= 3 headlines
            $this->assertGreaterThanOrEqual(2, count($c['descriptions']), $dest);
            foreach ($c['headlines'] as $h) { $this->assertLessThanOrEqual(30, mb_strlen($h)); }
        }
        $m = \App\Services\Ads\AdCopyAiService::clean('meta', \App\Services\Ads\AdCopyAiService::fallback('meta', ['destination' => 'Bali']), ['destination' => 'Bali']);
        $this->assertNotEmpty($m['primary_texts']);
    }

    public function testRuleEvaluationFiresOnlyWhenWarranted(): void
    {
        $rule = ['metric' => 'spend_no_leads', 'threshold' => 200_000, 'min_spend' => 0, 'window_days' => 3];
        $this->assertNotNull(\App\Services\Ads\AdRulesService::evaluate($rule, ['spend' => 250_000, 'crm_leads' => 0, 'platform_leads' => 0, 'bookings' => 0]));
        $this->assertNull(\App\Services\Ads\AdRulesService::evaluate($rule, ['spend' => 250_000, 'crm_leads' => 0, 'platform_leads' => 3, 'bookings' => 0]));   // platform saw leads
        $this->assertNull(\App\Services\Ads\AdRulesService::evaluate($rule, ['spend' => 100_000, 'crm_leads' => 0, 'platform_leads' => 0, 'bookings' => 0]));   // below threshold

        $cpl = ['metric' => 'cpl', 'threshold' => 50_000, 'min_spend' => 100_000, 'window_days' => 3];
        $this->assertNull(\App\Services\Ads\AdRulesService::evaluate($cpl, ['spend' => 60_000, 'crm_leads' => 1, 'platform_leads' => 1, 'bookings' => 0]));        // min spend not reached
        $this->assertNotNull(\App\Services\Ads\AdRulesService::evaluate($cpl, ['spend' => 300_000, 'crm_leads' => 4, 'platform_leads' => 4, 'bookings' => 0]));    // ₹750 per lead > ₹500
        $this->assertNull(\App\Services\Ads\AdRulesService::evaluate($cpl, ['spend' => 300_000, 'crm_leads' => 10, 'platform_leads' => 10, 'bookings' => 0]));     // ₹300 per lead ok

        $cpb = ['metric' => 'cost_per_booking', 'threshold' => 500_000, 'min_spend' => 0, 'window_days' => 7];
        $this->assertNotNull(\App\Services\Ads\AdRulesService::evaluate($cpb, ['spend' => 600_000, 'crm_leads' => 9, 'platform_leads' => 9, 'bookings' => 0]));     // spent more than a booking is worth, none yet
        $this->assertNull(\App\Services\Ads\AdRulesService::evaluate($cpb, ['spend' => 600_000, 'crm_leads' => 9, 'platform_leads' => 9, 'bookings' => 2]));
    }
}
