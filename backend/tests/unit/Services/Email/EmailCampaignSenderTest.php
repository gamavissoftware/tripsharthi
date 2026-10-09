<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\Email\Marketing\EmailCampaignSender;
use App\Services\Email\Marketing\EmailComposer;
use App\Services\Email\Marketing\SuppressionService;
use App\Services\Email\Marketing\Transport\MockTransport;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\EmailMarketingTestSchema;

class EmailCampaignSenderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmailMarketingTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Pinned, not unset: a developer .env with EMAIL_MOCK_MODE=true would
        // otherwise make every tenant "ready" and hide the SMTP guard.
        $_ENV['EMAIL_MOCK_MODE'] = 'false';
        $this->createEmailMarketingSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['EMAIL_MOCK_MODE']);
        parent::tearDown();
    }

    private function sender(MockTransport $t, int $batch = 50): EmailCampaignSender
    {
        return new EmailCampaignSender($t, new EmailComposer(), batchSizeOverride: $batch);
    }

    private function campaign(int $id): array
    {
        return db_connect()->table('email_campaigns')->where('id', $id)->get()->getRowArray();
    }

    public function testSendsPersonalisedTrackedEmailToEveryContactWithAnAddress(): void
    {
        $this->connectTenantSmtp();
        $a = $this->seedContact('+911', ['name' => 'Asha Verma', 'email' => 'Asha@Example.com']);
        $this->seedContact('+912', ['name' => 'No Email']);
        $this->seedContact('+913', ['name' => 'Ravi', 'email' => 'ravi@example.com']);
        $id = $this->seedEmailCampaign();

        $t = new MockTransport();
        $r = $this->sender($t)->processBatch($id, 1);

        $this->assertSame('done', $r['status']);
        $this->assertSame(2, $r['sent']);
        $this->assertCount(2, $t->sent);

        $first = $t->sent[0];
        $this->assertSame('asha@example.com', $first['to'], 'address normalised');
        $this->assertSame('Hi Asha', $first['subject']);
        $this->assertStringContainsString('Hello Asha Verma', $first['html']);
        $this->assertStringContainsString('/webhooks/email/click/', $first['html'], 'links are tracked');
        $this->assertStringContainsString('/webhooks/email/open/', $first['html'], 'open pixel added');
        $this->assertStringContainsString('/forms/unsubscribe/', $first['html'], 'unsubscribe footer added');
        $this->assertArrayHasKey('List-Unsubscribe', $first['headers']);
        $this->assertSame('List-Unsubscribe=One-Click', $first['headers']['List-Unsubscribe-Post']);

        $row = db_connect()->table('emails')->where('contact_id', $a)->get()->getRowArray();
        $this->assertSame('sent', $row['status']);
        $this->assertSame($id, (int) $row['email_campaign_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $row['tracking_token']);

        $c = $this->campaign($id);
        $this->assertSame(2, (int) $c['total_contacts']);
        $this->assertSame(2, (int) $c['sent_count']);
        $this->assertNotNull($c['completed_at']);
    }

    public function testRefusesToSendWithoutTenantSmtpAndPauses(): void
    {
        $this->seedContact('+911', ['email' => 'a@example.com']);
        $id = $this->seedEmailCampaign();

        $t = new MockTransport();
        $r = $this->sender($t)->processBatch($id, 1);

        $this->assertSame('paused', $r['status']);
        $this->assertSame([], $t->sent, 'platform SMTP is never used for bulk mail');
        $this->assertStringContainsString('SMTP', (string) $this->campaign($id)['last_error']);
    }

    public function testSkipsSuppressedAndSharedAddresses(): void
    {
        $this->connectTenantSmtp();
        $this->seedContact('+911', ['email' => 'same@example.com']);
        $this->seedContact('+912', ['email' => 'SAME@example.com']);
        $this->seedContact('+913', ['email' => 'gone@example.com']);
        $this->seedContact('+914', ['email' => 'not-an-email']);
        (new SuppressionService())->suppress(1, 'Gone@Example.com', 'unsubscribed');
        $id = $this->seedEmailCampaign();

        $t = new MockTransport();
        $this->sender($t)->processBatch($id, 1);

        $this->assertSame(['same@example.com'], array_column($t->sent, 'to'));
        $stats = json_decode($this->campaign($id)['stats'], true);
        $this->assertSame(1, $stats['skipped_suppressed']);
        $this->assertSame(1, $stats['skipped_duplicate']);
        $this->assertSame(1, $stats['skipped_no_email']);
    }

    public function testBatchesWithCursorAndNeverDoubleSendsOnRetry(): void
    {
        $this->connectTenantSmtp();
        for ($i = 1; $i <= 5; $i++) {
            $this->seedContact("+91{$i}", ['email' => "c{$i}@example.com"]);
        }
        $id = $this->seedEmailCampaign();
        $t  = new MockTransport();

        $r1 = $this->sender($t, 2)->processBatch($id, 1);
        $this->assertSame('processing', $r1['status']);
        $this->assertCount(2, $t->sent);

        // A crashed worker retries from a stale cursor: reservations stop a re-send.
        db_connect()->table('email_campaigns')->where('id', $id)->update(['cursor' => 0]);
        $this->sender($t, 2)->processBatch($id, 1);
        $this->assertCount(2, $t->sent, 'already-reserved contacts are not emailed again');

        $this->sender($t, 2)->processBatch($id, 1);
        $r = $this->sender($t, 2)->processBatch($id, 1);
        $this->assertSame('done', $r['status']);
        $this->assertCount(5, $t->sent);
        $this->assertCount(5, array_unique(array_column($t->sent, 'to')));
    }

    public function testSmtpRefusingEverythingPausesAndReleasesReservations(): void
    {
        $this->connectTenantSmtp();
        for ($i = 1; $i <= 6; $i++) {
            $this->seedContact("+91{$i}", ['email' => "c{$i}@example.com"]);
        }
        $id = $this->seedEmailCampaign();

        $r = $this->sender(new MockTransport(['*'], '535 Authentication failed'))->processBatch($id, 1);

        $this->assertSame('paused', $r['status']);
        $c = $this->campaign($id);
        $this->assertSame(0, (int) $c['cursor'], 'cursor held so a resume retries the same people');
        $this->assertStringContainsString('535', (string) $c['last_error']);
        $this->assertSame(0, db_connect()->table('emails')->countAllResults(), 'reservations released');

        // Fixed credentials + resume: everyone gets exactly one email.
        db_connect()->table('email_campaigns')->where('id', $id)->update(['status' => 'processing']);
        $t = new MockTransport();
        $this->assertSame('done', $this->sender($t)->processBatch($id, 1)['status']);
        $this->assertCount(6, $t->sent);
    }

    public function testIsolatedFailuresAreRecordedWithoutPausing(): void
    {
        $this->connectTenantSmtp();
        $this->seedContact('+911', ['email' => 'bad@example.com']);
        $this->seedContact('+912', ['email' => 'ok1@example.com']);
        $this->seedContact('+913', ['email' => 'ok2@example.com']);
        $id = $this->seedEmailCampaign();

        $r = $this->sender(new MockTransport(['bad@example.com'], '550 No such user'))->processBatch($id, 1);

        $this->assertSame('done', $r['status']);
        $this->assertSame(2, $r['sent']);
        $this->assertSame(1, $r['failed']);
        $bad = db_connect()->table('emails')->where('to_email', 'bad@example.com')->get()->getRowArray();
        $this->assertSame('failed', $bad['status']);
        $this->assertStringContainsString('550', $bad['error']);
    }

    public function testSegmentLimitsAudienceAndTenantsAreIsolated(): void
    {
        $this->connectTenantSmtp();
        $this->seedContact('+911', ['email' => 'won@example.com', 'status' => 'won']);
        $this->seedContact('+912', ['email' => 'new@example.com', 'status' => 'new']);
        $this->seedContact('+913', ['email' => 'other@example.com', 'status' => 'won', 'tenant_id' => 2]);
        $id = $this->seedEmailCampaign(['segment' => json_encode(['status' => 'won'])]);

        $t = new MockTransport();
        $this->sender($t)->processBatch($id, 1);

        $this->assertSame(['won@example.com'], array_column($t->sent, 'to'));
    }

    public function testNonProcessingCampaignIsLeftAlone(): void
    {
        $this->connectTenantSmtp();
        $this->seedContact('+911', ['email' => 'a@example.com']);
        $id = $this->seedEmailCampaign(['status' => 'paused']);

        $t = new MockTransport();
        $this->assertSame('paused', $this->sender($t)->processBatch($id, 1)['status']);
        $this->assertSame([], $t->sent);
    }
}
