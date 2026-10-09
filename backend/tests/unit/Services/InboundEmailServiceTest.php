<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Email\InboundEmailService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Inbound email ingestion (Phase L 2-way threading).
 *  - Matches the sender to a contact → logs an inbound emails row + timeline activity.
 *  - Unknown senders auto-create an email-only contact (Phase M follow-up); matching is tenant-scoped.
 *  - Address parsing handles "Name <a@b.com>".
 */
class InboundEmailServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCrmSchema();
    }

    // ── Address parsing ─────────────────────────────────────────────────

    public function testParseAddressExtractsBareEmail(): void
    {
        $this->assertSame('asha@example.com', InboundEmailService::parseAddress('Asha Rao <Asha@Example.com>'));
        $this->assertSame('a@b.com', InboundEmailService::parseAddress('a@b.com'));
        $this->assertSame('', InboundEmailService::parseAddress('not an email'));
    }

    // ── Ingestion ───────────────────────────────────────────────────────

    public function testInboundFromKnownContactIsLoggedAndThreaded(): void
    {
        $cid = $this->seedContact('+919997000001', ['email' => 'lead@example.com']);

        $r = (new InboundEmailService())->ingest(1, 'Lead <lead@example.com>', 'sales@us.test', 'Re: your quote', '<p>Sounds good</p>');

        $this->assertTrue($r['matched']);
        $email = db_connect()->table('emails')->where('id', $r['email_id'])->get()->getRowArray();
        $this->assertSame('in', $email['direction']);
        $this->assertSame('lead@example.com', $email['from_email']);
        $this->assertSame('Re: your quote', $email['subject']);

        $activity = db_connect()->table('activities')
            ->where('type', 'email')->where('related_type', 'contact')->where('related_id', $cid)
            ->get()->getRowArray();
        $this->assertNotNull($activity, 'inbound email threads onto the contact timeline');
    }

    public function testUnknownSenderAutoCreatesEmailOnlyContact(): void
    {
        $r = (new InboundEmailService())->ingest(1, 'Stranger Jones <stranger@elsewhere.com>', 'sales@us.test', 'Hi', 'body');

        $this->assertTrue($r['matched']);
        $this->assertTrue($r['created']);

        $c = db_connect()->table('contacts')->where('id', $r['contact_id'])->get()->getRowArray();
        $this->assertSame('stranger@elsewhere.com', $c['email']);
        $this->assertNull($c['wa_number'], 'auto-created contact is email-only (NULL wa_number)');
        $this->assertSame('email_inbound', $c['source']);
        $this->assertSame('Stranger Jones', $c['name']);
        // The message is captured against the new contact.
        $this->assertSame(1, (int) db_connect()->table('emails')->where('contact_id', $r['contact_id'])->countAllResults());
    }

    public function testSecondEmailFromSameSenderReusesContact(): void
    {
        $a = (new InboundEmailService())->ingest(1, 'repeat@elsewhere.com', 'sales@us.test', 'One', 'b');
        $b = (new InboundEmailService())->ingest(1, 'repeat@elsewhere.com', 'sales@us.test', 'Two', 'b');

        $this->assertTrue($a['created']);
        $this->assertFalse($b['created'], 'second email dedupes onto the same contact');
        $this->assertSame($a['contact_id'], $b['contact_id']);
        $this->assertSame(1, (int) db_connect()->table('contacts')->where('email', 'repeat@elsewhere.com')->countAllResults());
    }

    public function testMatchingIsTenantScoped(): void
    {
        // Contact with this email belongs to tenant 2; a tenant-1 ingest must NOT
        // attach to it — it creates a separate tenant-1 contact instead.
        db_connect()->table('contacts')->insert(['id' => 50, 'tenant_id' => 2, 'wa_number' => '+10', 'name' => 'T2', 'email' => 'shared@example.com']);

        $r = (new InboundEmailService())->ingest(1, 'shared@example.com', 'sales@us.test', 'Hi', 'body');

        $this->assertTrue($r['created'], 'creates a tenant-1 contact rather than matching tenant-2');
        $this->assertNotSame(50, $r['contact_id']);
        $new = db_connect()->table('contacts')->where('id', $r['contact_id'])->get()->getRowArray();
        $this->assertSame(1, (int) $new['tenant_id']);
    }

    public function testMissingSubjectFallsBack(): void
    {
        $this->seedContact('+919997000003', ['email' => 'lead2@example.com']);

        $r = (new InboundEmailService())->ingest(1, 'lead2@example.com', 'sales@us.test', '   ', 'body');

        $email = db_connect()->table('emails')->where('id', $r['email_id'])->get()->getRowArray();
        $this->assertSame('(no subject)', $email['subject']);
    }
}
