<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Marketing\ChatService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Website live chat (group "mysql"). */
#[Group('mysql')]
final class ChatServiceTest extends CIUnitTestCase
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
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0'); $db->table('chat_messages')->truncate(); $db->table('chat_sessions')->truncate(); $db->query('SET FOREIGN_KEY_CHECKS=1');
        cache()->delete('chat_team_seen'); $this->sent = [];
    }

    private function svc(): ChatService { return new ChatService(function (string $to, string $subject, string $html, string $text, ?string $reply): bool { $this->sent[] = compact('to', 'subject', 'html', 'reply'); return true; }); }

    public function testStartCreatesASessionWithTheFirstMessageAndAnAwayGreeting(): void
    {
        $r = $this->svc()->start(['name' => 'Asha', 'email' => 'asha@example.com', 'message' => 'Hi there', 'page' => '/pricing.html']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $r['token']);
        $p = $this->svc()->poll($r['token']);
        $this->assertSame(['visitor', 'system'], array_column($p['messages'], 'sender'));
        $this->assertStringContainsString('away', $p['messages'][1]['body']);
        $this->assertFalse($p['online']);
        ChatService::markTeamSeen();                                                          // someone opens the inbox
        $this->assertTrue($this->svc()->poll($r['token'])['online']);
    }

    public function testBotsAndBadInputAreRefused(): void
    {
        foreach ([['website' => 'x', 'message' => 'a'], ['message' => 'hi', 't' => 1_800_000_000_000]] as $in) {
            try { $this->svc()->start($in, '', '', 1_800_000_000_300); $this->fail('bot accepted'); } catch (\DomainException) { $this->addToAssertionCount(1); }
        }
        foreach ([['message' => ''], ['message' => 'hi', 'email' => 'nope'], ['message' => 'http://a.example http://b.example http://c.example']] as $in) {
            try { $this->svc()->start($in); $this->fail('invalid accepted'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(0, db_connect()->table('chat_sessions')->countAllResults());
    }

    public function testVisitorAndTeamConversationAndUnreadCounts(): void
    {
        $s = $this->svc(); $c = $s->start(['message' => 'First']); $id = $c['id'];
        $s->visitorSend($c['token'], 'Second');
        $this->assertSame(2, $s->sessions('open')[0]['unread_agent']);
        $t = $s->thread($id); $this->assertSame(['visitor', 'system', 'visitor'], array_column($t['messages'], 'sender'));
        $this->assertSame(0, $s->sessions('open')[0]['unread_agent']);                          // opening the thread marks it read
        $s->agentReply($id, 'Hello from the team', 7);
        $after = $s->poll($c['token'], $t['messages'][2]['id'])['messages'];
        $this->assertSame([['agent', 'Hello from the team']], array_map(fn ($m) => [$m['sender'], $m['body']], $after));          // incremental polling
        $s->close($id);
        try { $s->visitorSend($c['token'], 'late'); $this->fail('sent into a closed chat'); } catch (\DomainException) { $this->addToAssertionCount(1); }
        $this->assertSame(0, count($s->sessions('open'))); $this->assertSame(1, count($s->sessions('closed')));
    }

    public function testTeamEmailIsSentOncePerWindowAndEscaped(): void
    {
        $s = $this->svc(); $c = $s->start(['name' => '<b>Bob</b>', 'message' => '<script>x</script>']);
        $this->assertTrue($s->notifyTeam($c['id']));
        $this->assertStringNotContainsString('<script>', $this->sent[0]['html']); $this->assertStringContainsString('&lt;script&gt;', $this->sent[0]['html']);
        $this->assertSame('manglesh@gamavis.com', $this->sent[0]['to']);
        $s->visitorSend($c['token'], 'again'); $this->assertFalse($s->notifyTeam($c['id']));       // within 10 minutes: no second email
        $this->assertCount(1, $this->sent);
    }

    public function testAnswerIsEmailedOnlyWhenTheVisitorHasLeftAndGaveAnEmail(): void
    {
        $s = $this->svc(); $c = $s->start(['email' => 'v@example.com', 'message' => 'Hi']);
        $s->agentReply($c['id'], 'Online reply', 1);
        $this->assertCount(0, $this->sent);                                                       // widget still open: no email
        db_connect()->table('chat_sessions')->where('id', $c['id'])->update(['last_poll_at' => date('Y-m-d H:i:s', time() - 600)]);
        $s->agentReply($c['id'], 'Away reply', 1);
        $this->assertSame(['v@example.com', 'Reply from TripSarthi'], [$this->sent[0]['to'], $this->sent[0]['subject']]);
        $anon = $s->start(['message' => 'no email']); db_connect()->table('chat_sessions')->where('id', $anon['id'])->update(['last_poll_at' => null]);
        $s->agentReply($anon['id'], 'x', 1); $this->assertCount(1, $this->sent);
    }

    public function testBadTokenAndMessageLimits(): void
    {
        try { $this->svc()->poll('nothex'); $this->fail(); } catch (\OutOfBoundsException) { $this->addToAssertionCount(1); }
        $s = $this->svc(); $c = $s->start(['message' => 'a']);
        db_connect()->table('chat_messages')->insertBatch(array_map(fn ($i) => ['session_id' => $c['id'], 'sender' => 'visitor', 'body' => 'x', 'created_at' => date('Y-m-d H:i:s')], range(1, ChatService::MAX_MESSAGES)));
        try { $s->visitorSend($c['token'], 'one more'); $this->fail('unbounded chat'); } catch (\DomainException) { $this->addToAssertionCount(1); }
    }
}
