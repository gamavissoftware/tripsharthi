<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use App\Models\ContactFieldValueModel;
use App\Services\Leads\ContactDedupeService;
use App\Services\Push\DigestPush;
use App\Services\Push\EventPush;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\MobileDeviceService;
use App\Services\Push\PushNotifier;
use App\Services\Push\PushPreferenceService;
use App\Services\Travel\TravelTriggerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh. The Expo API is faked at the HTTP layer. */
#[Group('mysql')]
final class PushTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    // 2026-10-08 06:00 UTC = 11:30 IST
    private const NOW = 1_791_439_200;
    private const T1 = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';
    private const T2 = 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]';

    /** @var list<array> every request body sent to Expo */
    private array $sent = [];
    /** @var array<string,array> token => ticket override (e.g. DeviceNotRegistered) */
    private array $override = [];
    private ?int $httpStatus = null;
    private array $receipts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['push_log', 'push_preferences', 'mobile_devices', 'notifications', 'travel_trigger_log', 'jobs', 'flows', 'bookings', 'booking_payments', 'tasks', 'itineraries', 'trips', 'conversations', 'contacts', 'ad_campaigns', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'T', 'slug' => 't', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        foreach ([[1, 'owner'], [2, 'admin'], [3, 'agent'], [4, 'agent']] as [$id, $role]) {
            $db->table('users')->insert(['id' => $id, 'tenant_id' => 1, 'name' => "U{$id}", 'email' => "u{$id}@t.test", 'password_hash' => 'x', 'role' => $role, 'created_at' => '2026-01-01 00:00:00']);
        }
        $this->sent = []; $this->override = []; $this->httpStatus = null; $this->receipts = [];
        putenv('PUSH_MOCK_MODE=false'); $_ENV['PUSH_MOCK_MODE'] = 'false'; putenv('PUSH_HOURLY_CAP=30'); unset($_ENV['PUSH_HOURLY_CAP']);
    }

    private function client(): ExpoPushClient
    {
        return new ExpoPushClient(function (string $method, string $url, array $opt): array {
            if ($this->httpStatus !== null) { return ['status' => $this->httpStatus, 'body' => '{}']; }
            $json = $opt['json'];
            if (str_contains($url, 'getReceipts')) {
                return ['status' => 200, 'body' => json_encode(['data' => $this->receipts])];
            }
            $this->sent[] = $json;
            $tickets = array_map(fn ($m) => $this->override[$m['to']] ?? ['status' => 'ok', 'id' => 'tk-' . substr(md5($m['to'] . count($this->sent)), 0, 10)], $json);
            return ['status' => 200, 'body' => json_encode(['data' => $tickets])];
        });
    }

    private function n(?int $now = null): PushNotifier { return new PushNotifier($this->client(), $now ?? self::NOW); }

    private function device(int $user, string $token = self::T1): int
    {
        $d = (new MobileDeviceService())->register(1, $user, ['expo_token' => $token, 'platform' => 'ios', 'device_name' => 'iPhone']);
        return $d['id'];
    }

    private function msgs(): array { return array_merge(...array_map(static fn ($b) => $b, $this->sent)) ?: []; }

    // ---- pure -----------------------------------------------------------------------------------------------

    public function testExpoClientRequestShapeAndLimits(): void
    {
        $calls = [];
        putenv('EXPO_ACCESS_TOKEN=secret-token');
        $c = new ExpoPushClient(function ($m, $u, $o) use (&$calls) { $calls[] = [$m, $u, $o]; return ['status' => 200, 'body' => json_encode(['data' => [['status' => 'ok', 'id' => 'X'], ['status' => 'error', 'message' => 'bad', 'details' => ['error' => 'DeviceNotRegistered']]]])]; });
        $t = $c->send([['to' => self::T1, 'title' => 'a', 'body' => 'b'], ['to' => self::T2, 'title' => 'a', 'body' => 'b']]);
        putenv('EXPO_ACCESS_TOKEN');
        $this->assertSame('https://exp.host/--/api/v2/push/send', $calls[0][1]);
        $this->assertSame('Bearer secret-token', $calls[0][2]['headers']['Authorization']);
        $this->assertSame(['status' => 'ok', 'id' => 'X', 'error' => null, 'message' => null], $t[0]);
        $this->assertSame('DeviceNotRegistered', $t[1]['error']);
        $this->assertTrue(ExpoPushClient::isDeadToken('DeviceNotRegistered'));
        $this->assertFalse(ExpoPushClient::isDeadToken('MessageRateExceeded'));
        $this->expectException(\InvalidArgumentException::class);
        $c->send(array_fill(0, 101, ['to' => self::T1]));
    }

    public function testTokenValidationAndQuietHoursAndPreferenceDecisions(): void
    {
        $this->assertTrue(ExpoPushClient::validToken('ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]'));
        $this->assertTrue(ExpoPushClient::validToken('ExpoPushToken[abc_DEF-1234567]'));
        foreach (['', 'abc', 'ExponentPushToken[]', 'ExponentPushToken[short]', 'FCM:abcdef', "ExponentPushToken[x\"; DROP]"] as $bad) { $this->assertFalse(ExpoPushClient::validToken($bad), $bad); }

        // 11:30 IST. A window 22:00–07:00 wraps midnight; 11:00–12:00 contains now.
        $this->assertFalse(PushPreferenceService::inQuietHours('22:00', '07:00', self::NOW));
        $this->assertTrue(PushPreferenceService::inQuietHours('22:00', '07:00', self::NOW + 14 * 3600));   // 01:30 IST
        $this->assertTrue(PushPreferenceService::inQuietHours('11:00', '12:00', self::NOW));
        $this->assertFalse(PushPreferenceService::inQuietHours(null, null, self::NOW));
        $on = ['enabled' => true, 'categories' => ['lead' => true, 'ads' => true, 'quote' => false], 'quiet_start' => null, 'quiet_end' => null];
        $this->assertNull(PushPreferenceService::blockedReason($on, 'lead', self::NOW));
        $this->assertSame('category_off', PushPreferenceService::blockedReason($on, 'quote', self::NOW));
        $this->assertSame('notifications_off', PushPreferenceService::blockedReason(['enabled' => false] + $on, 'lead', self::NOW));
        $this->assertSame('staff_only', PushPreferenceService::blockedReason($on, 'ads', self::NOW, 'agent'));
        $this->assertNull(PushPreferenceService::blockedReason($on, 'ads', self::NOW, 'admin'));
    }

    // ---- devices ---------------------------------------------------------------------------------------------

    public function testDeviceRegistrationMovesTokensAndOnlyTheOwnerCanUnregister(): void
    {
        $svc = new MobileDeviceService();
        try { $svc->register(1, 3, ['expo_token' => 'nonsense']); $this->fail('invalid token accepted'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }

        $svc->register(1, 3, ['expo_token' => self::T1, 'platform' => 'ios']);
        $svc->register(1, 4, ['expo_token' => self::T1, 'platform' => 'ios']);          // same phone, now signed in as someone else
        $this->assertCount(0, $svc->forUser(1, 3));
        $this->assertCount(1, $svc->forUser(1, 4));
        $this->assertSame(1, db_connect()->table('mobile_devices')->countAllResults());   // moved, not duplicated

        $this->assertFalse($svc->unregister(1, 3, self::T1));                           // user 3 no longer owns it
        $this->assertTrue($svc->unregister(1, 4, self::T1));
        $this->assertCount(0, $svc->forUser(1, 4));
        $this->assertStringNotContainsString('aaaaaaaaaa', json_encode($svc->register(1, 4, ['expo_token' => self::T1])));   // token never echoed back
    }

    public function testAtMostTenActiveDevicesPerUser(): void
    {
        $svc = new MobileDeviceService();
        for ($i = 0; $i < 12; $i++) { $svc->register(1, 3, ['expo_token' => sprintf('ExponentPushToken[%022d]', $i)]); }
        $this->assertCount(10, $svc->forUser(1, 3));
    }

    // ---- notify ----------------------------------------------------------------------------------------------

    public function testNotifyCreatesInAppRowAndSendsToEveryDeviceWithCorrectPayload(): void
    {
        $this->device(3, self::T1); $this->device(3, self::T2);
        $r = $this->n()->notify(1, 3, 'quote', 'quote_viewed', '👀 Rohit opened your quote', 'Bali · ₹51,729', ['screen' => 'Trip', 'params' => ['id' => 7]], 'qv:1', '/trips/7');
        $this->assertSame('queued', $r['status']);
        $this->assertSame(2, $r['devices']);

        $m = $this->msgs();
        $this->assertCount(2, $m);
        $this->assertSame([self::T1, self::T2], array_column($m, 'to'));
        $this->assertSame('quote', $m[0]['channelId']);
        $this->assertSame('high', $m[0]['priority']);
        $this->assertEquals(['screen' => 'Trip', 'params' => ['id' => 7], 'category' => 'quote', 'event' => 'quote_viewed'], $m[0]['data']);
        $this->assertSame(1, $m[0]['badge']);                                              // unread in-app count drives the app-icon badge
        $this->assertSame(1, db_connect()->table('notifications')->where('user_id', 3)->countAllResults());
        $this->assertSame(2, db_connect()->table('push_log')->where('status', 'sent')->countAllResults());
    }

    public function testTheSameEventNeverNotifiesTwice(): void
    {
        $this->device(3);
        $this->n()->notify(1, 3, 'lead', 'lead_new', 'x', 'y', [], 'lead:5');
        $second = $this->n()->notify(1, 3, 'lead', 'lead_new', 'x', 'y', [], 'lead:5');
        $this->assertSame('duplicate', $second['status']);
        $this->assertCount(1, $this->msgs());
        $this->assertSame(1, db_connect()->table('notifications')->countAllResults());
        $this->device(4, self::T2);
        $this->assertSame('queued', $this->n()->notify(1, 4, 'lead', 'lead_new', 'x', 'y', [], 'lead:5')['status']);   // dedupe is per person
    }

    public function testPreferencesSuppressThePushButNeverTheInAppRecord(): void
    {
        $this->device(3);
        (new PushPreferenceService())->save(1, 3, ['categories' => ['quote' => false]]);
        $r = $this->n()->notify(1, 3, 'quote', 'quote_viewed', 't', 'b');
        $this->assertSame(['suppressed', 'category_off'], [$r['status'], $r['reason']]);
        $this->assertCount(0, $this->sent);
        $this->assertSame(1, db_connect()->table('notifications')->where('user_id', 3)->countAllResults());   // still in the app's inbox
        $log = db_connect()->table('push_log')->get()->getRowArray();
        $this->assertSame(['suppressed', 'category_off'], [$log['status'], $log['reason']]);                  // "why didn't I get it?" is answerable

        (new PushPreferenceService())->save(1, 3, ['enabled' => false]);
        $this->assertSame('notifications_off', $this->n()->notify(1, 3, 'lead', 'lead_new', 't', 'b')['reason']);
    }

    public function testQuietHoursSuppressInIst(): void
    {
        $this->device(3);
        (new PushPreferenceService())->save(1, 3, ['quiet_start' => '11:00', 'quiet_end' => '13:00']);
        $this->assertSame('quiet_hours', $this->n()->notify(1, 3, 'lead', 'lead_new', 't', 'b')['reason']);       // 11:30 IST
        $this->assertSame('queued', $this->n(self::NOW + 3 * 3600)->notify(1, 3, 'lead', 'lead_new', 't', 'b')['status']);   // 14:30 IST
        $this->expectException(\InvalidArgumentException::class);
        (new PushPreferenceService())->save(1, 3, ['quiet_start' => '22:00', 'quiet_end' => null]);
    }

    public function testStaffOnlyCategoriesNeverReachAgents(): void
    {
        $this->device(3); $this->device(2, self::T2);
        $this->assertSame('staff_only', $this->n()->notify(1, 3, 'ads', 'ad_rule', 't', 'b')['reason']);
        $this->assertSame('queued', $this->n()->notify(1, 2, 'ads', 'ad_rule', 't', 'b')['status']);
    }

    public function testMinimalPrivacyHidesNamesAndAmountsOnTheLockScreen(): void
    {
        $this->device(3);
        (new PushPreferenceService())->save(1, 3, ['privacy' => 'minimal']);
        $this->n()->notify(1, 3, 'payment', 'payment_received', '💰 ₹18,105 received', 'Rohit Sharma · TP-2026-0001');
        $m = $this->msgs()[0];
        $this->assertStringNotContainsString('18,105', json_encode($m));
        $this->assertStringNotContainsString('Rohit', json_encode($m));
        $this->assertSame('Payment update', $m['title']);
        $this->assertStringContainsString('18,105', db_connect()->table('notifications')->get()->getRowArray()['body']);   // the in-app inbox keeps the detail
    }

    public function testFloodThrottleSendsOneSummaryInsteadOfAStorm(): void
    {
        putenv('PUSH_HOURLY_CAP=5'); $_ENV['PUSH_HOURLY_CAP'] = '5';
        $this->device(3);
        for ($i = 1; $i <= 12; $i++) { $this->n()->notify(1, 3, 'payment', 'payment_overdue', "overdue {$i}", 'b', [], "po:{$i}"); }
        $titles = array_column($this->msgs(), 'title');
        $this->assertCount(6, $titles);                                                  // 5 normal + exactly ONE summary
        $this->assertSame(1, count(array_filter($titles, static fn ($t) => $t === 'More updates waiting')));
        $this->assertSame(12, db_connect()->table('notifications')->where('user_id', 3)->countAllResults());   // the in-app inbox still has all 12
    }

    public function testNoDeviceIsRecordedNotSilentlyDropped(): void
    {
        $r = $this->n()->notify(1, 3, 'lead', 'lead_new', 't', 'b');
        $this->assertSame(['suppressed', 'no_device'], [$r['status'], $r['reason']]);
        $this->assertSame(1, db_connect()->table('notifications')->countAllResults());
    }

    // ---- delivery failures ----------------------------------------------------------------------------------------

    public function testADeadDeviceIsDisabledWithoutAffectingTheOthers(): void
    {
        $dead = $this->device(3, self::T1); $this->device(3, self::T2);
        $this->override[self::T1] = ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']];
        $this->n()->notify(1, 3, 'lead', 'lead_new', 't', 'b');
        $this->assertNotNull(db_connect()->table('mobile_devices')->where('id', $dead)->get()->getRowArray()['disabled_at']);
        $this->assertSame('DeviceNotRegistered', db_connect()->table('mobile_devices')->where('id', $dead)->get()->getRowArray()['disabled_reason']);
        $this->assertCount(1, (new MobileDeviceService())->forUser(1, 3));
        $this->sent = [];
        $this->n()->notify(1, 3, 'lead', 'lead_new', 't2', 'b');                          // next push only goes to the healthy phone
        $this->assertSame([self::T2], array_column($this->msgs(), 'to'));
    }

    public function testExpoOutageKeepsPushesQueuedAndTheCronDeliversThem(): void
    {
        $this->device(3);
        $this->httpStatus = 503;
        $this->n()->notify(1, 3, 'lead', 'lead_new', 't', 'b');
        $this->assertSame('queued', db_connect()->table('push_log')->get()->getRowArray()['status']);
        $this->assertSame('1', (string) db_connect()->table('push_log')->get()->getRowArray()['attempts']);

        $this->httpStatus = null;
        $r = $this->n(self::NOW + 60)->maintain();
        $this->assertSame(1, $r['retried']);
        $this->assertSame('sent', db_connect()->table('push_log')->get()->getRowArray()['status']);
    }

    public function testGivesUpAfterMaxAttemptsAndTreats4xxAsPermanent(): void
    {
        $this->device(3);
        $this->httpStatus = 503;
        $this->n()->notify(1, 3, 'lead', 'lead_new', 't', 'b');
        for ($i = 1; $i <= 6; $i++) { $this->n(self::NOW + 60 * $i)->maintain(); }
        $this->assertSame(['failed', 'expo_unreachable'], array_values(array_intersect_key(db_connect()->table('push_log')->get()->getRowArray(), array_flip(['status', 'reason']))));

        db_connect()->table('push_log')->truncate();
        $this->httpStatus = 400;
        $this->n()->notify(1, 3, 'lead', 'lead_new', 't', 'b');
        $this->assertSame('failed', db_connect()->table('push_log')->get()->getRowArray()['status']);          // our request is wrong: no retry loop
    }

    public function testReceiptsConfirmDeliveryAndDisableDeadTokens(): void
    {
        $d1 = $this->device(3, self::T1); $d2 = $this->device(3, self::T2);
        $this->n()->notify(1, 3, 'lead', 'lead_new', 't', 'b');
        $tickets = db_connect()->table('push_log')->select('id, ticket_id, device_id')->orderBy('id')->get()->getResultArray();
        $this->receipts = [$tickets[0]['ticket_id'] => ['status' => 'ok'], $tickets[1]['ticket_id'] => ['status' => 'error', 'message' => 'x', 'details' => ['error' => 'DeviceNotRegistered']]];

        $this->assertSame(0, $this->n(self::NOW + 60)->maintain()['receipts']);       // too early: receipts are checked ~15 min after sending
        $r = $this->n(self::NOW + 1000)->maintain();
        $this->assertSame(2, $r['receipts']);
        $this->assertSame(1, $r['disabled']);
        $st = array_column(db_connect()->table('push_log')->select('device_id, status')->get()->getResultArray(), 'status', 'device_id');
        $this->assertSame(['delivered', 'error'], [$st[$d1], $st[$d2]]);
        $this->assertNull(db_connect()->table('mobile_devices')->where('id', $d1)->get()->getRowArray()['disabled_at']);
        $this->assertNotNull(db_connect()->table('mobile_devices')->where('id', $d2)->get()->getRowArray()['disabled_at']);
    }

    public function testTestPushIsRateLimitedAndNeedsADevice(): void
    {
        try { $this->n()->sendTest(1, 3); $this->fail('no device'); } catch (\DomainException $e) { $this->assertStringContainsString('No phone', $e->getMessage()); }
        $this->device(3);
        for ($i = 0; $i < 5; $i++) { $this->assertSame(1, $this->n()->sendTest(1, 3)['sent']); }
        $this->expectException(\DomainException::class);
        $this->n()->sendTest(1, 3);
    }

    // ---- business events -------------------------------------------------------------------------------------------------

    private function trip(array $o = []): int
    {
        db_connect()->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Rohit Sharma', 'wa_number' => '+919876543210', 'status' => 'new', 'source' => 'manual', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
        db_connect()->table('trips')->insert($o + ['tenant_id' => 1, 'contact_id' => 1, 'owner_id' => 3, 'title' => 'Bali', 'destination_text' => 'Bali', 'adults' => 2, 'status' => 'quoted', 'created_at' => '2026-09-01 00:00:00']);
        return (int) db_connect()->insertID();
    }

    public function testQuoteViewedPushesTheTripOwnerOnceAndDeepLinksToTheTrip(): void
    {
        $this->device(3); $this->device(1, self::T2);
        $trip = $this->trip();
        db_connect()->table('itineraries')->insert(['tenant_id' => 1, 'trip_id' => $trip, 'title' => 'Bali 6N', 'status' => 'sent', 'grand_total' => 5_172_930, 'share_token' => bin2hex(random_bytes(16)), 'created_at' => '2026-09-01 00:00:00']);
        $it = (int) db_connect()->insertID();

        // Route through the real trigger service, with Expo faked via the mock-mode-off + our client in PushNotifier::safely default.
        putenv('PUSH_MOCK_MODE=true'); $_ENV['PUSH_MOCK_MODE'] = 'true';
        (new TravelTriggerService(self::NOW))->quoteEvent(1, $it, 'quote_viewed');
        (new TravelTriggerService(self::NOW))->quoteEvent(1, $it, 'quote_viewed');             // second view: claimed already, no second push
        $rows = db_connect()->table('push_log')->get()->getResultArray();
        $this->assertCount(1, $rows);                                                           // owner (user 3) only — not the admins
        $this->assertSame('3', (string) $rows[0]['user_id']);
        $d = json_decode($rows[0]['data'], true);
        $this->assertSame(['screen' => 'Trip', 'params' => ['id' => $trip]], ['screen' => $d['screen'], 'params' => $d['params']]);
        $this->assertStringContainsString('Rohit Sharma opened your quote', $rows[0]['title']);
        $this->assertStringContainsString('₹51,729', $rows[0]['body']);
    }

    public function testOwnerlessRecordsFallBackToOwnersAndAdmins(): void
    {
        $this->device(1); $this->device(2, self::T2); $this->device(3, 'ExponentPushToken[cccccccccccccccccccccc]');
        $trip = $this->trip(['owner_id' => null]);
        db_connect()->table('itineraries')->insert(['tenant_id' => 1, 'trip_id' => $trip, 'title' => 'x', 'status' => 'sent', 'grand_total' => 100_000, 'share_token' => bin2hex(random_bytes(16)), 'created_at' => '2026-09-01 00:00:00']);
        $it = (int) db_connect()->insertID();
        putenv('PUSH_MOCK_MODE=true'); $_ENV['PUSH_MOCK_MODE'] = 'true';
        (new TravelTriggerService(self::NOW))->quoteEvent(1, $it, 'quote_accepted');
        $users = array_map('intval', array_column(db_connect()->table('push_log')->get()->getResultArray(), 'user_id'));
        sort($users);
        $this->assertSame([1, 2], $users);                                                      // staff, never a random agent
    }

    public function testNewAdLeadPushesButUpdatesAndImportsDoNot(): void
    {
        $this->device(1);
        putenv('PUSH_MOCK_MODE=true'); $_ENV['PUSH_MOCK_MODE'] = 'true';
        db_connect()->table('ad_campaigns')->insert(['tenant_id' => 1, 'platform' => 'meta', 'external_id' => '1201', 'name' => 'Bali Honeymoon – Nov', 'created_at' => '2026-10-01 00:00:00']);
        $svc = new ContactDedupeService(new ContactModel(), new ContactFieldValueModel());

        $svc->upsert(1, ['wa_number' => '+919811100001', 'name' => 'Priya Nair', 'source' => 'meta_lead_ads', '_attribution' => ['platform' => 'meta', 'campaign_id' => '1201', 'lead_id' => '99']]);
        $svc->upsert(1, ['wa_number' => '+919811100001', 'name' => 'Priya Nair', 'source' => 'meta_lead_ads']);          // same lead again = update, not new
        $svc->upsert(1, ['wa_number' => '+919811100002', 'name' => 'Bulk', 'source' => 'csv_import']);                    // import: silent
        $svc->upsert(1, ['wa_number' => '+919811100003', 'name' => 'Silent', 'source' => 'meta_lead_ads'], false);        // triggers off (archive import): silent

        $rows = db_connect()->table('push_log')->where('status', 'sent')->get()->getResultArray();      // (the admin has no phone: a 'no_device' row, not a push)
        $this->assertCount(1, $rows);
        $this->assertSame('🔔 New lead: Priya Nair', $rows[0]['title']);
        $this->assertSame('Facebook/Instagram lead ad · Bali Honeymoon – Nov', $rows[0]['body']);   // which ad produced it
        $this->assertSame('Contact', json_decode($rows[0]['data'], true)['screen']);
    }

    public function testFirstWhatsAppMessageFromANewContactIsALeadAnExistingOneIsAReply(): void
    {
        $this->device(1);
        db_connect()->table('contacts')->insert(['id' => 9, 'tenant_id' => 1, 'name' => 'New Person', 'wa_number' => '+919800000009', 'status' => 'new', 'source' => 'whatsapp_inbound', 'opt_in' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $n = $this->n();
        EventPush::reply($n, 1, ['id' => 5, 'contact_id' => 9, 'contact_name' => 'New Person', 'last_inbound_at' => '2026-10-08 11:00:00'], 'Hi, Bali for 4 people in Dec?', true, ['owner_id' => null]);
        EventPush::reply($n, 1, ['id' => 6, 'contact_id' => 9, 'contact_name' => 'New Person', 'last_inbound_at' => '2026-10-08 12:00:00'], 'And what about visas?', false, ['owner_id' => null]);
        $titles = array_column($this->msgs(), 'title');
        $this->assertSame(['🔔 New WhatsApp lead: New Person', '💬 New Person'], $titles);
        $this->assertSame(['lead', 'reply'], array_column(db_connect()->table('push_log')->where('user_id', 1)->orderBy('id')->get()->getResultArray(), 'category'));
    }

    public function testLegacyInAppNotificationsAreMirroredToThePhoneWithoutDuplicatingTheInAppRow(): void
    {
        $this->device(2);
        putenv('PUSH_MOCK_MODE=true'); $_ENV['PUSH_MOCK_MODE'] = 'true';
        \App\Services\Crm\NotificationService::notify(1, 2, 'ad_rule', 'Rule “CPL too high” on “Bali”: Cost per lead ₹193 is above ₹100. The campaign was paused.', '/ads/campaigns');
        $this->assertSame(1, db_connect()->table('notifications')->where('user_id', 2)->countAllResults());      // exactly one in-app row
        $log = db_connect()->table('push_log')->get()->getRowArray();
        $this->assertSame(['ads', 'sent'], [$log['category'], $log['status']]);
    }

    public function testMorningBriefingIsPerPersonOncePerDayAndSkippedWhenEmpty(): void
    {
        $this->device(3); $this->device(4, self::T2);
        $this->trip();
        $db = db_connect();
        $db->table('bookings')->insert(['tenant_id' => 1, 'trip_id' => 1, 'contact_id' => 1, 'owner_id' => 3, 'booking_ref' => 'TP-1', 'title' => 'Bali', 'status' => 'confirmed', 'travel_start' => '2026-10-08', 'total_amount' => 500_000, 'created_at' => '2026-09-01 00:00:00']);
        $b = (int) $db->insertID();
        $db->table('booking_payments')->insert(['tenant_id' => 1, 'booking_id' => $b, 'label' => 'Balance', 'due_date' => '2026-10-07', 'amount' => 1_810_525, 'status' => 'overdue', 'created_at' => '2026-09-01 00:00:00']);
        $db->table('tasks')->insert(['tenant_id' => 1, 'title' => 'Call', 'status' => 'open', 'assigned_user_id' => 3, 'due_at' => '2026-10-08 09:00:00', 'created_at' => '2026-10-01 00:00:00']);

        $at = strtotime('2026-10-08 08:10:00 +05:30');
        $this->assertFalse(DigestPush::inWindow(self::NOW));                                 // 11:30 IST: outside the 08:00–08:45 window
        $this->assertTrue(DigestPush::inWindow($at));
        putenv('PUSH_MOCK_MODE=true'); $_ENV['PUSH_MOCK_MODE'] = 'true';
        $r = (new DigestPush(new PushNotifier(null, $at), $at))->run();
        $this->assertSame(['users' => 2, 'sent' => 1], $r);                                    // agent 4 owns nothing: no empty digest
        $row = $db->table('push_log')->get()->getRowArray();
        $this->assertSame('3', (string) $row['user_id']);
        $this->assertSame('1 departure today · 1 payment due (₹18,105) · 1 task to do', $row['body']);
        $this->assertSame(['users' => 2, 'sent' => 0], (new DigestPush(new PushNotifier(null, $at), $at))->run());   // same day: once only
    }
}
