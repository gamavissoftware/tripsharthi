<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushNotifier;
use App\Services\Push\PushPreferenceService;
use App\Services\Push\TargetPush;
use App\Services\Travel\SalesTargetService;
use App\Services\Travel\TravelHomeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** The "behind pace" nudge for agents (group "mysql"). The Expo API is faked at the HTTP layer. */
#[Group('mysql')]
final class TargetPushTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['push_log', 'push_preferences', 'mobile_devices', 'notifications', 'sales_targets', 'tasks', 'bookings', 'trips', 'deals', 'pipeline_stages', 'pipelines', 'activities', 'contacts', 'audit_logs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'T', 'slug' => 't', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        foreach ([[1, 'owner'], [2, 'admin'], [3, 'agent'], [4, 'agent']] as [$id, $role]) {
            $db->table('users')->insert(['id' => $id, 'tenant_id' => 1, 'name' => "U{$id}", 'email' => "u{$id}@t.test", 'password_hash' => 'x', 'role' => $role, 'created_at' => '2026-01-01 00:00:00']);
        }
        $this->sent = [];
        putenv('PUSH_MOCK_MODE=false'); $_ENV['PUSH_MOCK_MODE'] = 'false';
    }

    private function ist(string $at): int { return (new \DateTimeImmutable($at, new \DateTimeZone('Asia/Kolkata')))->getTimestamp(); }

    private function client(): ExpoPushClient
    {
        return new ExpoPushClient(function (string $method, string $url, array $opt): array {
            if (str_contains($url, 'getReceipts')) { return ['status' => 200, 'body' => json_encode(['data' => []])]; }
            $this->sent[] = $opt['json'];
            return ['status' => 200, 'body' => json_encode(['data' => array_map(fn ($m) => ['status' => 'ok', 'id' => 'tk-' . substr(md5($m['to'] . count($this->sent)), 0, 10)], $opt['json'])])];
        });
    }

    private function nudge(int $now): array { return (new TargetPush(new PushNotifier($this->client(), $now), $now))->run(); }

    private function device(int $user): void
    {
        $tenant = (int) db_connect()->table('users')->select('tenant_id')->where('id', $user)->get()->getRowArray()['tenant_id'];
        db_connect()->table('mobile_devices')->insert(['tenant_id' => $tenant, 'user_id' => $user, 'expo_token' => 'ExponentPushToken[' . str_pad((string) $user, 22, 'x') . ']', 'platform' => 'android', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
    }

    /** a target set long enough ago that it counts */
    private function target(int $user, int $revenue, int $bookings, string $month = '2026-10'): void
    {
        (new SalesTargetService())->save(1, $month, [['user_id' => $user, 'revenue' => $revenue, 'bookings' => $bookings]], 2);
        db_connect()->table('sales_targets')->where('user_id', $user)->update(['updated_at' => '2026-09-01 00:00:00']);
    }

    private function booking(int $owner, int $subtotal, string $created = '2026-10-03 10:00:00'): void
    {
        db_connect()->table('bookings')->insert(['tenant_id' => 1, 'owner_id' => $owner, 'booking_ref' => 'B' . random_int(10000, 99999), 'title' => 'Trip', 'status' => 'confirmed', 'subtotal' => $subtotal, 'total_amount' => $subtotal, 'cost_total' => 0, 'created_at' => $created, 'updated_at' => $created]);
    }

    public function testBehindAgentGetsOneNudgeWithTheirNumbersAndNoRepeatInTheWindow(): void
    {
        $this->device(3); $this->target(3, 30_000_000, 6); $this->booking(3, 5_000_000);          // ₹50k of ₹3L revenue, 1 of 6 bookings, on day 12 of 31
        $now = $this->ist('2026-10-12 11:10:00');
        $r = $this->nudge($now);
        $this->assertSame([1, 1, 1], [$r['agents'], $r['behind'], $r['sent']]);
        $m = $this->sent[0][0];
        $this->assertStringContainsString('₹50,000 of ₹3L revenue (17%)', $m['body']);
        $this->assertStringContainsString('1 of 6 bookings', $m['body']);
        $this->assertStringContainsString('19 days left', $m['body']);
        $this->assertSame(['target', 'target_behind'], [$m['channelId'], $m['data']['event']]);     // Android channel id == category key
        $this->assertStringStartsWith('🎯', $m['title']);
        $this->assertSame('Targets', $m['data']['screen']);                                          // tapping it opens the phone's Targets tab
        $again = $this->nudge($now + 600);                                                           // the job runs every minute: no second push
        $this->assertSame(0, $again['sent']);
        $this->assertCount(1, $this->sent);
    }

    public function testOnPaceAchievedOrNoTargetMeansSilence(): void
    {
        foreach ([3, 4] as $u) { $this->device($u); }
        $this->target(3, 10_000_000, 2); $this->booking(3, 6_000_000);                              // 60% by day 12 (expected 39%): ahead
        // agent 4 has no target at all
        $r = $this->nudge($this->ist('2026-10-12 11:10:00'));
        $this->assertSame([2, 0, 0], [$r['agents'], $r['behind'], $r['sent']]);
        $this->assertSame([], $this->sent);
    }

    public function testOnlyAgentsAreNudgedNotManagers(): void
    {
        foreach ([1, 2, 3] as $u) { $this->device($u); $this->target($u, 30_000_000, 6); }
        $r = $this->nudge($this->ist('2026-10-12 11:10:00'));
        $this->assertSame([1, 1], [$r['agents'], $r['sent']]);                                      // owner and admin are behind too but are not agents
        $this->assertSame(2, $r['managers_sent']);                                                  // ...they get the team summary instead
        foreach ([1, 2] as $mgr) { foreach ($this->bodiesFor($mgr) as $push) { $this->assertStringNotContainsString('A little behind on your target', $push['title']); } }   // never the agent nudge
        $this->assertSame('🎯 1 agent behind pace on targets', $this->bodiesFor(1)[0]['title']);
    }

    public function testTimingOnlyInTheWindowAndOnCheckpointDays(): void
    {
        $this->device(3); $this->target(3, 30_000_000, 6);
        $this->assertSame(0, $this->nudge($this->ist('2026-10-12 15:00:00'))['sent']);                // wrong hour
        $this->assertSame(0, $this->nudge($this->ist('2026-10-05 11:10:00'))['sent']);                // before the first checkpoint
        $this->assertSame(0, $this->nudge($this->ist('2026-10-15 11:10:00'))['sent']);                // between checkpoints
        $this->assertSame(1, $this->nudge($this->ist('2026-10-10 11:10:00'))['sent']);                // day 10
        $this->assertSame(1, $this->nudge($this->ist('2026-10-20 11:10:00'))['sent']);                // day 20 is a new checkpoint, a new nudge
        $this->assertSame(0, $this->nudge($this->ist('2026-10-21 11:10:00'))['sent']);                // already told for day 20
        $this->assertSame([10, 20, null, null], [TargetPush::checkpoint(10), TargetPush::checkpoint(22), TargetPush::checkpoint(23), TargetPush::checkpoint(9)]);
    }

    public function testNoDeviceOrAFreshlyChangedTargetMeansNoNudge(): void
    {
        $this->target(3, 30_000_000, 6);                                                            // no phone registered
        $this->assertSame(0, $this->nudge($this->ist('2026-10-12 11:10:00'))['agents']);
        $this->device(3);
        db_connect()->table('sales_targets')->where('user_id', 3)->update(['updated_at' => '2026-10-11 09:00:00']);   // changed yesterday
        $this->assertSame(0, $this->nudge($this->ist('2026-10-12 11:10:00'))['sent']);
        db_connect()->table('sales_targets')->where('user_id', 3)->update(['updated_at' => '2026-10-09 09:00:00']);   // changed three days ago
        $this->assertSame(1, $this->nudge($this->ist('2026-10-12 11:10:00'))['sent']);
    }

    public function testThePersonCanSwitchItOffAndMinimalPrivacyHidesTheNumbers(): void
    {
        $this->device(3); $this->device(4); $this->target(3, 30_000_000, 6); $this->target(4, 30_000_000, 6);
        (new PushPreferenceService())->save(1, 3, ['categories' => ['target' => false]]);
        (new PushPreferenceService())->save(1, 4, ['privacy' => 'minimal']);
        $r = $this->nudge($this->ist('2026-10-12 11:10:00'));
        $this->assertSame(1, $r['sent']);                                                           // user 3 opted out
        $this->assertSame('category_off', db_connect()->table('push_log')->where('user_id', 3)->get()->getRowArray()['reason']);
        $this->assertStringNotContainsString('₹', json_encode($this->sent));                       // user 4 gets the generic text
        $this->assertStringContainsString('Sales target', $this->sent[0][0]['title']);
    }

    public function testItMentionsOpenEnquiriesWithNoNextStep(): void
    {
        $this->device(3); $this->target(3, 30_000_000, 6);
        db_connect()->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'Rohit', 'wa_number' => '+919876543210', 'status' => 'new', 'source' => 'manual', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
        (new \App\Services\Travel\TripService())->create(1, ['title' => 'Lead', 'contact_id' => 1, 'adults' => 2], 3);      // creates the deal too; the agent owns it
        $this->nudge($this->ist('2026-10-12 11:10:00'));
        $this->assertStringContainsString('1 open enquiry has no next step', $this->sent[0][0]['body']);
    }

    public function testTheNudgeAndTheDashboardAgreeOnTheNumbers(): void
    {
        $this->target(3, 30_000_000, 6); $this->booking(3, 5_000_000);
        $now = $this->ist('2026-10-12 11:10:00');
        $dash = (new TravelHomeService($now))->build(1, 3, false)['target'];
        $a = (new SalesTargetService())->actuals(1, 3, '2026-10-01 00:00:00', '2026-11-01 00:00:00');
        $this->assertSame([$a['revenue'], $a['bookings']], [$dash['revenue']['actual'], $dash['bookings']['actual']]);
        $this->assertSame('behind', $dash['revenue']['status']);
    }

    private function moreAgents(int ...$ids): void
    {
        foreach ($ids as $id) {
            db_connect()->table('users')->insert(['id' => $id, 'tenant_id' => 1, 'name' => "Agent{$id}", 'email' => "a{$id}@t.test", 'password_hash' => 'x', 'role' => 'agent', 'created_at' => '2026-01-01 00:00:00']);
        }
    }

    /** every push body that went to this user */
    private function bodiesFor(int $user): array
    {
        $tok = 'ExponentPushToken[' . str_pad((string) $user, 22, 'x') . ']';
        $out = [];
        foreach ($this->sent as $batch) { foreach ($batch as $m) { if ($m['to'] === $tok) { $out[] = $m; } } }
        return $out;
    }

    public function testManagersGetOneSummaryNamingWhoIsBehindIncludingAgentsWithoutAPhone(): void
    {
        $this->moreAgents(5, 6);
        $this->device(1); $this->device(2);                                      // owner and admin have phones
        $this->device(3);                                                         // agent 3 has a phone; agents 4, 5, 6 do not
        foreach ([3, 4, 5, 6] as $u) { $this->target($u, 30_000_000, 0); }                  // revenue-only targets
        $this->booking(3, 12_000_000);                                            // agent 3: 40% by day 12 (expected 39%) -> on pace
        $this->booking(5, 2_000_000);                                             // agent 5: 7%
        $r = $this->nudge($this->ist('2026-10-12 11:10:00'));
        $this->assertSame([3, 2], [$r['team_behind'], $r['managers_sent']]);      // 4, 5, 6 are behind; both managers told
        $m = $this->bodiesFor(1)[0];
        $this->assertSame('🎯 3 agents behind pace on targets', $m['title']);
        $this->assertStringContainsString('U4 0%', $m['body']);                   // an agent with no phone is still counted
        $this->assertStringContainsString('Agent5 7%', $m['body']);
        $this->assertStringContainsString('with 19 days left', $m['body']);
        $this->assertStringNotContainsString('U3 ', $m['body']);                   // the on-pace agent is not named
        $this->assertSame(0, $r['sent']);                                          // agent 3 is on pace, so no agent nudge
        $this->assertSame($m['body'], $this->bodiesFor(2)[0]['body']);            // admin sees the same summary
    }

    public function testTheSummaryListsTheFurthestBehindFirstAndSaysHowManyMore(): void
    {
        $this->moreAgents(5, 6);
        $this->device(1);
        foreach ([3, 4, 5, 6] as $u) { $this->target($u, 30_000_000, 0); }
        $this->booking(3, 6_000_000); $this->booking(4, 3_000_000); $this->booking(5, 1_000_000);   // 20%, 10%, 3%, and agent 6 at 0%
        $this->nudge($this->ist('2026-10-12 11:10:00'));
        $body = $this->bodiesFor(1)[0]['body'];
        $this->assertStringStartsWith('Agent6 0% · Agent5 3% · U4 10% · +1 more.', $body);
    }

    public function testNoSummaryWhenEveryoneIsOnPaceAndNoRepeatInTheWindow(): void
    {
        $this->device(1); $this->device(3); $this->target(3, 10_000_000, 0); $this->booking(3, 6_000_000);
        $r = $this->nudge($this->ist('2026-10-12 11:10:00'));
        $this->assertSame([0, 0], [$r['team_behind'], $r['managers_sent']]);
        $this->assertSame([], $this->sent);

        $this->target(3, 90_000_000, 0);                                           // now far behind
        $this->assertSame(1, $this->nudge($this->ist('2026-10-12 11:20:00'))['managers_sent']);
        $this->assertSame(0, $this->nudge($this->ist('2026-10-12 11:30:00'))['managers_sent']);   // not again in the same checkpoint
        db_connect()->table('sales_targets')->where('user_id', 3)->update(['updated_at' => '2026-09-01 00:00:00']);
        $this->assertSame(1, $this->nudge($this->ist('2026-10-20 11:10:00'))['managers_sent']);   // a new checkpoint, a new summary
    }

    public function testAgentsWithAFreshTargetAreLeftOutAndManagersCanOptOutOrGoMinimal(): void
    {
        $this->moreAgents(5);
        $this->device(1); $this->device(2);
        foreach ([3, 4, 5] as $u) { $this->target($u, 30_000_000, 6); }
        db_connect()->table('sales_targets')->where('user_id', 5)->update(['updated_at' => '2026-10-11 09:00:00']);   // agent 5's target is new: not judged yet
        (new PushPreferenceService())->save(1, 1, ['categories' => ['target' => false]]);       // the owner switched the category off
        (new PushPreferenceService())->save(1, 2, ['privacy' => 'minimal']);                    // the admin wants no names on the lock screen
        $r = $this->nudge($this->ist('2026-10-12 11:10:00'));
        $this->assertSame(2, $r['team_behind']);                                    // agents 3 and 4 only
        $this->assertSame(1, $r['managers_sent']);                                  // owner suppressed
        $this->assertSame('category_off', db_connect()->table('push_log')->where('user_id', 1)->where('event', 'target_team')->get()->getRowArray()['reason']);
        $this->assertStringNotContainsString('Agent', json_encode($this->bodiesFor(2)));      // generic text for the admin
        $this->assertStringContainsString('Sales target', $this->bodiesFor(2)[0]['title']);
    }

    public function testASummaryNeverMentionsAnotherWorkspacesAgents(): void
    {
        $db = db_connect();
        $db->table('tenants')->insert(['id' => 2, 'name' => 'T2', 'slug' => 't2', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        foreach ([[7, 'owner', 'Boss2'], [8, 'agent', 'RivalAgent']] as [$id, $role, $name]) {
            $db->table('users')->insert(['id' => $id, 'tenant_id' => 2, 'name' => $name, 'email' => "x{$id}@t.test", 'password_hash' => 'x', 'role' => $role, 'created_at' => '2026-01-01 00:00:00']);
        }
        $this->device(1); $this->device(7); $this->target(3, 30_000_000, 6);
        (new SalesTargetService())->save(2, '2026-10', [['user_id' => 8, 'revenue' => 30_000_000, 'bookings' => 6]], 7);
        $db->table('sales_targets')->where('tenant_id', 2)->update(['updated_at' => '2026-09-01 00:00:00']);
        $r = $this->nudge($this->ist('2026-10-12 11:10:00'));
        $this->assertSame(2, $r['managers_sent']);
        $this->assertStringContainsString('U3', $this->bodiesFor(1)[0]['body']);
        $this->assertStringNotContainsString('RivalAgent', $this->bodiesFor(1)[0]['body']);
        $this->assertStringContainsString('RivalAgent', $this->bodiesFor(7)[0]['body']);
        $this->assertStringNotContainsString('U3', $this->bodiesFor(7)[0]['body']);
    }
}
