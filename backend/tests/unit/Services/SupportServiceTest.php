<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Support\SupportService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Customer -> TripSarthi support tickets: isolation between workspaces, internal notes, status flow, limits, e-mails (group "mysql"). */
#[Group('mysql')]
final class SupportServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['support_messages', 'support_tickets', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        foreach ([1, 2] as $id) { $db->table('tenants')->insert(['id' => $id, 'name' => "Agency $id", 'slug' => "a$id", 'plan' => 'growth', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        // users: 1 owner of agency 1, 2 agent of agency 1, 3 owner of agency 2, 9 = TripSarthi team member
        foreach ([[1, 1, 'owner'], [2, 1, 'agent'], [3, 2, 'owner']] as [$id, $t, $role]) { $db->table('users')->insert(['id' => $id, 'tenant_id' => $t, 'name' => "User $id", 'email' => "u{$id}@example.com", 'password_hash' => 'x', 'role' => $role, 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('users')->insert(['id' => 9, 'tenant_id' => 1, 'name' => 'Team Member', 'email' => 'team@example.com', 'password_hash' => 'x', 'role' => 'owner', 'is_platform_admin' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $this->mails = [];
    }

    protected function tearDown(): void { db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function svc(?int $now = null): SupportService
    {
        return new SupportService(function (string $to, string $subject, string $html, string $text) { $this->mails[] = compact('to', 'subject', 'html', 'text'); return true; }, $now);
    }

    private function open(SupportService $s, int $tenant = 1, int $user = 1, string $subject = 'Cannot connect WhatsApp'): int
    {
        return $s->create($tenant, $user, ['subject' => $subject, 'message' => 'The connect button does nothing for me.', 'category' => 'whatsapp'])['id'];
    }

    public function testInboxNumbersShowHowLongCustomersHaveWaitedAndHowFastWeAnswer(): void
    {
        $now = strtotime('2026-10-12 12:00:00 UTC');
        $a = $this->open($this->svc($now - 30 * 3600), 1, 1, 'Old waiting request');
        $b = $this->open($this->svc($now - 2 * 3600), 2, 3, 'Fresh waiting request');
        $c = $this->open($this->svc($now - 10 * 3600), 1, 1, 'Answered request');
        $this->svc($now - 10 * 3600 + 90 * 60)->adminReply($c, 9, 'On it, looking at it now.', 'pending');
        $n = $this->svc($now)->counts();
        $this->assertSame([2, 1, 1, 30, 90, 24], [$n['unanswered'], $n['past_target'], $n['pending'], $n['oldest_waiting_hours'], $n['avg_first_response_minutes'], $n['target_hours']]);

        $rows = array_column($this->svc($now)->adminList('active')['rows'], null, 'id');
        $this->assertSame([30, true], [$rows[$a]['waiting_hours'], $rows[$a]['past_target']]);
        $this->assertSame([2, false], [$rows[$b]['waiting_hours'], $rows[$b]['past_target']]);
        $this->assertSame([null, false], [$rows[$c]['waiting_hours'], $rows[$c]['past_target']], 'a request we answered is not waiting on us');
    }

    public function testTicketDetailCarriesTheCustomerCardPreviousRequestsAndFirstResponseTime(): void
    {
        db_connect()->table('subscriptions')->truncate();   // other test classes leave paid periods behind: this one needs a workspace with none
        $now = strtotime('2026-10-12 12:00:00 UTC');
        $a = $this->open($this->svc($now - 30 * 3600), 1, 1, 'Old waiting request');
        $c = $this->open($this->svc($now - 10 * 3600), 1, 1, 'Answered request');
        $this->svc($now - 10 * 3600 + 90 * 60)->adminReply($c, 9, 'On it, looking at it now.', 'pending');
        $d = $this->svc($now)->adminDetail($c);
        $this->assertSame(90, $d['first_response_minutes']);
        $this->assertSame([$a], array_column($d['previous'], 'id'), 'the other requests from this workspace');
        $this->assertSame('free', $d['customer']['billing']['state']); $this->assertArrayHasKey('flags', $d['customer']); $this->assertSame('u1@example.com', $d['customer']['owner']['email']);
        $this->assertNull($this->svc($now)->adminDetail($a)['first_response_minutes'], 'nobody has answered yet');
    }

    public function testCreateAndTeamAndCustomerThread(): void
    {
        $s = $this->svc(); $id = $this->open($s);
        $s->deliverPending();
        $this->assertCount(1, $this->mails, 'the team is told about a new request');
        $this->assertStringContainsString('TS-00001', $this->mails[0]['subject']);

        $s->adminNote($id, 9, 'Looks like the Meta token expired - check logs');
        $s->adminReply($id, 9, 'Please reconnect from Settings -> WhatsApp and tell us if it still fails.');
        $s->deliverPending();
        $this->assertSame('u1@example.com', $this->mails[1]['to'], 'the customer is e-mailed the reply');

        $d = $s->detailFor(1, 1, 'owner', $id);
        $this->assertSame('pending', $d['status']);
        $this->assertSame(['customer', 'team'], array_column($d['messages'], 'from'));
        $this->assertStringNotContainsString('Meta token', json_encode($d), 'internal notes never reach the customer');
        $this->assertSame(3, count($s->adminDetail($id)['messages']), 'the team sees the note');
    }

    public function testWorkspacesCannotSeeEachOthersTickets(): void
    {
        $s = $this->svc(); $id = $this->open($s, 1, 1);
        $this->assertSame([], $s->listFor(2, 3, 'owner'));
        $this->expectException(\OutOfBoundsException::class);
        $s->detailFor(2, 3, 'owner', $id);
    }

    public function testAgentsSeeOnlyTheirOwnButOwnersSeeTheWorkspace(): void
    {
        $s = $this->svc(); $mine = $this->open($s, 1, 2, 'Agent request one'); $this->open($s, 1, 1, 'Owner request one');
        $this->assertCount(1, $s->listFor(1, 2, 'agent'));
        $this->assertCount(2, $s->listFor(1, 1, 'owner'));
        $this->expectException(\OutOfBoundsException::class);   // an agent cannot open the owner's ticket
        $s->detailFor(1, 2, 'agent', $mine + 1);
    }

    public function testCustomerReplyReopensAndClosedTicketsTakeNoReplies(): void
    {
        $s = $this->svc(); $id = $this->open($s);
        $s->adminReply($id, 9, 'Fixed on our side, please try again.', 'resolved');
        $this->assertSame('resolved', $s->detailFor(1, 1, 'owner', $id)['status']);
        $this->assertSame('open', $s->reply(1, 1, 'owner', $id, 'Still broken for me')['status'], 'a reply reopens it');

        $s->close(1, 1, 'owner', $id);
        $this->assertSame('closed', $s->detailFor(1, 1, 'owner', $id)['status']);
        $this->expectException(\DomainException::class);
        $s->reply(1, 1, 'owner', $id, 'Hello again');
    }

    public function testInputIsCleanedAndLimited(): void
    {
        $s = $this->svc();
        $d = $s->create(1, 1, ['subject' => '  <b>Billing</b>   question  ', 'message' => "<script>alert(1)</script>Please help with my invoice.\r\nThanks", 'category' => 'nonsense']);
        $this->assertSame('Billing question', $d['subject']);
        $this->assertSame('other', $d['category']);
        $this->assertStringNotContainsString('<script', json_encode($d['messages']));
        foreach ([['subject' => 'Hi', 'message' => 'long enough message here'], ['subject' => 'A good title', 'message' => 'short']] as $bad) {
            try { $s->create(1, 1, $bad); $this->fail('should refuse'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function testDoubleClickIsOneTicketAndFloodsAreStopped(): void
    {
        $s = $this->svc(1_800_000_000);
        $a = $this->open($s); $b = $this->open($s);
        $this->assertSame($a, $b, 'the same request sent twice is one ticket');
        $this->open($s, 1, 1, 'Second different one'); $this->open($s, 1, 1, 'Third different one');
        $this->expectException(\DomainException::class);   // a 4th within 10 minutes
        $this->open($s, 1, 1, 'Fourth different one');
    }

    public function testTeamCanAssignOnlyToTeamMembersAndSetPriorityAndStatus(): void
    {
        $s = $this->svc(); $id = $this->open($s);
        $d = $s->adminUpdate($id, 9, ['priority' => 'urgent', 'assigned_to' => 9, 'status' => 'resolved']);
        $this->assertSame('urgent', $d['priority']); $this->assertSame(9, $d['assigned_to']); $this->assertSame('resolved', $d['status']);
        $this->assertContains('event', array_column($d['messages'], 'from'), 'a status change is recorded in the thread');
        try { $s->adminUpdate($id, 9, ['assigned_to' => 3]); $this->fail('a customer cannot be assigned'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame(1, $s->counts()['total']);
    }

    public function testTeamEmailForCustomerRepliesIsThrottledPerTicket(): void
    {
        $s = $this->svc(1_800_000_000); $id = $this->open($s); $s->deliverPending();
        $s->reply(1, 1, 'owner', $id, 'one more detail'); $s->deliverPending();
        $this->assertCount(1, $this->mails, 'a quick follow-up does not send the team another e-mail');
    }
}
