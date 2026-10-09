<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\EmailModel;
use App\Services\Email\ContactEmailService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Phase L: email as a CRM channel (v1 — outbound only).
 *  - A send logs an `emails` row + mirrors an `email` activity to the timeline.
 *  - Missing-email and empty-subject are rejected (no row logged).
 *  - Per-tenant SMTP config overrides the platform env defaults.
 *
 * EMAIL_MOCK_MODE skips the SMTP transport so the logging path runs serverless.
 */
class ContactEmailServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['EMAIL_MOCK_MODE'] = 'true';
        $this->createCrmSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['EMAIL_MOCK_MODE']);
        parent::tearDown();
    }

    public function testSendLogsEmailAndTimelineActivity(): void
    {
        $cid = $this->seedContact('+919995000001', ['email' => 'lead@example.com']);

        $r = (new ContactEmailService())->sendToContact(1, $cid, 'Your quote', '<p>Hello there</p>', 7);

        $this->assertTrue($r['success']);
        $this->assertNotNull($r['email_id']);

        $email = db_connect()->table('emails')->where('id', $r['email_id'])->get()->getRowArray();
        $this->assertSame('sent', $email['status']);
        $this->assertSame('lead@example.com', $email['to_email']);
        $this->assertSame(7, (int) $email['sent_by']);

        $activity = db_connect()->table('activities')
            ->where('type', 'email')->where('related_type', 'contact')->where('related_id', $cid)
            ->get()->getRowArray();
        $this->assertNotNull($activity, 'email is mirrored to the contact timeline');
        $this->assertSame('Your quote', $activity['subject']);
    }

    public function testSendFailsWhenContactHasNoEmail(): void
    {
        $cid = $this->seedContact('+919995000002'); // no email

        $r = (new ContactEmailService())->sendToContact(1, $cid, 'Hi', '<p>Body</p>');

        $this->assertFalse($r['success']);
        $this->assertStringContainsString('no email', strtolower((string) $r['error']));
        $this->assertSame(0, (int) db_connect()->table('emails')->countAllResults());
    }

    public function testSendRejectsEmptySubject(): void
    {
        $cid = $this->seedContact('+919995000003', ['email' => 'x@example.com']);

        $r = (new ContactEmailService())->sendToContact(1, $cid, '   ', '<p>Body</p>');

        $this->assertFalse($r['success']);
        $this->assertSame(0, (int) db_connect()->table('emails')->countAllResults());
    }

    public function testSendWithAttachmentSucceeds(): void
    {
        // The attachment path threads through to transport without breaking the
        // log/timeline path (transport itself is skipped in mock mode).
        $cid = $this->seedContact('+919995000099', ['email' => 'q@example.com']);
        $r   = (new ContactEmailService())->sendToContact(1, $cid, 'Quote Q-0001', '<p>Attached</p>', 1, '/tmp/does-not-exist.pdf');

        $this->assertTrue($r['success']);
        $email = db_connect()->table('emails')->where('id', $r['email_id'])->get()->getRowArray();
        $this->assertSame('sent', $email['status']);
    }

    public function testForContactReturnsNewestFirst(): void
    {
        $cid = $this->seedContact('+919995000004', ['email' => 'y@example.com']);
        $svc = new ContactEmailService();
        $svc->sendToContact(1, $cid, 'First', '<p>1</p>');
        $svc->sendToContact(1, $cid, 'Second', '<p>2</p>');

        $rows = (new EmailModel())->forContact(1, $cid);
        $this->assertCount(2, $rows);
        $this->assertSame('Second', $rows[0]['subject'], 'newest first');
    }

    public function testResolveSmtpPrefersTenantConfig(): void
    {
        db_connect()->table('integrations')->insert([
            'tenant_id' => 1,
            'type'      => 'email_smtp',
            'status'    => 'active',
            'config'    => json_encode(['host' => 'smtp.tenant.test', 'port' => 465, 'from_email' => 'sales@tenant.test']),
        ]);

        $cfg = (new ContactEmailService())->resolveSmtp(1);

        $this->assertTrue($cfg['tenant']);
        $this->assertSame('smtp.tenant.test', $cfg['host']);
        $this->assertSame(465, $cfg['port']);
        $this->assertSame('ssl', $cfg['crypto'], 'port 465 defaults to ssl');
        $this->assertSame('sales@tenant.test', $cfg['from_email']);
    }

    public function testResolveSmtpFallsBackWhenNoTenantConfig(): void
    {
        $cfg = (new ContactEmailService())->resolveSmtp(1);
        $this->assertFalse($cfg['tenant'], 'no integration → platform env defaults');
    }
}
