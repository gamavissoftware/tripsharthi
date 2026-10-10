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
        db_connect()->table('mobile_devices')->insert(['tenant_id' => 1, 'user_id' => $user, 'expo_token' => 'ExponentPushToken[' . str_pad((string) $user, 22, 'x') . ']', 'platform' => 'android', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
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
}
