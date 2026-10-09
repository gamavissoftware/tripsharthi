<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\Email\Marketing\EmailCampaignReport;
use App\Services\Email\Marketing\EmailCampaignScheduler;
use App\Services\Email\Marketing\EmailPersonalizer;
use App\Services\Email\Marketing\EmailTracking;
use App\Services\Email\Marketing\EmailTrackingService;
use App\Services\Email\Marketing\SuppressionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\EmailMarketingTestSchema;

class EmailMarketingUnitsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmailMarketingTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEmailMarketingSchema();
    }

    // ── Personalizer ───────────────────────────────────────────────────

    public function testMergeTagsFallbacksAndEscaping(): void
    {
        $p = new EmailPersonalizer();
        $c = ['name' => 'Asha <b>Verma</b>', 'city' => '', 'email' => 'a@x.com'];

        $this->assertSame('Hi Asha', $p->render('Hi {{contact.first_name}}', ['name' => 'Asha Verma'], [], false));
        $this->assertSame('Hi there', $p->render('Hi {{ contact.first_name | there }}', ['name' => ''], [], false));
        $this->assertSame('From Delhi', $p->render('From {{contact.city|Delhi}}', $c, [], true));
        $this->assertSame('Asha &lt;b&gt;Verma&lt;/b&gt;', $p->render('{{contact.name}}', $c, [], true), 'HTML-escaped in bodies');
        $this->assertSame('Plan: Gold', $p->render('Plan: {{custom.plan}}', $c, ['plan' => 'Gold'], false));
        $this->assertSame('x', $p->render('{{contact.password_hash}}x', ['password_hash' => 'secret'], [], false), 'non-whitelisted field prints nothing');
        $this->assertSame(['contact.first_name', 'custom.plan'], $p->tokens('{{contact.first_name}} {{custom.plan}}'));
    }

    // ── Tracking markup ────────────────────────────────────────────────

    public function testPrepareRewritesLinksButNotMailtoOrUnsubscribe(): void
    {
        $token = str_repeat('a', 32);
        $html  = '<html><body><a href="https://x.com/p?a=1&amp;b=2">x</a> <a href="mailto:m@x.com">m</a>'
               . ' <a href="{{unsubscribe_url}}">Leave</a></body></html>';

        $out = EmailTracking::prepare($html, $token, 'Sneak peek');

        $this->assertStringContainsString(htmlspecialchars(EmailTracking::clickUrl($token, 'https://x.com/p?a=1&b=2')), $out);
        $this->assertStringContainsString('href="mailto:m@x.com"', $out);
        $this->assertStringContainsString('href="' . EmailTracking::unsubscribeUrl($token) . '"', $out);
        $this->assertSame(1, substr_count($out, '/forms/unsubscribe/'), 'author placed the link, so no second footer');
        $this->assertMatchesRegularExpression('/<body><div style="display:none[^>]*>Sneak peek<\/div>/', $out);
        $this->assertLessThan(strpos($out, '</body>'), strpos($out, '/webhooks/email/open/'));
    }

    public function testUntrackedPrepareLeavesLinksAlone(): void
    {
        $out = EmailTracking::prepare('<p><a href="https://x.com">x</a></p>', null);
        $this->assertStringContainsString('href="https://x.com"', $out);
        $this->assertStringNotContainsString('/webhooks/email/open/', $out);
        $this->assertStringContainsString('Unsubscribe', $out);
    }

    public function testClickSignatureRejectsTamperedUrl(): void
    {
        $token = str_repeat('b', 32);
        $sig   = EmailTracking::sign($token, 'https://good.com');
        $this->assertTrue(EmailTracking::verify($token, 'https://good.com', $sig));
        $this->assertFalse(EmailTracking::verify($token, 'https://evil.com', $sig));
        $this->assertFalse(EmailTracking::verify($token, 'https://good.com', ''));
    }

    // ── Tracking service ───────────────────────────────────────────────

    private function seedSentEmail(string $token, int $contactId, ?int $campaignId = 1): int
    {
        db_connect()->table('emails')->insert([
            'tenant_id' => 1, 'contact_id' => $contactId, 'email_campaign_id' => $campaignId,
            'tracking_token' => $token, 'to_email' => 'r@example.com', 'subject' => 'S',
            'status' => 'sent', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    public function testOpenClickAndUnsubscribeAreRecorded(): void
    {
        $cid   = $this->seedContact('+911', ['email' => 'r@example.com']);
        $token = str_repeat('c', 32);
        $id    = $this->seedSentEmail($token, $cid);
        $svc   = new EmailTrackingService();

        $this->assertTrue($svc->recordOpen($token));
        $this->assertTrue($svc->recordOpen($token));
        $this->assertFalse($svc->recordOpen('not-a-token'));

        $url = 'https://example.com/buy';
        $this->assertNull($svc->recordClick($token, 'https://evil.com', EmailTracking::sign($token, $url)), 'no open redirect');
        $this->assertSame($url, $svc->recordClick($token, $url, EmailTracking::sign($token, $url)));

        $row = db_connect()->table('emails')->where('id', $id)->get()->getRowArray();
        $this->assertSame(3, (int) $row['open_count'], 'click implies open');
        $this->assertSame(1, (int) $row['click_count']);
        $this->assertNotNull($row['opened_at']);
        $this->assertSame(1, db_connect()->table('email_clicks')->countAllResults());

        $res = $svc->unsubscribe($token);
        $this->assertTrue($res['ok']);
        $this->assertFalse($res['already']);
        $this->assertTrue((new SuppressionService())->isSuppressed(1, 'R@Example.com'));
        $this->assertTrue($svc->unsubscribe($token)['already'], 'idempotent');
        $this->assertSame(1, db_connect()->table('email_suppressions')->countAllResults());
    }

    public function testSuppressionIsPerTenant(): void
    {
        $svc = new SuppressionService();
        $this->assertTrue($svc->suppress(1, 'x@example.com'));
        $this->assertFalse($svc->suppress(1, 'nope'));
        $this->assertTrue($svc->isSuppressed(1, 'x@example.com'));
        $this->assertFalse($svc->isSuppressed(2, 'x@example.com'));
    }

    // ── Report ─────────────────────────────────────────────────────────

    public function testFunnelAndRecipientFilters(): void
    {
        $c1 = $this->seedContact('+911', ['email' => 'a@example.com']);
        $c2 = $this->seedContact('+912', ['email' => 'b@example.com']);
        $c3 = $this->seedContact('+913', ['email' => 'c@example.com']);
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $rows = [
            ['tenant_id' => 1, 'contact_id' => $c1, 'email_campaign_id' => 7, 'to_email' => 'a@example.com', 'subject' => 's', 'status' => 'sent', 'opened_at' => $now, 'clicked_at' => $now, 'created_at' => $now],
            ['tenant_id' => 1, 'contact_id' => $c2, 'email_campaign_id' => 7, 'to_email' => 'b@example.com', 'subject' => 's', 'status' => 'sent', 'created_at' => $now],
            ['tenant_id' => 1, 'contact_id' => $c3, 'email_campaign_id' => 7, 'to_email' => 'c@example.com', 'subject' => 's', 'status' => 'failed', 'created_at' => $now],
            ['tenant_id' => 2, 'contact_id' => 999, 'email_campaign_id' => 7, 'to_email' => 'z@example.com', 'subject' => 's', 'status' => 'sent', 'created_at' => $now],
        ];
        foreach ($rows as $row) {
            $db->table('emails')->insert($row);
        }

        $r = new EmailCampaignReport();
        $f = $r->funnel(1, [7]);
        $this->assertSame(3, $f['recipients']);
        $this->assertSame(2, $f['sent']);
        $this->assertSame(1, $f['failed']);
        $this->assertSame(50.0, $f['open_rate']);
        $this->assertSame(50.0, $f['click_rate']);

        $this->assertSame(1, $r->recipients(1, 7, 'not_opened')['total']);
        $this->assertSame('b@example.com', $r->recipients(1, 7, 'not_opened')['rows'][0]['to_email']);
        $this->assertSame(1, $r->recipients(1, 7, 'failed')['total']);
        $this->assertSame(3, $r->recipients(1, 7, 'bogus')['total'], 'unknown filter = all');
    }

    // ── Scheduler ──────────────────────────────────────────────────────

    public function testSchedulerDispatchesDueCampaignsOnce(): void
    {
        $due    = $this->seedEmailCampaign(['status' => 'scheduled', 'scheduled_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $future = $this->seedEmailCampaign(['status' => 'scheduled', 'scheduled_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);

        $s = new EmailCampaignScheduler();
        $this->assertSame([$due], $s->dispatchDue(time())['ids']);
        $this->assertSame(0, $s->dispatchDue(time())['dispatched'], 'second pass is a no-op');

        $db = db_connect();
        $this->assertSame('processing', $db->table('email_campaigns')->where('id', $due)->get()->getRow()->status);
        $this->assertSame('scheduled', $db->table('email_campaigns')->where('id', $future)->get()->getRow()->status);
        $job = $db->table('jobs')->where('type', 'email_campaign_send')->get()->getRowArray();
        $this->assertSame(['email_campaign_id' => $due], json_decode($job['payload'], true));
    }
}
