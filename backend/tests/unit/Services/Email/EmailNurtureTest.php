<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Database\Seeds\GamavisEmailNurtureSeeder;
use App\Services\Email\Marketing\EmailCampaignScheduler;
use App\Services\Email\Marketing\EmailCampaignSender;
use App\Services\Email\Marketing\EmailComposer;
use App\Services\Email\Marketing\EmailTracking;
use App\Services\Email\Marketing\Transport\MockTransport;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\EmailMarketingTestSchema;

/**
 * Nurture sequences: follow-up audiences, the rolling daily cap, step
 * ordering, and the Gamavis template series.
 */
class EmailNurtureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmailMarketingTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['EMAIL_MOCK_MODE'] = 'false';
        $this->createEmailMarketingSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['EMAIL_MOCK_MODE']);
        parent::tearDown();
    }

    private function sentRow(int $campaignId, int $contactId, array $extra = []): void
    {
        db_connect()->table('emails')->insert($extra + [
            'tenant_id' => 1, 'contact_id' => $contactId, 'email_campaign_id' => $campaignId,
            'to_email' => "c{$contactId}@example.com", 'subject' => 's', 'direction' => 'out',
            'status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function sendFollowup(int $id): array
    {
        $t = new MockTransport();
        (new EmailCampaignSender($t, new EmailComposer()))->processBatch($id, 1);

        return array_column($t->sent, 'to');
    }

    public function testFollowupTargetsSourceRecipientsWhoHaveNotEngaged(): void
    {
        $this->connectTenantSmtp();
        $c1 = $this->seedContact('+911', ['email' => 'c1@example.com']);
        $c2 = $this->seedContact('+912', ['email' => 'c2@example.com']);
        $c3 = $this->seedContact('+913', ['email' => 'c3@example.com', 'status' => 'won']);
        $c4 = $this->seedContact('+914', ['email' => 'c4@example.com']); // never got the source
        $c5 = $this->seedContact('+915', ['email' => 'c5@example.com']); // source send failed

        $source = $this->seedEmailCampaign(['status' => 'done']);
        $this->sentRow($source, $c1);
        $this->sentRow($source, $c2, ['clicked_at' => date('Y-m-d H:i:s')]);
        $this->sentRow($source, $c3);
        $this->sentRow($source, $c5, ['status' => 'failed']);

        $f1 = $this->seedEmailCampaign(['segment' => json_encode([
            'followup_of' => $source, 'engagement' => 'not_clicked', 'exclude_statuses' => ['won'],
        ])]);

        $this->assertSame(['c1@example.com'], $this->sendFollowup($f1));

        // Clicking follow-up 1 takes c1 out of follow-up 2: engagement spans the sequence.
        db_connect()->table('emails')->where('email_campaign_id', $f1)->update(['clicked_at' => date('Y-m-d H:i:s')]);
        $f2 = $this->seedEmailCampaign(['segment' => json_encode([
            'followup_of' => $source, 'after_campaign_id' => $f1, 'engagement' => 'not_clicked', 'exclude_statuses' => ['won'],
        ])]);
        $this->assertSame([], $this->sendFollowup($f2));
        unset($c4);
    }

    public function testFollowupEngagementAllAndUnknownFailsClosed(): void
    {
        $this->connectTenantSmtp();
        $c1 = $this->seedContact('+911', ['email' => 'c1@example.com']);
        $c2 = $this->seedContact('+912', ['email' => 'c2@example.com']);
        $source = $this->seedEmailCampaign(['status' => 'done']);
        $this->sentRow($source, $c1);
        $this->sentRow($source, $c2, ['clicked_at' => date('Y-m-d H:i:s')]);

        $all = $this->seedEmailCampaign(['segment' => json_encode(['followup_of' => $source, 'engagement' => 'all'])]);
        $this->assertCount(2, $this->sendFollowup($all));

        $bogus = $this->seedEmailCampaign(['segment' => json_encode(['followup_of' => $source, 'engagement' => 'whatever'])]);
        $this->assertSame(['c1@example.com'], $this->sendFollowup($bogus), 'unknown engagement = not_clicked');
    }

    public function testDailyLimitThrottlesAndLaterResumes(): void
    {
        $this->connectTenantSmtp(1, ['daily_limit' => 3]);
        for ($i = 1; $i <= 4; $i++) {
            $this->seedContact("+91{$i}", ['email' => "n{$i}@example.com"]);
        }
        // One email already went out today (e.g. from a flow).
        $this->sentRow(999, 999);

        $id = $this->seedEmailCampaign();
        $t  = new MockTransport();
        $s  = new EmailCampaignSender($t, new EmailComposer());

        $r1 = $s->processBatch($id, 1);
        $this->assertCount(2, $t->sent, 'only the room left under the cap');
        $this->assertSame('processing', $r1['status']);

        $r2 = $s->processBatch($id, 1);
        $this->assertTrue($r2['throttled'] ?? false);
        $this->assertCount(2, $t->sent);
        $row = db_connect()->table('email_campaigns')->where('id', $id)->get()->getRowArray();
        $this->assertStringContainsString('Daily limit', (string) $row['last_error']);

        // The window rolls: yesterday's sends no longer count.
        db_connect()->table('emails')->update(['sent_at' => date('Y-m-d H:i:s', time() - 90000)]);
        $r3 = $s->processBatch($id, 1);
        $this->assertSame('done', $r3['status']);
        $this->assertCount(4, $t->sent);
        $this->assertNull(db_connect()->table('email_campaigns')->where('id', $id)->get()->getRow()->last_error);
    }

    public function testFollowupWaitsForPredecessorToFinish(): void
    {
        $source = $this->seedEmailCampaign(['status' => 'processing']);
        $due    = gmdate('Y-m-d H:i:s', time() - 60);
        $f1     = $this->seedEmailCampaign(['status' => 'scheduled', 'scheduled_at' => $due,
            'segment' => json_encode(['followup_of' => $source, 'after_campaign_id' => $source])]);

        $s = new EmailCampaignScheduler();
        $this->assertSame(0, $s->dispatchDue(time())['dispatched']);
        $row = db_connect()->table('email_campaigns')->where('id', $f1)->get()->getRowArray();
        $this->assertSame('scheduled', $row['status']);
        $this->assertGreaterThan(time(), strtotime($row['scheduled_at'] . ' UTC'), 'pushed back');
        $this->assertStringContainsString('Waiting for', (string) $row['last_error']);

        db_connect()->table('email_campaigns')->where('id', $source)->update(['status' => 'done']);
        $this->assertSame([$f1], $s->dispatchDue(time() + 3700)['ids']);
        $this->assertNull(db_connect()->table('email_campaigns')->where('id', $f1)->get()->getRow()->last_error);
    }

    public function testFooterTextTokenIsFilledAndEscaped(): void
    {
        $out = EmailTracking::prepare('<p>{{footer_text}}</p><a href="{{unsubscribe_url}}">u</a>', str_repeat('a', 32), '', "Gamavis <Pvt> Ltd\nGurugram");
        $this->assertStringContainsString('Gamavis &lt;Pvt&gt; Ltd<br>', $out);
        $this->assertStringNotContainsString('{{footer_text}}', $out);
    }

    public function testGamavisSeriesRendersCleanly(): void
    {
        $templates = (new GamavisEmailNurtureSeeder(new \Config\Database()))->templates();
        $this->assertCount(5, $templates);

        $composer = new EmailComposer();
        foreach ($templates as $t) {
            foreach (['gamavis-logo.png', 'Manglesh Upadhyay', '+919718991797', '+919650609615',
                      'manglesh@gamavis.com', 'mangleshup@gmail.com', '{{unsubscribe_url}}', '{{footer_text}}'] as $needle) {
                $this->assertStringContainsString($needle, $t['html'], "{$t['name']} lacks {$needle}");
            }

            $mail = $composer->compose(['id' => 0, 'name' => 'Asha Verma'], $t['subject'], $t['html'], $t['preheader'], str_repeat('f', 32), 'Gamavis, Gurugram');
            $this->assertDoesNotMatchRegularExpression('/\{\{[^}]*\}\}/', $mail['html'] . $mail['subject'], "{$t['name']} left a merge tag unfilled");
            $this->assertStringContainsString('Hi Asha,', $mail['html']);
            $this->assertStringContainsString('/forms/unsubscribe/', $mail['html']);
            $this->assertSame(1, substr_count($mail['html'], 'Unsubscribe</a>'), "{$t['name']}: exactly one unsubscribe link");
            $this->assertStringContainsString('/webhooks/email/click/', $mail['html'], 'WhatsApp CTA is tracked');
            $this->assertStringContainsString('href="tel:+919718991797"', $mail['html'], 'tel: links left untracked');
        }
    }

    public function testSeederIsIdempotent(): void
    {
        $seeder = new GamavisEmailNurtureSeeder(new \Config\Database());
        ob_start();
        $seeder->run();
        $seeder->run();
        ob_end_clean();
        $this->assertSame(5, db_connect()->table('email_templates')->where('tenant_id', 1)->countAllResults());
    }

    // ── Owner copy ──────────────────────────────────────────────────────

    public function testOwnerGetsAnUntrackedCopyThatNeverCountsAsTheCustomersOpen(): void
    {
        $this->connectTenantSmtp(1, ['copy_to' => 'owner@example.com']);
        $this->seedContact('+911', ['name' => 'Asha Verma', 'email' => 'asha@example.com']);
        $id = $this->seedEmailCampaign();

        $t = new MockTransport();
        (new EmailCampaignSender($t, new EmailComposer()))->processBatch($id, 1);

        $this->assertSame(['asha@example.com', 'owner@example.com'], array_column($t->sent, 'to'));
        [$real, $copy] = $t->sent;
        $this->assertStringContainsString('/webhooks/email/open/', $real['html']);
        $this->assertSame('[Copy → Asha Verma <asha@example.com>] Hi Asha', $copy['subject']);
        $this->assertStringNotContainsString('/webhooks/email/open/', $copy['html'], 'no pixel in the copy');
        $this->assertStringNotContainsString('/webhooks/email/click/', $copy['html'], 'no tracked links in the copy');
        $this->assertStringNotContainsString('/forms/unsubscribe/', $copy['html'], "the copy cannot unsubscribe the customer");
        $this->assertSame([], $copy['headers']);
        $this->assertSame(1, db_connect()->table('emails')->countAllResults(), 'the copy is not a recipient row');
    }

    public function testCopiesCountAgainstTheDailyLimit(): void
    {
        $this->connectTenantSmtp(1, ['copy_to' => 'owner@example.com', 'daily_limit' => 5]);
        for ($i = 1; $i <= 4; $i++) {
            $this->seedContact("+91{$i}", ['email' => "n{$i}@example.com"]);
        }
        $id = $this->seedEmailCampaign();
        $t  = new MockTransport();
        $s  = new EmailCampaignSender($t, new EmailComposer());

        $s->processBatch($id, 1);
        $this->assertCount(4, $t->sent, '5 messages allowed → 2 recipients + 2 copies');
        $this->assertTrue($s->processBatch($id, 1)['throttled'] ?? false);
    }

    public function testNoCopyWhenTheSendFails(): void
    {
        $this->connectTenantSmtp(1, ['copy_to' => 'owner@example.com']);
        $this->seedContact('+911', ['email' => 'bad@example.com']);
        $this->seedContact('+912', ['email' => 'ok@example.com']);
        $id = $this->seedEmailCampaign();

        $t = new MockTransport(['bad@example.com']);
        (new EmailCampaignSender($t, new EmailComposer()))->processBatch($id, 1);
        $this->assertSame(['ok@example.com', 'owner@example.com'], array_column($t->sent, 'to'));
    }

    // ── CSV export ──────────────────────────────────────────────────────

    public function testRecipientCsvShowsWhoOpenedAndNeutralisesFormulas(): void
    {
        $c1 = $this->seedContact('+911', ['name' => '=HYPERLINK("x")', 'email' => 'a@example.com']);
        $c2 = $this->seedContact('+912', ['name' => 'Ravi', 'email' => 'b@example.com']);
        $this->sentRow(7, $c1, ['to_email' => 'a@example.com', 'opened_at' => '2026-09-26 05:10:00', 'open_count' => 3]);
        $this->sentRow(7, $c2, ['to_email' => 'b@example.com']);

        $r   = new \App\Services\Email\Marketing\EmailCampaignReport();
        $all = array_map('str_getcsv', array_filter(explode("\n", $r->recipientsCsv(1, 7))));
        $this->assertSame('Name', $all[0][0]);
        $this->assertCount(3, $all);
        $this->assertSame("'=HYPERLINK(\"x\")", $all[1][0], 'formula neutralised');
        $this->assertSame(['Yes', '2026-09-26 05:10:00', '3'], [$all[1][4], $all[1][5], $all[1][6]]);

        $opened = array_filter(explode("\n", $r->recipientsCsv(1, 7, 'opened')));
        $this->assertCount(2, $opened, 'header + the one who opened');
    }

    // ── Sequence service ────────────────────────────────────────────────

    public function testSequenceServiceValidatesBeforeCreatingAnything(): void
    {
        $tpl = db_connect()->table('email_templates');
        $tpl->insert(['tenant_id' => 1, 'name' => 'T2', 'subject' => 's', 'html_body' => '<p>x</p>']);
        $t2 = (int) db_connect()->insertID();

        $draft = db_connect()->table('email_campaigns')->where('id', $this->seedEmailCampaign(['status' => 'draft']))->get()->getRowArray();
        $svc   = new \App\Services\Email\Marketing\NurtureSequenceService();

        try {
            $svc->create(1, $draft, [['email_template_id' => $t2, 'days_after' => 3]]);
            $this->fail('draft source must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('schedule this campaign first', $e->getMessage());
        }

        $src = db_connect()->table('email_campaigns')->where('id', $this->seedEmailCampaign([
            'status' => 'scheduled', 'scheduled_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]))->get()->getRowArray();

        try {
            $svc->create(1, $src, [['email_template_id' => $t2, 'days_after' => 3], ['email_template_id' => 999, 'days_after' => 7]]);
            $this->fail('unknown template must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Follow-up 2', $e->getMessage());
        }
        $this->assertSame(0, db_connect()->table('email_campaigns')->like('segment', 'followup_of')->countAllResults(), 'all-or-nothing');

        $made = $svc->create(1, $src, [['email_template_id' => $t2, 'days_after' => 3]], 'not_clicked', ['won', 'bogus'], '10:30', 'Asia/Calcutta');
        $this->assertCount(1, $made);
        $seg = json_decode(db_connect()->table('email_campaigns')->where('id', $made[0]['id'])->get()->getRow()->segment, true);
        $this->assertSame(['won'], $seg['exclude_statuses']);
        $this->assertSame('05:00:00', substr($made[0]['scheduled_at'], 11), '10:30 IST = 05:00 UTC');
    }
}
