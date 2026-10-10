<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\PlatformAudienceService;
use App\Services\Admin\PlatformMarketingService;
use App\Services\Admin\PlatformSettings;
use App\Services\Marketing\ContactEnquiryService;
use App\Services\Partner\PartnerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Consent-based audiences for TripSarthi's own WhatsApp marketing + the marketing workspace readiness (group "mysql"). */
#[Group('mysql')]
final class PlatformAudienceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const MARKETING = 9;     // the workspace that runs TripSarthi's marketing

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['platform_audience_syncs', 'platform_settings', 'jobs', 'contact_tags', 'tags', 'contacts', 'partner_events', 'partners', 'contact_enquiries', 'subscriptions', 'templates', 'phone_numbers', 'waba_accounts', 'campaigns', 'messages', 'conversations', 'audit_logs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $mk = fn (int $id, string $name, string $plan = 'free', string $status = 'active', string $created = '2026-01-01 00:00:00') => $db->table('tenants')->insert(['id' => $id, 'name' => $name, 'slug' => 'w' . $id, 'plan' => $plan, 'status' => $status, 'mode' => 'saas', 'created_at' => $created]);
        $mk(self::MARKETING, 'TripSarthi Marketing', 'pro');
        $mk(1, 'Alpha Travels', 'free', 'active', date('Y-m-d H:i:s', time() - 30 * 86400));     // free for a month
        $mk(2, 'Beta Holidays', 'growth');                                                            // paying, plan ends soon
        $mk(3, 'Gamma Tours', 'free');                                                                // lapsed (paid before)
        $mk(4, 'Delta Trips', 'free', 'active', date('Y-m-d H:i:s', time() - 2 * 86400));         // brand new
        $mk(5, 'Epsilon Suspended', 'free', 'suspended', date('Y-m-d H:i:s', time() - 30 * 86400));
        $this->user(1, 'Asha Rao', 'asha@alpha.test', '+91 98765 00001', true);
        $this->user(2, 'Bala K', 'bala@beta.test', '98765 00002', true);
        $this->user(3, 'Chitra N', 'chitra@gamma.test', '98765 00003', true);
        $this->user(4, 'Dev P', 'dev@delta.test', '98765 00004', true);
        $this->user(5, 'Esha S', 'esha@eps.test', '98765 00005', true);
        $this->user(self::MARKETING, 'Marketing Owner', 'mk@tripsarthi.test', '98765 00099', true);
        $db->table('subscriptions')->insert(['tenant_id' => 2, 'razorpay_sub_id' => 'pay_b', 'plan' => 'growth', 'amount_paise' => 399900, 'billing_cycle' => 'monthly', 'status' => 'active', 'current_period_start' => date('Y-m-d H:i:s', time() - 25 * 86400), 'current_period_end' => date('Y-m-d H:i:s', time() + 4 * 86400), 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $db->table('subscriptions')->insert(['tenant_id' => 3, 'razorpay_sub_id' => 'pay_c', 'plan' => 'starter', 'amount_paise' => 149900, 'billing_cycle' => 'monthly', 'status' => 'cancelled', 'current_period_start' => '2026-01-01 00:00:00', 'current_period_end' => '2026-02-01 00:00:00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        PlatformSettings::set(PlatformSettings::MARKETING_TENANT, (string) self::MARKETING);
    }

    protected function tearDown(): void { db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function user(int $tenant, string $name, string $email, ?string $phone, bool $optIn, string $role = 'owner', int $admin = 0): void
    {
        db_connect()->table('users')->insert(['tenant_id' => $tenant, 'name' => $name, 'email' => $email, 'password_hash' => 'x', 'role' => $role, 'is_platform_admin' => $admin, 'phone' => $phone, 'wa_marketing_opt_in' => $optIn ? 1 : 0, 'created_at' => '2026-01-01 00:00:00']);
    }

    private function svc(): PlatformAudienceService { return new PlatformAudienceService(); }
    private function phones(string $seg): array { $p = array_column($this->svc()->members($seg), 'phone'); sort($p); return $p; }

    // ---- who is in each segment ---------------------------------------------------------------------------------------------------------

    public function testOnlyOptedInOwnersWithAUsableNumberOfActiveWorkspacesAreInTheOwnersSegment(): void
    {
        $this->user(1, 'Second Owner', 'x@alpha.test', '', true, 'owner');                         // no number: cannot be contacted
        $this->user(1, 'No Consent', 'y@alpha.test', '98765 00007', false, 'owner');               // has a number, said no
        $this->user(1, 'An Agent', 'z@alpha.test', '98765 00008', true, 'agent');                  // not an owner
        $this->user(4, 'Staff', 's@tripsarthi.test', '98765 00009', true, 'owner', 1);             // TripSarthi staff
        $this->assertSame(['919876500001', '919876500002', '919876500003', '919876500004'], $this->phones('owners'),
            'workspace 5 is suspended, the marketing workspace itself is excluded, and nobody without a yes + number is listed');
    }

    public function testSegmentRulesForTrialEndingFreeAndLapsed(): void
    {
        $this->assertSame(['919876500002'], $this->phones('owners_trial_ending'), 'Beta pays until in 4 days');
        $this->assertSame(['919876500001', '919876500003'], $this->phones('owners_free'), 'free plan for 7+ days (Delta signed up 2 days ago)');
        $this->assertSame(['919876500003'], $this->phones('owners_lapsed'), 'Gamma paid before and is free now');
    }

    public function testManualAdminGrantsCountForTrialEndingButNotAsPayingHistory(): void
    {
        db_connect()->table('subscriptions')->insert(['tenant_id' => 4, 'razorpay_sub_id' => 'admin-1', 'plan' => 'growth', 'amount_paise' => 0, 'billing_cycle' => 'monthly', 'status' => 'active', 'current_period_start' => '2026-01-01 00:00:00', 'current_period_end' => date('Y-m-d H:i:s', time() + 3 * 86400), 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->assertContains('919876500004', $this->phones('owners_trial_ending'), 'a free trial ending in 3 days is a "plan ending" nudge');
        db_connect()->table('subscriptions')->where('razorpay_sub_id', 'admin-1')->update(['status' => 'cancelled']);
        $this->assertNotContains('919876500004', $this->phones('owners_lapsed'), 'a cancelled free grant is not a lapsed customer');
    }

    public function testPartnersAndWebsiteLeadsNeedTheirOwnConsent(): void
    {
        $p = new PartnerService();
        $a = $p->create(['name' => 'Opted Partner', 'email' => 'a@p.test', 'phone' => '98765 10001'], 1)['partner'];
        $b = $p->create(['name' => 'Quiet Partner', 'email' => 'b@p.test', 'phone' => '98765 10002'], 1)['partner'];
        $p->create(['name' => 'No Phone', 'email' => 'c@p.test'], 1);
        foreach ([$a['id'], $b['id']] as $id) { db_connect()->table('partners')->where('id', $id)->update(['status' => 'active']); }
        db_connect()->table('partners')->where('id', $a['id'])->update(['wa_marketing_opt_in' => 1]);
        $this->assertSame(['919876510001'], $this->phones('partners'));

        $e = new ContactEnquiryService();
        $e->submit(['name' => 'Lead One', 'email' => 'l1@x.test', 'phone' => '98765 20001', 'message' => 'Interested', 'wa_opt_in' => '1'], '', '', 0, false);
        $e->submit(['name' => 'Lead Two', 'email' => 'l2@x.test', 'phone' => '98765 20002', 'message' => 'Also interested'], '', '', 0, false);
        $e->submit(['name' => 'Spam Lead', 'email' => 'l3@x.test', 'phone' => '98765 20003', 'message' => 'http://a.test http://b.test http://c.test buy now', 'wa_opt_in' => '1'], '', '', 0, false);
        $this->assertSame(['919876520001'], $this->phones('website_leads'), 'ticked + not spam only');
    }

    public function testAnEnquiryOptInWithoutAUsableNumberIsRefused(): void
    {
        $v = ContactEnquiryService::validate(['name' => 'Lead', 'email' => 'l@x.test', 'phone' => '', 'wa_opt_in' => '1', 'message' => 'hi']);
        $this->assertArrayHasKey('phone', $v['errors']);
        $ok = ContactEnquiryService::validate(['name' => 'Lead', 'email' => 'l@x.test', 'phone' => '98765 43210', 'message' => 'hi']);
        $this->assertSame(0, $ok['clean']['wa_opt_in'], 'never pre-ticked: unticked means no consent');
    }

    public function testPreviewShowsAMaskedSampleNotAContactList(): void
    {
        $pv = $this->svc()->preview('owners');
        $this->assertSame(4, $pv['count']);
        $this->assertLessThanOrEqual(5, count($pv['sample']));
        foreach ($pv['sample'] as $s) { $this->assertMatchesRegularExpression('/^•{6}\d{4}$/u', $s['phone']); }
        $this->assertStringNotContainsString('9198765000', json_encode($pv));
    }

    public function testNumbersAreDeduplicatedAcrossAWorkspaceOwnersAndSpellings(): void
    {
        $this->user(1, 'Asha Again', 'asha2@alpha.test', '09876500001', true);                    // same person, written differently
        $this->assertCount(4, $this->svc()->members('owners'));
    }

    // ---- syncing into the marketing workspace ---------------------------------------------------------------------------------------

    public function testSyncAddsOptedInTaggedContactsWithoutStartingAnyFlow(): void
    {
        $r = $this->svc()->sync('owners', 1);
        $this->assertSame([4, 0, 0, 0, 4], [$r['added'], $r['already_there'], $r['skipped'], $r['withdrawn'], $r['members']]);
        $rows = db_connect()->table('contacts')->where('tenant_id', self::MARKETING)->get()->getResultArray();
        $this->assertCount(4, $rows);
        foreach ($rows as $c) { $this->assertSame(1, (int) $c['opt_in']); }
        $tags = db_connect()->query('SELECT DISTINCT t.name FROM contact_tags ct JOIN tags t ON t.id = ct.tag_id ORDER BY t.name')->getResultArray();
        $this->assertSame(['ts-owners', 'ts-platform'], array_column($tags, 'name'));
        $this->assertSame(0, db_connect()->table('jobs')->countAllResults(), 'syncing must never trigger a welcome flow or any queued job');
        $this->assertSame(['Asha Rao', 'Bala K'], array_slice(array_column(db_connect()->table('contacts')->where('tenant_id', self::MARKETING)->orderBy('id')->get()->getResultArray(), 'name'), 0, 2));
    }

    public function testSyncIsIdempotent(): void
    {
        $this->svc()->sync('owners', 1);
        $again = $this->svc()->sync('owners', 1);
        $this->assertSame([0, 4], [$again['added'], $again['already_there']]);
        $this->assertSame(4, db_connect()->table('contacts')->where('tenant_id', self::MARKETING)->countAllResults(), 'no duplicates');
    }

    public function testSomeoneWhoRepliedSTOPIsNeverOptedBackIn(): void
    {
        $this->svc()->sync('owners', 1);
        db_connect()->table('contacts')->where('tenant_id', self::MARKETING)->where('wa_number', '919876500002')->update(['opt_in' => 0]);   // what a STOP reply does
        $r = $this->svc()->sync('owners', 1);
        $this->assertSame([0, 3, 1], [$r['added'], $r['already_there'], $r['skipped']]);
        $this->assertSame(0, (int) db_connect()->table('contacts')->where('tenant_id', self::MARKETING)->where('wa_number', '919876500002')->get()->getRowArray()['opt_in']);
    }

    public function testWithdrawnConsentSwitchesTheContactOffOnTheNextSync(): void
    {
        $this->svc()->sync('owners', 1);
        db_connect()->table('users')->where('email', 'asha@alpha.test')->update(['wa_marketing_opt_in' => 0]);        // Asha withdraws in her profile
        $r = $this->svc()->sync('owners', 1);
        $this->assertSame(1, $r['withdrawn']);
        $asha = db_connect()->table('contacts')->where('tenant_id', self::MARKETING)->where('wa_number', '919876500001')->get()->getRowArray();
        $this->assertSame(0, (int) $asha['opt_in']);
        $this->assertSame(3, (int) db_connect()->table('contacts')->where('tenant_id', self::MARKETING)->where('opt_in', 1)->countAllResults());
    }

    public function testWithdrawalLeavesContactsTheTeamAddedByHandAlone(): void
    {
        db_connect()->table('contacts')->insert(['tenant_id' => self::MARKETING, 'wa_number' => '919999900000', 'name' => 'Hand added', 'source' => 'manual', 'status' => 'new', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $this->svc()->sync('owners', 1);
        $this->assertSame(1, (int) db_connect()->table('contacts')->where('wa_number', '919999900000')->get()->getRowArray()['opt_in'], 'only contacts we created (tag ts-platform) can be withdrawn');
    }

    public function testConsentInAnotherSourceKeepsTheContactOn(): void
    {
        $this->svc()->sync('owners', 1);
        db_connect()->table('users')->where('email', 'asha@alpha.test')->update(['wa_marketing_opt_in' => 0]);
        db_connect()->table('contact_enquiries')->insert(['name' => 'Asha', 'email' => 'asha@alpha.test', 'phone' => '98765 00001', 'message' => 'm', 'status' => 'new', 'wa_opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $r = $this->svc()->sync('owners', 1);
        $this->assertSame(0, $r['withdrawn'], 'she still agreed through the website form');
    }

    public function testSyncNeedsAChosenExistingWorkspace(): void
    {
        PlatformSettings::set(PlatformSettings::MARKETING_TENANT, null);
        try { $this->svc()->sync('owners', 1); $this->fail('synced with no workspace'); } catch (\DomainException $e) { $this->assertStringContainsString('Choose the workspace', $e->getMessage()); }
        PlatformSettings::set(PlatformSettings::MARKETING_TENANT, '4242');
        $this->expectException(\DomainException::class);
        $this->svc()->sync('owners', 1);
    }

    public function testUnknownSegmentIsRefusedAndSegmentsReportCountsAndLastSync(): void
    {
        try { $this->svc()->members('everyone'); $this->fail('unknown segment'); } catch (\InvalidArgumentException) { /* expected */ }
        $this->svc()->sync('owners_free', 1);
        $seg = array_column($this->svc()->segments(), null, 'key');
        $this->assertSame([4, 2], [$seg['owners']['count'], $seg['owners_free']['count']]);
        $this->assertNull($seg['owners']['last_sync']);
        $this->assertSame(2, $seg['owners_free']['last_sync']['added']);
    }

    // ---- readiness of the marketing workspace --------------------------------------------------------------------------------------

    public function testReadinessChecksExplainWhatIsMissing(): void
    {
        $m = new PlatformMarketingService();
        $s = $m->status();
        $this->assertFalse($s['ready']);
        $by = array_column($s['checks'], 'ok', 'key');
        $this->assertSame(['workspace' => true, 'whatsapp' => false, 'quality' => false, 'templates' => false, 'audience' => false, 'not_paused' => true], $by);

        $db = db_connect();
        $db->table('waba_accounts')->insert(['tenant_id' => self::MARKETING, 'waba_id' => 'w9', 'status' => 'active', 'provider' => 'cloud', 'created_at' => '2026-01-01 00:00:00']);
        $wid = (int) $db->insertID();
        $db->table('phone_numbers')->insert(['waba_account_id' => $wid, 'tenant_id' => self::MARKETING, 'phone_number_id' => 'pn9', 'display_number' => '+919000000009', 'quality_rating' => 'green']);
        $db->table('templates')->insert(['tenant_id' => self::MARKETING, 'name' => 'promo', 'language' => 'en', 'category' => 'marketing', 'body' => 'x', 'meta_status' => 'approved', 'created_at' => '2026-01-01 00:00:00']);
        $this->svc()->sync('owners', 1);
        $s = $m->status();
        $this->assertTrue($s['ready'], json_encode($s['checks']));
        $this->assertSame([4, 4], [$s['contacts']['opted_in'], $s['contacts']['platform']]);
    }

    public function testChoosingTheWorkspaceIsValidatedAndAudited(): void
    {
        $m = new PlatformMarketingService();
        $this->assertSame('Alpha Travels', $m->setWorkspace(1, 7)['workspace']['name']);
        $this->assertSame(1, (int) PlatformSettings::get(PlatformSettings::MARKETING_TENANT));
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'admin.marketing_workspace')->countAllResults());
        try { $m->setWorkspace(5, 7); $this->fail('suspended workspace accepted'); } catch (\DomainException) { /* expected */ }
        try { $m->setWorkspace(999, 7); $this->fail('missing workspace accepted'); } catch (\OutOfBoundsException) { /* expected */ }
        $this->assertNull($m->setWorkspace(null, 7)['workspace']);
    }

    // ---- partner consent -------------------------------------------------------------------------------------------------------------

    public function testPartnerConsentIsSetAtTheInviteAndChangeableLater(): void
    {
        $p = new PartnerService();
        $r = $p->create(['name' => 'Priya', 'email' => 'priya@partner.test', 'phone' => '98765 30001'], 1);
        $this->assertTrue($p->inviteInfo($r['invite_token'])['has_phone']);
        $p->acceptInvite($r['invite_token'], 'a long passphrase 1', '', true);
        $this->assertSame(1, (int) db_connect()->table('partners')->get()->getRowArray()['wa_marketing_opt_in']);
        $saved = $p->savePreferences($r['partner']['id'], null, false);
        $this->assertFalse($saved['wa_marketing_opt_in']);
        $this->assertSame(['whatsapp_consent.given', 'whatsapp_consent.withdrawn'], array_values(array_filter(array_column(db_connect()->table('partner_events')->orderBy('id')->get()->getResultArray(), 'action'), fn ($a) => str_starts_with($a, 'whatsapp'))));
        try { $p->savePreferences($r['partner']['id'], '', true); $this->fail('opted in without a number'); } catch (\InvalidArgumentException) { /* expected */ }
    }

    public function testAPartnerWithoutAPhoneCannotBeOptedInAtTheInvite(): void
    {
        $p = new PartnerService();
        $r = $p->create(['name' => 'No Phone', 'email' => 'np@partner.test'], 1);
        $this->assertFalse($p->inviteInfo($r['invite_token'])['has_phone']);
        $p->acceptInvite($r['invite_token'], 'a long passphrase 1', '', true);
        $this->assertSame(0, (int) db_connect()->table('partners')->get()->getRowArray()['wa_marketing_opt_in']);
    }

    public function testAResetLinkNeverSilentlyWithdrawsAnExistingYes(): void
    {
        $p = new PartnerService();
        $r = $p->create(['name' => 'Priya', 'email' => 'priya@partner.test', 'phone' => '98765 30001'], 1);
        $p->acceptInvite($r['invite_token'], 'a long passphrase 1', '', true);
        $link = $p->newInvite($r['partner']['id'], 1);
        $p->acceptInvite($link, 'another long passphrase', '', false);                          // box left unticked on the reset page
        $this->assertSame(1, (int) db_connect()->table('partners')->get()->getRowArray()['wa_marketing_opt_in']);
    }
}
