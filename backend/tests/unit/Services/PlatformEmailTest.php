<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\PlatformEmailService;
use App\Services\Marketing\EmailBody;
use App\Services\Marketing\EmailUnsubscribe;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** TripSarthi's own e-mail marketing: consent-only audiences, queued sending, unsubscribe (group "mysql"). */
#[Group('mysql')]
final class PlatformEmailTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private array $mails = [];
    private bool $mailOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['platform_email_sends', 'platform_email_campaigns', 'subscriptions', 'partners', 'audit_logs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $tenants = [[1, 'Alpha Travels', 'free', 'active', '2026-01-01 00:00:00'], [2, 'Beta Holidays', 'growth', 'active', '2026-01-01 00:00:00'], [3, 'Gamma Tours', 'free', 'suspended', '2026-01-01 00:00:00'], [4, 'Delta Trips', 'free', 'active', gmdate('Y-m-d H:i:s')]];
        foreach ($tenants as [$id, $n, $plan, $st, $created]) { $db->table('tenants')->insert(['id' => $id, 'name' => $n, 'slug' => "w$id", 'plan' => $plan, 'status' => $st, 'mode' => 'saas', 'created_at' => $created]); }
        // owners: 1 yes, 2 yes, 3 yes but suspended workspace, 4 yes but brand-new; owner 5 = agency 1's second user (agent) yes; 6 = owner of 2 who did NOT agree; 7 = TripSarthi staff who agreed
        $users = [[1, 1, 'owner', 1, 0], [2, 2, 'owner', 1, 0], [3, 3, 'owner', 1, 0], [4, 4, 'owner', 1, 0], [5, 1, 'agent', 1, 0], [6, 2, 'owner', 0, 0], [7, 1, 'owner', 1, 1]];
        foreach ($users as [$id, $t, $role, $opt, $staff]) {
            $db->table('users')->insert(['id' => $id, 'tenant_id' => $t, 'name' => "Owner Name $id", 'email' => "o{$id}@example.com", 'password_hash' => 'x', 'role' => $role, 'email_marketing_opt_in' => $opt, 'is_platform_admin' => $staff, 'created_at' => '2026-01-01 00:00:00']);
        }
        $db->table('users')->where('id', 7)->update(['email' => 'team@example.com']);
        $db->table('partners')->insert(['name' => 'Pat Partner', 'email' => 'pat@example.com', 'code' => 'PAT', 'status' => 'active', 'email_marketing_opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $this->mails = []; $this->mailOk = true;
    }

    protected function tearDown(): void { db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function svc(): PlatformEmailService
    {
        return new PlatformEmailService(function (string $to, string $subject, string $html, string $text, array $headers) { $this->mails[] = compact('to', 'subject', 'html', 'text', 'headers'); return $this->mailOk; });
    }

    private function draft(PlatformEmailService $s, string $segment = 'owners'): int
    {
        return $s->save(['name' => 'Diwali offer', 'subject' => 'Hi {{first_name}}, a Diwali offer', 'body' => "Hello {{first_name}},\n\nGet 20% off with code DIWALI. See https://tripsarthi.com/pricing\n\nTeam TripSarthi", 'segment' => $segment], null, 7)['id'];
    }

    public function testOnlyConsentingActiveOwnersAreInTheAudience(): void
    {
        $emails = array_column($this->svc()->members('owners'), 'email');
        sort($emails);
        $this->assertSame(['o1@example.com', 'o2@example.com', 'o4@example.com'], $emails, 'not: suspended workspace, non-consenting owner, agents, staff');
        $this->assertSame(['o1@example.com'], array_column($this->svc()->members('owners_free'), 'email'), 'free for 7+ days only (Delta is brand new)');
        $this->assertSame(['pat@example.com'], array_column($this->svc()->members('partners'), 'email'));
    }

    public function testSendNeedsAConfirmedCountThenSendsOnceWithUnsubscribeLinks(): void
    {
        $s = $this->svc(); $id = $this->draft($s);
        try { $s->send($id, 7, 99); $this->fail('wrong count must be refused'); } catch (\DomainException) { $this->addToAssertionCount(1); }
        $c = $s->send($id, 7, 3);
        $this->assertSame('sending', $c['status']); $this->assertSame(3, $c['recipients']);
        $this->assertSame(['sent' => 3, 'failed' => 0, 'skipped' => 0, 'finished' => 1], $s->process());
        $this->assertSame('sent', $s->campaign($id)['status']);
        $this->assertCount(3, $this->mails);
        $m = $this->mails[0];
        $this->assertStringStartsWith('Hi Owner,', $m['subject'], 'subject personalised');
        $this->assertStringContainsString('/api/v1/public/email/unsubscribe?e=', $m['text']);
        $this->assertStringStartsWith('<http', $m['headers']['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $m['headers']['List-Unsubscribe-Post']);
        $this->assertSame([], array_diff([$m['to']], ['o1@example.com', 'o2@example.com', 'o4@example.com']));

        try { $s->send($id, 7, 3); $this->fail('a sent campaign cannot be sent again'); } catch (\DomainException) { $this->addToAssertionCount(1); }
        $this->assertSame(0, array_sum($s->process()), 'nothing is sent twice');
    }

    public function testRateLimitSpreadsTheSendAcrossRuns(): void
    {
        $s = $this->svc(); $id = $this->draft($s); $s->send($id, 7, 3);
        $this->assertSame(2, $s->process(2)['sent']); $this->assertSame('sending', $s->campaign($id)['status']);
        $r = $s->process(2);
        $this->assertSame(1, $r['sent']); $this->assertSame(1, $r['finished']);
    }

    public function testConsentWithdrawnAfterSendIsHonouredAndFailuresAreRecorded(): void
    {
        $s = $this->svc(); $id = $this->draft($s); $s->send($id, 7, 3);
        db_connect()->table('users')->where('id', 2)->update(['email_marketing_opt_in' => 0]);   // changed their mind after the snapshot
        $this->mailOk = false;
        $r = $s->process();
        $this->assertSame(1, $r['skipped']); $this->assertSame(2, $r['failed']);
        $c = $s->campaign($id);
        $this->assertSame('sent', $c['status']); $this->assertSame(2, $c['failed']); $this->assertCount(2, $c['failures']);
    }

    public function testCancelStopsTheRest(): void
    {
        $s = $this->svc(); $id = $this->draft($s); $s->send($id, 7, 3); $s->process(1);
        $c = $s->cancel($id, 7);
        $this->assertSame('cancelled', $c['status']); $this->assertSame(1, $c['sent']); $this->assertSame(2, $c['skipped']);
        $this->assertSame(0, array_sum($s->process()));
    }

    public function testDraftsAreEditableOnlyWhileDraftAndValidated(): void
    {
        $s = $this->svc(); $id = $this->draft($s);
        $s->save(['name' => 'Renamed', 'subject' => 'New subject', 'body' => str_repeat('Hello there. ', 5), 'segment' => 'partners'], $id, 7);
        $this->assertSame('partners', $s->campaign($id)['segment']);
        foreach ([['name' => '', 'subject' => 'abc', 'body' => str_repeat('x', 30), 'segment' => 'owners'], ['name' => 'n', 'subject' => 'ab', 'body' => str_repeat('x', 30), 'segment' => 'owners'],
                  ['name' => 'n', 'subject' => 'abc', 'body' => 'short', 'segment' => 'owners'], ['name' => 'n', 'subject' => 'abc', 'body' => str_repeat('x', 30), 'segment' => 'everyone']] as $bad) {
            try { $s->save($bad, null, 7); $this->fail('should refuse'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $s->send($id, 7, 1);
        $this->expectException(\DomainException::class);
        $s->save(['name' => 'x', 'subject' => 'abc', 'body' => str_repeat('x', 30), 'segment' => 'owners'], $id, 7);
    }

    public function testTestSendGoesToTheAdminOnly(): void
    {
        $s = $this->svc(); $id = $this->draft($s);
        $this->assertSame('team@example.com', $s->testSend($id, 7)['sent_to']);
        $this->assertCount(1, $this->mails); $this->assertStringStartsWith('[TEST] ', $this->mails[0]['subject']);
        $this->assertSame('draft', $s->campaign($id)['status']);
    }

    public function testBodyIsEscapedPersonalisedAndLinked(): void
    {
        $r = EmailBody::render("Hi {{first_name}}\n\n<script>alert(1)</script> visit https://tripsarthi.com/x?a=1&b=2", 'Priya', 'https://app.example/unsub?e=x&t=y');
        $this->assertStringNotContainsString('<script>', $r['html']);
        $this->assertStringContainsString('Hi Priya', $r['html']);
        $this->assertStringContainsString('href="https://tripsarthi.com/x?a=1&amp;b=2"', $r['html']);
        $this->assertStringContainsString('Unsubscribe', $r['html']);
        $this->assertSame('there', EmailBody::firstName(''));
        $this->assertSame('Priya', EmailBody::firstName('Priya Sharma'));
    }

    // --- unsubscribe -------------------------------------------------------------------------------------------------------------------

    public function testUnsubscribeLinksCannotBeForgedAndSwitchConsentOff(): void
    {
        $this->assertTrue(EmailUnsubscribe::verify('o1@example.com', EmailUnsubscribe::token('O1@Example.com')));
        $this->assertFalse(EmailUnsubscribe::verify('o2@example.com', EmailUnsubscribe::token('o1@example.com')));
        $this->assertSame('o1@example.com', EmailUnsubscribe::decode(explode('&', explode('e=', EmailUnsubscribe::url('o1@example.com'))[1])[0]));
        EmailUnsubscribe::apply('o1@example.com'); EmailUnsubscribe::apply('pat@example.com');
        $this->assertSame(0, (int) db_connect()->table('users')->where('id', 1)->get()->getRowArray()['email_marketing_opt_in']);
        $this->assertSame(0, (int) db_connect()->table('partners')->get()->getRowArray()['email_marketing_opt_in']);
        $this->assertSame(1, (int) db_connect()->table('users')->where('id', 2)->get()->getRowArray()['email_marketing_opt_in'], 'other people are untouched');
    }

    private function link(string $email): string
    {
        $u = EmailUnsubscribe::url($email);
        return substr($u, strpos($u, '/api/'));
    }

    public function testThePublicPageNeedsAClickToUnsubscribeAndRejectsForgedLinks(): void
    {
        $path = $this->link('o2@example.com');
        $this->get($path)->assertStatus(200);                                         // scanners opening the link change nothing
        $this->assertSame(1, (int) db_connect()->table('users')->where('id', 2)->get()->getRowArray()['email_marketing_opt_in']);
        $this->post($path)->assertStatus(200);                                        // the button / one-click request does
        $this->assertSame(0, (int) db_connect()->table('users')->where('id', 2)->get()->getRowArray()['email_marketing_opt_in']);

        $forged = preg_replace('/t=[0-9a-f]+/', 't=' . str_repeat('0', 64), $this->link('o1@example.com'));
        $this->post($forged)->assertStatus(400);
        $this->assertSame(1, (int) db_connect()->table('users')->where('id', 1)->get()->getRowArray()['email_marketing_opt_in']);
    }
}
