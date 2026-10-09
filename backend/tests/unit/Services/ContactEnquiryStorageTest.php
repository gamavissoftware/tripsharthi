<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Marketing\ContactEnquiryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Storage behaviour against real MySQL (group "mysql"). */
#[Group('mysql')]
final class ContactEnquiryStorageTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('contact_enquiries')->truncate();
    }

    private function in(array $o = []): array { return $o + ['name' => 'Asha Rao', 'email' => 'asha@example.com', 'topic' => 'demo', 'message' => 'Show me the GST features', 'source' => 'contact.html']; }

    private array $sent = [];
    private function mailer(bool $ok = true): callable { return function (string $to, string $subject, string $html, string $text, ?string $reply) use ($ok): bool { $this->sent[] = compact('to', 'subject', 'html', 'text', 'reply'); return $ok; }; }

    public function testEmailGoesToTheTeamWithReplyToAndEverythingEscaped(): void
    {
        $r = (new ContactEnquiryService($this->mailer()))->submit($this->in(['name' => 'Asha <script>alert(1)</script>', 'message' => '<img src=x onerror=alert(1)>']));
        $m = $this->sent[0];
        $this->assertSame(['manglesh@gamavis.com', 'asha@example.com'], [$m['to'], $m['reply']]);
        $this->assertSame('TripSarthi website: Demo request from Asha <script>alert(1)</script>', $m['subject']);        // subject is plain text, not HTML
        $this->assertStringNotContainsString('<script>', $m['html']);
        $this->assertStringNotContainsString('<img src=x', $m['html']);
        $this->assertStringContainsString('&lt;script&gt;', $m['html']);
        $row = db_connect()->table('contact_enquiries')->where('id', $r['id'])->get()->getRowArray();
        $this->assertNotNull($row['notified_at']); $this->assertNull($row['notify_error']);
    }

    public function testMessageIsSavedEvenWhenTheEmailCannotBeSent(): void
    {
        $r = (new ContactEnquiryService($this->mailer(false)))->submit($this->in(), '203.0.113.7', 'UnitTest/1.0');
        $this->assertSame('stored', $r['status']);
        $row = db_connect()->table('contact_enquiries')->where('id', $r['id'])->get()->getRowArray();
        $this->assertSame(['new', 'asha@example.com', null], [$row['status'], $row['email'], $row['notified_at']]);
        $this->assertNotEmpty($row['notify_error']);                                // recorded for `contact:list --retry`
        $this->assertNotSame('203.0.113.7', $row['ip_hash']);                        // only a salted hash of the address is kept
        $this->assertSame(32, strlen((string) $row['ip_hash']));
    }

    public function testHoneypotAndSuperFastFillAreDroppedSilently(): void
    {
        $svc = new ContactEnquiryService($this->mailer()); $now = 1_800_000_000_000;
        $this->assertSame('ignored', $svc->submit($this->in(['website' => 'http://spam.example']), '', '', $now)['status']);
        $this->assertSame('ignored', $svc->submit($this->in(['t' => $now - 300]), '', '', $now)['status']);            // 0.3 s after the page loaded
        $this->assertSame('stored', $svc->submit($this->in(['t' => $now - 9000]), '', '', $now)['status']);
        $this->assertSame(1, db_connect()->table('contact_enquiries')->countAllResults());
    }

    public function testSameMessageTwiceIsStoredOnceAndLinkSpamIsNeverEmailed(): void
    {
        $svc = new ContactEnquiryService($this->mailer());
        $this->assertSame('stored', $svc->submit($this->in())['status']);
        $this->assertSame('duplicate', $svc->submit($this->in())['status']);
        $spam = $svc->submit($this->in(['email' => 'x@y.co', 'message' => 'http://a.example http://b.example http://c.example']));
        $this->assertSame('spam', $spam['status']);
        $row = db_connect()->table('contact_enquiries')->where('id', $spam['id'])->get()->getRowArray();
        $this->assertSame(['spam', null, null], [$row['status'], $row['notified_at'], $row['notify_error']]);       // spam: stored for review, no email attempted
        $this->assertCount(1, $this->sent);                                                                          // only the genuine message was emailed
        $this->assertSame(2, db_connect()->table('contact_enquiries')->countAllResults());
    }

    public function testInvalidInputIsRefusedAndNothingStored(): void
    {
        try { (new ContactEnquiryService())->submit($this->in(['email' => 'nope'])); $this->fail('invalid email accepted'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('email', strtolower($e->getMessage())); }
        $this->assertSame(0, db_connect()->table('contact_enquiries')->countAllResults());
    }

    public function testRetryOnlyTouchesUnsentRealEnquiries(): void
    {
        (new ContactEnquiryService($this->mailer(false)))->submit($this->in());          // mail is down: saved, not emailed
        $this->assertSame(0, (new ContactEnquiryService($this->mailer(false)))->retryUnsent());
        $this->assertSame(1, (new ContactEnquiryService($this->mailer(true)))->retryUnsent());   // mail is back: the retry sends it
        $this->assertSame(0, (new ContactEnquiryService($this->mailer(true)))->retryUnsent());   // and never twice
        db_connect()->table('contact_enquiries')->update(['status' => 'handled', 'notified_at' => null]);
        $this->assertFalse((new ContactEnquiryService($this->mailer(true)))->notify(1));          // handled enquiries are never re-emailed
    }
}
