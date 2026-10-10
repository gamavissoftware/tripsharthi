<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\BillingService;
use App\Services\Partner\PartnerService;
use App\Services\Partner\PartnerSessionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** The referral partner program against real MySQL (group "mysql"). */
#[Group('mysql')]
final class PartnerServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const PW = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('RAZORPAY_MOCK_MODE=true'); $_ENV['RAZORPAY_MOCK_MODE'] = 'true'; $_SERVER['RAZORPAY_MOCK_MODE'] = 'true';
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['partner_events', 'partner_payouts', 'partner_commissions', 'partner_sessions', 'partners', 'coupon_redemptions', 'coupons', 'promotions', 'subscriptions', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1 => 'Alpha Travels', 2 => 'Beta Holidays', 3 => 'Gamma Tours'] as $id => $n) {
            $db->table('tenants')->insert(['id' => $id, 'name' => $n, 'slug' => 'w' . $id, 'plan' => 'free', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
            $db->table('users')->insert(['tenant_id' => $id, 'name' => "Owner $id", 'email' => "owner{$id}@customer{$id}.test", 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        }
    }

    private function svc(?int $now = null): PartnerService { return new PartnerService($now); }

    /** A partner who has accepted the invite (active, with a password). */
    private function activePartner(array $o = [], ?string $email = null): array
    {
        $r = $this->svc()->create($o + ['name' => 'Priya Sharma', 'email' => $email ?? 'priya@partner.test', 'company' => 'Travel Guru'], 1);
        $this->svc()->acceptInvite($r['invite_token'], self::PW);
        return $r['partner'];
    }

    private function pay(int $tenant, string $plan = 'growth', string $cycle = 'monthly', string $coupon = ''): array
    {
        $b = new BillingService();
        $o = $b->createOrder($tenant, $plan, $cycle, $coupon);
        $b->verifyPayment($tenant, $o['order_id'], 'pay_' . bin2hex(random_bytes(4)), 'sig', $plan, $cycle);
        return $o;
    }

    // ---- accounts ------------------------------------------------------------------------------------------------------------------------

    public function testCreatingAPartnerGivesDefaultTermsACodeAndAHashedOneTimeInvite(): void
    {
        $r = $this->svc()->create(['name' => 'Priya Sharma', 'email' => ' Priya@Partner.TEST ', 'company' => 'Travel Guru'], 1);
        $p = $r['partner'];
        $this->assertSame(['invited', 'priya@partner.test', 20.0, 12, 30], [$p['status'], $p['email'], $p['commission_pct'], $p['commission_months'], $p['hold_days']]);
        $this->assertMatchesRegularExpression('/^PRIYASHA[A-Z0-9]{3}$/', $p['code']);
        $this->assertTrue($p['invite_pending']);
        $this->assertFalse($p['has_password']);
        $row = db_connect()->table('partners')->get()->getRowArray();
        $this->assertSame(hash('sha256', $r['invite_token']), $row['invite_token_hash']);
        $this->assertStringNotContainsString($r['invite_token'], json_encode($row), 'the raw invite token is never stored');
        $this->assertStringContainsString('ref=' . $p['code'], $p['links']['app_link']);
    }

    /** @return array<string,array{0:array,1:string}> */
    public static function badPartners(): array
    {
        return [
            'no name'            => [['email' => 'a@b.test'], 'name'],
            'bad email'          => [['name' => 'A', 'email' => 'nope'], 'valid email'],
            'commission too high' => [['name' => 'A', 'email' => 'a@b.test', 'commission_pct' => 80], 'between 1% and 60%'],
            'commission zero'    => [['name' => 'A', 'email' => 'a@b.test', 'commission_pct' => 0], 'between 1% and 60%'],
            'absurd months'      => [['name' => 'A', 'email' => 'a@b.test', 'commission_months' => 500], '0 (lifetime)'],
            'absurd hold'        => [['name' => 'A', 'email' => 'a@b.test', 'hold_days' => 999], 'between 0 and 180'],
            'bad code'           => [['name' => 'A', 'email' => 'a@b.test', 'code' => 'x y'], '3-20 characters'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPartners')]
    public function testBadPartnerInputIsRefused(array $in, string $needle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/i');
        $this->svc()->create($in, 1);
    }

    public function testEmailsAndCodesAreUniqueAndCannotCollideWithACouponCode(): void
    {
        $this->svc()->create(['name' => 'A', 'email' => 'a@x.test', 'code' => 'ALPHA1'], 1);
        foreach ([['email' => 'A@X.test', 'code' => 'ZZZ1'], ['email' => 'b@x.test', 'code' => 'alpha1']] as $dup) {
            try { $this->svc()->create(['name' => 'B'] + $dup, 1); $this->fail('duplicate accepted'); } catch (\DomainException) { /* expected */ }
        }
        db_connect()->table('coupons')->insert(['code' => 'SAVE20', 'type' => 'percent', 'value' => 20, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->expectException(\DomainException::class);
        $this->svc()->create(['name' => 'C', 'email' => 'c@x.test', 'code' => 'SAVE20'], 1);
    }

    public function testTheInviteLinkSetsAPasswordOnceActivatesAndEndsOldSessions(): void
    {
        $r = $this->svc()->create(['name' => 'Priya', 'email' => 'priya@partner.test'], 1);
        $this->assertSame(['name' => 'Priya', 'email' => 'priya@partner.test', 'has_phone' => false], $this->svc()->inviteInfo($r['invite_token']));
        try { $this->svc()->acceptInvite($r['invite_token'], 'short'); $this->fail('weak password accepted'); } catch (\InvalidArgumentException) { /* expected */ }
        $p = $this->svc()->acceptInvite($r['invite_token'], self::PW);
        $this->assertSame('active', $p['status']);
        $this->assertTrue(password_verify(self::PW, $p['password_hash']));
        $this->expectException(\OutOfBoundsException::class);          // the link is now dead
        $this->svc()->acceptInvite($r['invite_token'], self::PW);
    }

    public function testAnExpiredOrGarbageInviteIsRefused(): void
    {
        $r = $this->svc()->create(['name' => 'Priya', 'email' => 'priya@partner.test'], 1);
        foreach ([$r['invite_token'] . 'x', 'zz', str_repeat('a', 48)] as $bad) {
            try { $this->svc()->inviteInfo($bad); $this->fail('accepted'); } catch (\OutOfBoundsException) { /* expected */ }
        }
        $late = new PartnerService(time() + 8 * 86400);
        $this->expectException(\OutOfBoundsException::class);
        $late->inviteInfo($r['invite_token']);
    }

    public function testResettingAPasswordNeedsANewLinkAndEndsExistingSessions(): void
    {
        $p = $this->activePartner();
        $sess = new PartnerSessionService(); $tok = $sess->create($p['id']);
        $this->assertNotNull($sess->partner($tok));
        $link = $this->svc()->newInvite($p['id'], 1);
        $this->assertNotNull($sess->partner($tok), 'the old session keeps working until the new password is set');
        $this->svc()->acceptInvite($link, 'a brand new passphrase');
        $this->assertNull($sess->partner($tok), 'setting a new password ends every old session');
        $this->assertNotNull($this->svc()->verifyLogin('priya@partner.test', 'a brand new passphrase'));
        $this->assertNull($this->svc()->verifyLogin('priya@partner.test', self::PW));
    }

    public function testOnlyAnActivePartnerWithTheRightPasswordCanSignIn(): void
    {
        $inv = $this->svc()->create(['name' => 'Invited', 'email' => 'inv@x.test'], 1);
        $this->assertNull($this->svc()->verifyLogin('inv@x.test', 'anything at all'), 'not set up yet');
        $p = $this->activePartner();
        $this->assertNotNull($this->svc()->verifyLogin(' PRIYA@partner.test ', self::PW));
        $this->assertNull($this->svc()->verifyLogin('priya@partner.test', 'wrong password'));
        $this->assertNull($this->svc()->verifyLogin('nobody@x.test', self::PW));
        $this->svc()->setStatus($p['id'], 'suspended', 1);
        $this->assertNull($this->svc()->verifyLogin('priya@partner.test', self::PW), 'suspended');
        $this->assertSame(1, (int) db_connect()->table('partner_events')->where('action', 'login')->countAllResults(), 'only the successful sign-ins are logged as logins');
    }

    public function testSuspendingEndsLiveSessionsImmediately(): void
    {
        $p = $this->activePartner(); $sess = new PartnerSessionService(); $tok = $sess->create($p['id']);
        $this->svc()->setStatus($p['id'], 'suspended', 1);
        $this->assertNull($sess->partner($tok));
    }

    public function testPartnerSessionsExpireIdleAndAbsoluteAndAreCapped(): void
    {
        $p = $this->activePartner(); $t0 = time();
        $tok = (new PartnerSessionService($t0))->create($p['id']);
        $this->assertNull((new PartnerSessionService($t0 + 13 * 3600))->partner($tok), 'idle 12h+');
        $tokB = (new PartnerSessionService($t0))->create($p['id']);
        $this->assertNotNull((new PartnerSessionService($t0 + 11 * 3600))->partner($tokB), 'inside the idle window');
        $tok2 = (new PartnerSessionService($t0))->create($p['id']);
        for ($h = 6; $h <= 162; $h += 6) { $this->assertNotNull((new PartnerSessionService($t0 + $h * 3600))->partner($tok2), "active at {$h}h"); }
        $this->assertNull((new PartnerSessionService($t0 + 169 * 3600))->partner($tok2), 'absolute 7 days');
        $svc = new PartnerSessionService(); $all = [];
        for ($i = 0; $i < 6; $i++) { $all[] = $svc->create($p['id']); }
        $this->assertNull($svc->partner($all[0]));
        $this->assertNotNull($svc->partner($all[5]));
    }

    public function testASessionNeverCarriesPasswordOrPayoutSecrets(): void
    {
        $p = $this->activePartner(); $sess = new PartnerSessionService();
        $row = $sess->partner($sess->create($p['id']));
        $this->assertArrayNotHasKey('password_hash', $row);
        $this->assertArrayNotHasKey('payout_enc', $row);
        $this->assertArrayNotHasKey('invite_token_hash', $row);
    }

    // ---- attribution -----------------------------------------------------------------------------------------------------------------------

    public function testASignupThroughTheReferralCodeIsAttributedFirstTouchWins(): void
    {
        $a = $this->activePartner([], 'a@partner.test'); $b = $this->activePartner(['name' => 'Bala'], 'b@partner.test');
        $this->assertTrue($this->svc()->attributeSignup(1, strtolower($a['code']), 'owner1@customer1.test'), 'codes are case-insensitive');
        $t = db_connect()->table('tenants')->where('id', 1)->get()->getRowArray();
        $this->assertSame([$a['id'], 'link'], [(int) $t['referred_partner_id'], $t['referral_source']]);
        $this->assertFalse($this->svc()->attributeSignup(1, $b['code'], 'owner1@customer1.test'), 'a second code never overwrites the first');
        $this->assertSame($a['id'], (int) db_connect()->table('tenants')->where('id', 1)->get()->getRowArray()['referred_partner_id']);
    }

    public function testUnknownInactiveAndSelfReferralsAreIgnored(): void
    {
        $p = $this->activePartner(['name' => 'Priya'], 'priya@partner.test');
        $this->assertFalse($this->svc()->attributeSignup(1, 'NOSUCHCODE', 'o@c.test'));
        $this->assertFalse($this->svc()->attributeSignup(1, 'x', 'o@c.test'), 'malformed');
        $this->assertFalse($this->svc()->attributeSignup(1, $p['code'], 'PRIYA@partner.test'), 'a partner cannot refer themselves');
        $this->assertFalse($this->svc()->attributeSignup(1, $p['code'], 'priya+shop@partner.test'), 'a +tag alias of the same mailbox is still themselves');
        $this->svc()->setStatus($p['id'], 'suspended', 1);
        $this->assertFalse($this->svc()->attributeSignup(1, $p['code'], 'owner1@customer1.test'), 'a suspended partner earns nothing new');
        $this->assertNull(db_connect()->table('tenants')->where('id', 1)->get()->getRowArray()['referred_partner_id']);
    }

    // ---- commissions -----------------------------------------------------------------------------------------------------------------------

    public function testAReferredCustomersPaymentEarnsTheConfiguredCommissionAfterAHold(): void
    {
        $p = $this->activePartner(['commission_pct' => 25, 'hold_days' => 10]);
        $this->svc()->attributeSignup(1, $p['code'], 'owner1@customer1.test');
        $o = $this->pay(1);                                              // Growth monthly, Rs 3,999
        $c = db_connect()->table('partner_commissions')->get()->getRowArray();
        $this->assertSame([399_900, 99_975, 'pending', 'growth', 'monthly'], [(int) $c['base_paise'], (int) $c['amount_paise'], $c['status'], $c['plan'], $c['cycle']]);
        $this->assertEqualsWithDelta(time() + 10 * 86400, strtotime($c['payable_after']), 5);
        $this->assertSame(['pending_paise' => 99_975, 'available_paise' => 0, 'paid_paise' => 0, 'void_paise' => 0, 'earned_paise' => 99_975], $this->svc()->balances($p['id']));
        $this->assertSame($o['amount'], (int) $c['base_paise'], 'commission is on what the customer actually paid');
    }

    public function testTheCommissionIsOnTheDiscountedAmountAfterACoupon(): void
    {
        $p = $this->activePartner();
        $this->svc()->attributeSignup(1, $p['code'], 'owner1@customer1.test');
        db_connect()->table('coupons')->insert(['code' => 'HALF', 'type' => 'percent', 'value' => 50, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->pay(1, 'growth', 'monthly', 'HALF');
        $c = db_connect()->table('partner_commissions')->get()->getRowArray();
        $this->assertSame([199_950, 39_990], [(int) $c['base_paise'], (int) $c['amount_paise']]);
    }

    public function testNoCommissionWithoutAReferralOrForASuspendedPartnerOrAFreePayment(): void
    {
        $p = $this->activePartner();
        $this->pay(1);                                                    // nobody referred workspace 1
        $this->assertSame(0, db_connect()->table('partner_commissions')->countAllResults());
        $this->svc()->attributeSignup(2, $p['code'], 'owner2@customer2.test');
        $this->svc()->setStatus($p['id'], 'suspended', 1);
        $this->pay(2);
        $this->assertSame(0, db_connect()->table('partner_commissions')->countAllResults(), 'suspended: no new commissions');
        $this->assertNull($this->svc()->recordPayment(2, 999, 0, 'growth', 'monthly'));
    }

    public function testEachPaymentEarnsOnceAndRepeatingTheHookDoesNotDuplicate(): void
    {
        $p = $this->activePartner();
        $this->svc()->attributeSignup(1, $p['code'], 'owner1@customer1.test');
        $this->pay(1); $this->pay(1);                                     // two renewals = two commissions
        $this->assertSame(2, db_connect()->table('partner_commissions')->countAllResults());
        $sub = (int) db_connect()->table('subscriptions')->where('status', 'active')->get()->getRowArray()['id'];
        $this->svc()->recordPayment(1, $sub, 399_900, 'growth', 'monthly');
        $this->svc()->recordPayment(1, $sub, 399_900, 'growth', 'monthly');
        $this->assertSame(2, db_connect()->table('partner_commissions')->countAllResults(), 'the same payment row never earns twice');
    }

    public function testCommissionStopsAfterTheCommissionPeriodUnlessLifetime(): void
    {
        $p = $this->activePartner(['commission_months' => 2]);
        $this->svc()->attributeSignup(1, $p['code'], 'owner1@customer1.test');
        $this->pay(1);                                                    // anchors the window
        $within = new PartnerService(time() + 50 * 86400);
        $this->assertNotNull($within->recordPayment(1, 9001, 100_000, 'starter', 'monthly'), 'inside 2 months');
        $after = new PartnerService(time() + 70 * 86400);
        $this->assertNull($after->recordPayment(1, 9002, 100_000, 'starter', 'monthly'), 'after 2 months');

        $this->svc()->update($p['id'], ['commission_months' => 0], 1);
        $this->assertNotNull($after->recordPayment(1, 9003, 100_000, 'starter', 'monthly'), 'lifetime');
    }

    public function testAnUnpaidTermsChangeOnlyAffectsFutureCommissions(): void
    {
        $p = $this->activePartner(['commission_pct' => 20]);
        $this->svc()->attributeSignup(1, $p['code'], 'owner1@customer1.test');
        $this->pay(1);
        $this->svc()->update($p['id'], ['commission_pct' => 40], 1);
        $this->pay(1);
        $rows = db_connect()->table('partner_commissions')->orderBy('id')->get()->getResultArray();
        $this->assertSame([20.0, 40.0], [(float) $rows[0]['rate_pct'], (float) $rows[1]['rate_pct']], 'each commission keeps the rate it was earned at');
    }

    // ---- coupon-based attribution ------------------------------------------------------------------------------------------------------

    public function testABrandNewCustomerPayingWithAPartnerCouponIsAttributedAtPurchase(): void
    {
        $p = $this->activePartner();
        db_connect()->table('coupons')->insert(['code' => 'PRIYA10', 'type' => 'percent', 'value' => 10, 'partner_id' => $p['id'], 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->pay(1, 'growth', 'monthly', 'PRIYA10');
        $t = db_connect()->table('tenants')->where('id', 1)->get()->getRowArray();
        $this->assertSame([$p['id'], 'coupon'], [(int) $t['referred_partner_id'], $t['referral_source']]);
        $c = db_connect()->table('partner_commissions')->get()->getRowArray();
        $this->assertSame(359_910, (int) $c['base_paise']);
    }

    public function testAnExistingPayingCustomerCannotBePoachedWithAPartnerCoupon(): void
    {
        $p = $this->activePartner();
        $this->pay(1);                                                    // already a paying customer, nobody referred them
        db_connect()->table('coupons')->insert(['code' => 'POACH', 'type' => 'percent', 'value' => 10, 'partner_id' => $p['id'], 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->pay(1, 'pro', 'monthly', 'POACH');
        $this->assertNull(db_connect()->table('tenants')->where('id', 1)->get()->getRowArray()['referred_partner_id']);
        $this->assertSame(0, db_connect()->table('partner_commissions')->countAllResults());
    }

    public function testTheReferralPartnerKeepsTheSaleWhenAnotherPartnersCouponIsUsed(): void
    {
        $a = $this->activePartner([], 'a@partner.test'); $b = $this->activePartner(['name' => 'Bala'], 'b@partner.test');
        $this->svc()->attributeSignup(1, $a['code'], 'owner1@customer1.test');
        db_connect()->table('coupons')->insert(['code' => 'BALA10', 'type' => 'percent', 'value' => 10, 'partner_id' => $b['id'], 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->pay(1, 'growth', 'monthly', 'BALA10');
        $this->assertSame($a['id'], (int) db_connect()->table('partner_commissions')->get()->getRowArray()['partner_id']);
    }

    // ---- void / balances / payouts -----------------------------------------------------------------------------------------------------

    private function partnerWithCommissions(int $hold = 0): array
    {
        $p = $this->activePartner(['hold_days' => $hold]);
        $this->svc()->attributeSignup(1, $p['code'], 'owner1@customer1.test');
        return $p;
    }

    public function testBalancesSplitPendingAvailablePaidAndVoid(): void
    {
        $p = $this->partnerWithCommissions(0);
        $this->pay(1);                                                    // available now (no hold)
        $this->svc()->update($p['id'], ['hold_days' => 30], 1);
        $this->pay(1);                                                    // pending for 30 days
        $b = $this->svc()->balances($p['id']);
        $this->assertSame([79_980, 79_980, 0, 0, 159_960], [$b['available_paise'], $b['pending_paise'], $b['paid_paise'], $b['void_paise'], $b['earned_paise']]);
        $id = (int) db_connect()->table('partner_commissions')->orderBy('id', 'DESC')->get()->getRowArray()['id'];
        $this->svc()->voidCommission($id, 'Customer got a refund', 1);
        $b = $this->svc()->balances($p['id']);
        $this->assertSame([0, 79_980, 79_980], [$b['pending_paise'], $b['void_paise'], $b['earned_paise']], 'voided money is not earned');
    }

    public function testAPayoutSettlesExactlyWhatIsAvailableAndRecordsTheReference(): void
    {
        $p = $this->partnerWithCommissions(0);
        $this->pay(1); $this->pay(1); $this->pay(1);                      // 3 x Rs 799.80
        $this->svc()->update($p['id'], ['hold_days' => 30], 1); $this->pay(1);   // a 4th, still on hold
        $r = $this->svc()->payout($p['id'], 'upi', 'UTR123456', 'October', 1, true);
        $this->assertSame([239_940, 3, 'upi', 'UTR123456'], [(int) $r['payout']['amount_paise'], (int) $r['payout']['commissions'], $r['payout']['method'], $r['payout']['reference']]);
        $this->assertSame([0, 79_980, 239_940], [$r['balances']['available_paise'], $r['balances']['pending_paise'], $r['balances']['paid_paise']]);
        $st = array_count_values(array_column(db_connect()->table('partner_commissions')->get()->getResultArray(), 'status'));
        $this->assertSame(['paid' => 3, 'pending' => 1], $st);
        try { $this->svc()->payout($p['id'], 'upi', 'UTR999', '', 1, true); $this->fail('paid twice'); }
        catch (\DomainException $e) { $this->assertStringContainsString('Nothing is available', $e->getMessage()); }
        $this->assertSame(1, (int) db_connect()->table('partner_payouts')->countAllResults(), 'a second click cannot pay the same money again');
    }

    public function testPayoutsBelowTheMinimumNeedAnOverrideAndNeedAReference(): void
    {
        $p = $this->partnerWithCommissions(0);
        $this->pay(1);                                                    // only Rs 799.80 available
        try { $this->svc()->payout($p['id'], 'bank', 'REF1', '', 1); $this->fail('below minimum'); }
        catch (\DomainException $e) { $this->assertStringContainsString('minimum payout', $e->getMessage()); }
        foreach ([['upi', ''], ['cheque', 'REF']] as [$m, $ref]) {
            try { $this->svc()->payout($p['id'], $m, $ref, '', 1, true); $this->fail('bad input'); } catch (\InvalidArgumentException) { /* expected */ }
        }
        $this->assertSame(79_980, (int) $this->svc()->payout($p['id'], 'bank', 'NEFT-77', '', 1, true)['payout']['amount_paise']);
    }

    public function testNothingIsPayableDuringTheHoldAndAPaidCommissionCannotBeVoided(): void
    {
        $p = $this->partnerWithCommissions(30);
        $this->pay(1);
        $this->expectException(\DomainException::class);
        try { $this->svc()->payout($p['id'], 'upi', 'REF', '', 1, true); }
        finally {
            $this->svc()->update($p['id'], ['hold_days' => 0], 1); $this->pay(1);
            $this->svc()->payout($p['id'], 'upi', 'REF2', '', 1, true);
            $paid = (int) db_connect()->table('partner_commissions')->where('status', 'paid')->get()->getRowArray()['id'];
            try { $this->svc()->voidCommission($paid, 'trying to claw back', 1); $this->fail('voided a paid commission'); } catch (\DomainException) { /* expected */ }
        }
    }

    public function testVoidNeedsAReasonAndWorksOnce(): void
    {
        $p = $this->partnerWithCommissions(30); $this->pay(1);
        $id = (int) db_connect()->table('partner_commissions')->get()->getRowArray()['id'];
        try { $this->svc()->voidCommission($id, 'no', 1); $this->fail('short reason'); } catch (\InvalidArgumentException) { /* expected */ }
        $this->svc()->voidCommission($id, 'Chargeback received', 1);
        $this->expectException(\DomainException::class);
        $this->svc()->voidCommission($id, 'Chargeback received', 1);
    }

    // ---- payout details --------------------------------------------------------------------------------------------------------------------

    public function testPayoutDetailsAreEncryptedMaskedForThePartnerAndNeedThePassword(): void
    {
        $p = $this->activePartner();
        $masked = $this->svc()->savePayoutDetails($p['id'], ['method' => 'bank', 'account_name' => 'Priya Sharma', 'account_no' => '123456789012', 'ifsc' => 'hdfc0001234', 'pan' => 'abcde1234f'], self::PW);
        $this->assertSame(['bank', '••••••••9012', 'HDFC0001234', '••••••234F'], [$masked['method'], $masked['account_no'], $masked['ifsc'], $masked['pan']]);
        $raw = db_connect()->table('partners')->get()->getRowArray()['payout_enc'];
        foreach (['123456789012', 'HDFC0001234', 'ABCDE1234F', 'Priya Sharma'] as $secret) { $this->assertStringNotContainsString($secret, (string) $raw, "{$secret} must not be readable in the database"); }
        $this->assertSame('123456789012', $this->svc()->payoutDetails($p['id'])['account_no'], 'the admin can decrypt it to make the transfer');
        $this->assertSame(1, db_connect()->table('partner_events')->where('action', 'payout_details.changed')->countAllResults());
        $this->expectException(\DomainException::class);
        $this->svc()->savePayoutDetails($p['id'], ['method' => 'upi', 'upi' => 'x@okbank'], 'wrong password');
    }

    public function testPayoutDetailValidation(): void
    {
        $p = $this->activePartner();
        foreach ([
            ['method' => 'upi', 'upi' => 'not-a-upi'], ['method' => 'bank', 'account_name' => '', 'account_no' => '123456', 'ifsc' => 'HDFC0001234'],
            ['method' => 'bank', 'account_name' => 'A', 'account_no' => 'abc', 'ifsc' => 'HDFC0001234'], ['method' => 'bank', 'account_name' => 'A', 'account_no' => '123456', 'ifsc' => 'BADIFSC'],
            ['method' => 'bank', 'account_name' => 'A', 'account_no' => '123456', 'ifsc' => 'HDFC0001234', 'pan' => 'NOTAPAN'], ['method' => 'crypto'],
        ] as $bad) {
            try { $this->svc()->savePayoutDetails($p['id'], $bad, self::PW); $this->fail('accepted ' . json_encode($bad)); } catch (\InvalidArgumentException) { /* expected */ }
        }
        $this->assertSame('••••••••bank', $this->svc()->savePayoutDetails($p['id'], ['method' => 'upi', 'upi' => 'Priya@OkBank'], self::PW)['upi']);
    }

    public function testChangingThePasswordNeedsTheCurrentOne(): void
    {
        $p = $this->activePartner();
        try { $this->svc()->changePassword($p['id'], 'wrong', 'another long passphrase'); $this->fail('changed with wrong current'); } catch (\DomainException) { /* expected */ }
        try { $this->svc()->changePassword($p['id'], self::PW, 'short'); $this->fail('weak'); } catch (\InvalidArgumentException) { /* expected */ }
        $this->svc()->changePassword($p['id'], self::PW, 'another long passphrase');
        $this->assertNotNull($this->svc()->verifyLogin('priya@partner.test', 'another long passphrase'));
    }

    // ---- what each side sees ------------------------------------------------------------------------------------------------------------

    public function testAPartnerSeesOnlyTheirOwnReferralsAndNeverCustomerContactDetails(): void
    {
        $a = $this->activePartner([], 'a@partner.test'); $b = $this->activePartner(['name' => 'Bala'], 'b@partner.test');
        $this->svc()->attributeSignup(1, $a['code'], 'owner1@customer1.test');
        $this->svc()->attributeSignup(2, $b['code'], 'owner2@customer2.test');
        $this->pay(1); $this->pay(2);
        $pa = $this->svc()->portal($a['id']);
        $this->assertSame(['Alpha Travels'], array_column($pa['referred'], 'workspace'));
        $this->assertSame(['Alpha Travels'], array_column($pa['commissions'], 'workspace'));
        $json = json_encode($pa);
        foreach (['Beta Holidays', 'owner1@customer1.test', 'owner2@customer2.test', 'customer1.test', 'password_hash'] as $leak) { $this->assertStringNotContainsString($leak, $json, "portal must not expose {$leak}"); }
        $this->assertSame('paying', $pa['referred'][0]['state']);
        $this->assertSame('growth', $pa['referred'][0]['plan']);
        $this->assertStringContainsString($a['code'], $pa['links']['app_link']);
    }

    public function testThePortalMarksCommissionsAsAvailableOnceTheHoldHasPassed(): void
    {
        $p = $this->partnerWithCommissions(30);
        $this->pay(1);
        $this->assertSame('pending', $this->svc()->portal($p['id'])['commissions'][0]['status']);
        $later = new PartnerService(time() + 31 * 86400);
        $this->assertSame('available', $later->portal($p['id'])['commissions'][0]['status']);
        $this->assertSame(79_980, $later->balances($p['id'])['available_paise']);
    }

    public function testTheAdminListAndDetailCarryTheNumbers(): void
    {
        $p = $this->partnerWithCommissions(0);
        $this->svc()->attributeSignup(2, $p['code'], 'owner2@customer2.test');
        $this->pay(1);
        $row = array_values(array_filter($this->svc()->partners(), fn ($x) => $x['id'] === $p['id']))[0];
        $this->assertSame([2, 1, 79_980], [$row['referrals'], $row['paying'], $row['balances']['available_paise']]);
        $d = $this->svc()->detail($p['id']);
        $names = array_column($d['referred'], 'name'); sort($names);
        $this->assertSame(['Alpha Travels', 'Beta Holidays'], $names);
        $this->assertSame(1, count($d['commissions']));
        $this->assertSame(PartnerService::MIN_PAYOUT_PAISE, $d['min_payout_paise']);
    }
}
