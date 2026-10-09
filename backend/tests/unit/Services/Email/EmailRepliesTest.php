<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\Email\Mailbox\ReplyMailbox;
use App\Services\Email\Marketing\EmailCampaignSender;
use App\Services\Email\Marketing\EmailComposer;
use App\Services\Email\Marketing\EmailDailyReport;
use App\Services\Email\Marketing\ReplyNotifier;
use App\Services\Email\Marketing\ReplyTracker;
use App\Services\Email\Marketing\Transport\MockTransport;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\EmailMarketingTestSchema;

/** Reply tracking, reply alerts, follow-up suppression and the daily report. */
class EmailRepliesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmailMarketingTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    private MockTransport $alerts;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['EMAIL_MOCK_MODE'] = 'false';
        $this->createEmailMarketingSchema();
        $this->alerts = new MockTransport();
    }

    protected function tearDown(): void
    {
        unset($_ENV['EMAIL_MOCK_MODE']);
        parent::tearDown();
    }

    /** @param list<array> $messages */
    private function mailbox(array $messages): ReplyMailbox
    {
        return new class ($messages) implements ReplyMailbox {
            public int $reads = 0;
            public function __construct(private array $messages) {}
            public function listSince(int $sinceTs, int $limit = 500): array
            {
                return array_map(static fn ($m) => array_intersect_key($m, array_flip(['uid', 'message_id', 'from', 'from_name', 'subject', 'date_ts'])), $this->messages);
            }
            public function read(string $uid): array
            {
                $this->reads++;
                foreach ($this->messages as $m) {
                    if ($m['uid'] === $uid) {
                        return ['text' => $m['text'], 'auto' => $m['auto'] ?? false];
                    }
                }
                return ['text' => '', 'auto' => false];
            }
        };
    }

    private function msg(string $uid, string $from, string $subject, string $text, array $extra = []): array
    {
        return $extra + ['uid' => $uid, 'message_id' => "<{$uid}@mail.test>", 'from' => $from, 'from_name' => 'Ravi Kumar',
            'subject' => $subject, 'date_ts' => time(), 'text' => $text];
    }

    private function tracker(): ReplyTracker
    {
        return new ReplyTracker(new ReplyNotifier($this->alerts));
    }

    /** A campaign sent to one contact; returns [campaignId, contactId]. */
    private function sentCampaign(string $email = 'ravi@acme.test', array $contact = []): array
    {
        $cid = $this->seedContact('+9199' . random_int(10000000, 99999999), $contact + ['name' => 'Ravi Kumar', 'email' => $email]);
        $id  = $this->seedEmailCampaign(['status' => 'done', 'started_at' => date('Y-m-d H:i:s', time() - 7200)]);
        db_connect()->table('emails')->insert([
            'tenant_id' => 1, 'contact_id' => $cid, 'email_campaign_id' => $id, 'direction' => 'out',
            'from_email' => 'news@tenant.test', 'to_email' => $email, 'subject' => 'Intro', 'status' => 'sent',
            'sent_at' => date('Y-m-d H:i:s', time() - 3600), 'created_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);

        return [$id, $cid];
    }

    public function testReplyIsRecordedAlertedAndNotDuplicated(): void
    {
        $this->connectTenantSmtp(1, ['notify_to' => 'boss@example.com']);
        [$campaign, $cid] = $this->sentCampaign();
        $box = $this->mailbox([
            $this->msg('1', 'Ravi@Acme.test', 'Re: Intro', "Yes, please call me tomorrow.\n\nOn Fri, 26 Sep 2026 at 10:30, Manglesh <news@tenant.test> wrote:\n> Run your business on a system"),
            $this->msg('2', 'stranger@spam.test', 'Win a prize', 'hello'),
        ]);

        $r = $this->tracker()->sync(1, $box, time() - 86400);

        $this->assertSame(1, $r['replies']);
        $in = db_connect()->table('emails')->where('direction', 'in')->get()->getResultArray();
        $this->assertCount(1, $in, 'unknown senders are never recorded');
        $this->assertSame($cid, (int) $in[0]['contact_id']);
        $this->assertSame('Yes, please call me tomorrow.', $in[0]['body'], 'quoted original stripped');
        $this->assertNull($in[0]['email_campaign_id']);

        $out = db_connect()->table('emails')->where('direction', 'out')->get()->getRowArray();
        $this->assertNotNull($out['replied_at']);

        $this->assertCount(1, $this->alerts->sent);
        $alert = $this->alerts->sent[0];
        $this->assertSame('boss@example.com', $alert['to']);
        $this->assertSame('ravi@acme.test', $alert['reply_to'], 'answering the alert answers the customer');
        $this->assertStringContainsString('Ravi Kumar', $alert['subject']);
        $this->assertStringContainsString('Yes, please call me tomorrow.', $alert['html']);
        $this->assertSame(0, $box->reads - 1, 'only the matching message body was fetched');

        $again = $this->tracker()->sync(1, $box, time() - 86400);
        $this->assertSame(0, $again['replies']);
        $this->assertCount(1, $this->alerts->sent, 'no second alert');
        $this->assertSame(1, db_connect()->table('emails')->where('direction', 'in')->countAllResults());
        unset($campaign);
    }

    public function testAutoReplyIsKeptButIsNotAReply(): void
    {
        $this->connectTenantSmtp(1, ['notify_to' => 'boss@example.com']);
        $this->sentCampaign();
        $box = $this->mailbox([
            $this->msg('1', 'ravi@acme.test', 'Automatic reply: Intro', 'I am out of office until Monday.'),
            $this->msg('2', 'ravi@acme.test', 'Re: Intro', 'Back soon', ['auto' => true, 'message_id' => '<x2@mail.test>']),
        ]);

        $r = $this->tracker()->sync(1, $box, time() - 86400);

        $this->assertSame(0, $r['replies']);
        $this->assertSame(2, $r['auto_replies']);
        $this->assertSame(2, db_connect()->table('emails')->where('direction', 'in')->where('is_auto_reply', 1)->countAllResults());
        $this->assertNull(db_connect()->table('emails')->where('direction', 'out')->get()->getRow()->replied_at);
        $this->assertSame([], $this->alerts->sent);
    }

    public function testDryRunChangesNothing(): void
    {
        $this->connectTenantSmtp(1, ['notify_to' => 'boss@example.com']);
        $this->sentCampaign();
        $r = $this->tracker()->sync(1, $this->mailbox([$this->msg('1', 'ravi@acme.test', 'Re: Intro', 'Interested')]), time() - 86400, true);

        $this->assertSame(1, $r['replies']);
        $this->assertSame(0, db_connect()->table('emails')->where('direction', 'in')->countAllResults());
        $this->assertSame([], $this->alerts->sent);
    }

    public function testRepliersDropOutOfFollowUpsButAutoRepliersDoNot(): void
    {
        $this->connectTenantSmtp();
        [$source] = $this->sentCampaign('ravi@acme.test');
        $cid2 = $this->seedContact('+911', ['name' => 'Asha', 'email' => 'asha@acme.test']);
        db_connect()->table('emails')->insert(['tenant_id' => 1, 'contact_id' => $cid2, 'email_campaign_id' => $source, 'direction' => 'out',
            'to_email' => 'asha@acme.test', 'subject' => 'Intro', 'status' => 'sent', 'sent_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $this->tracker()->sync(1, $this->mailbox([
            $this->msg('1', 'ravi@acme.test', 'Re: Intro', 'Call me'),
            $this->msg('2', 'asha@acme.test', 'Out of Office', 'Away'),
        ]), time() - 86400);

        $f = $this->seedEmailCampaign(['segment' => json_encode(['followup_of' => $source, 'engagement' => 'not_clicked'])]);
        $t = new MockTransport();
        (new EmailCampaignSender($t, new EmailComposer()))->processBatch($f, 1);

        $this->assertSame(['asha@acme.test'], array_column($t->sent, 'to'));
    }

    public function testQuotedTextStrippingAcrossClients(): void
    {
        $this->assertSame('Sounds good.', ReplyTracker::stripQuoted("Sounds good.\n\nOn Mon, 29 Sep 2026, 10:31 Gamavis <a@b.c>\nwrote:\n> old"));
        $this->assertSame('Send details', ReplyTracker::stripQuoted("Send details\n\n________________________________\nFrom: Gamavis <a@b.c>\nSent: Monday\nSubject: Intro"));
        $this->assertSame('ok', ReplyTracker::stripQuoted("ok\n-----Original Message-----\nFrom: x"));
        $this->assertSame('> only quoted', ReplyTracker::stripQuoted('> only quoted'), 'never returns empty');
    }

    public function testDailyReportListsOpenersClickersAndReplies(): void
    {
        $this->connectTenantSmtp(1, ['notify_to' => 'boss@example.com']);
        [$campaign, $cid] = $this->sentCampaign();
        db_connect()->table('emails')->where('contact_id', $cid)->update(['opened_at' => date('Y-m-d H:i:s', time() - 600), 'open_count' => 2]);
        $this->tracker()->sync(1, $this->mailbox([$this->msg('1', 'ravi@acme.test', 'Re: Intro', 'Please call')]), time() - 86400);

        $r = new EmailDailyReport($this->alerts);
        $d = $r->build(1, time() + 60);
        $this->assertSame(1, $d['sent']);
        $this->assertSame(1, $d['opened']);
        $this->assertSame(1, $d['replied']);
        $this->assertSame(1, $d['campaigns'][0]['replied']);

        [$ok] = $r->send(1, time() + 60);
        $this->assertTrue($ok);
        $mail = end($this->alerts->sent);
        $this->assertSame('boss@example.com', $mail['to']);
        $this->assertStringContainsString('1 opened', $mail['subject']);
        $this->assertStringContainsString('Ravi Kumar', $mail['html']);
        $this->assertStringContainsString('Please call', $mail['html']);
        unset($campaign);
    }

    public function testRunnerSendsTheDailyReportOnceAfterNineIst(): void
    {
        $_ENV['EMAIL_MOCK_MODE'] = 'true';
        $this->connectTenantSmtp(1, ['notify_to' => 'boss@example.com']);
        $runner = new \App\Services\Email\Marketing\EmailInboxRunner();
        $cfg    = static fn () => json_decode(db_connect()->table('integrations')->where('type', 'email_smtp')->get()->getRow()->config, true);

        $early = strtotime('2026-09-29 02:00:00 UTC'); // 07:30 IST
        $runner->tick($early);
        $this->assertArrayNotHasKey('report_sent_on', $cfg(), 'not before 09:00 IST');

        $nine = strtotime('2026-09-29 03:31:00 UTC');  // 09:01 IST
        $log  = $runner->tick($nine);
        $this->assertSame('2026-09-29', $cfg()['report_sent_on']);
        $this->assertStringContainsString('daily report sent', implode(' ', $log));

        $this->assertSame([], array_filter($runner->tick($nine + 3600), static fn ($l) => str_contains($l, 'daily report')), 'once a day');
        $this->assertNotEmpty(array_filter($runner->tick(strtotime('2026-09-30 04:00:00 UTC')), static fn ($l) => str_contains($l, 'daily report')), 'next day again');
    }
}
