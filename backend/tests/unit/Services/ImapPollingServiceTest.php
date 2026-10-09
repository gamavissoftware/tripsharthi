<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Email\ImapPollingService;
use App\Services\Email\Mailbox\MailboxReader;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * IMAP polling (Phase M). The mailbox transport is faked so the poll→ingest→
 * mark-seen logic is verified without a live IMAP server.
 */
class ImapPollingServiceTest extends CIUnitTestCase
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

    public function testPollIngestsEachMessageAndMarksAllSeen(): void
    {
        // One known sender, one unknown.
        $this->seedContact('+919997000001', ['email' => 'known@example.com']);

        $reader = new FakeMailboxReader([
            ['uid' => '1', 'from' => 'known@example.com',     'to' => 's@us.test', 'subject' => 'Re: hi', 'body' => 'b'],
            ['uid' => '2', 'from' => 'New One <new@x.test>',  'to' => 's@us.test', 'subject' => 'Hello',  'body' => 'b'],
        ]);

        $res = (new ImapPollingService())->poll(1, $reader);

        $this->assertSame(2, $res['fetched']);
        $this->assertSame(2, $res['ingested']);
        $this->assertSame(1, $res['created'], 'the unknown sender auto-creates a contact');
        $this->assertSame(['1', '2'], $reader->seen, 'every processed message is marked seen');
        // Two inbound emails recorded.
        $this->assertSame(2, (int) db_connect()->table('emails')->countAllResults());
    }

    public function testEmptyMailboxIsANoOp(): void
    {
        $reader = new FakeMailboxReader([]);
        $res = (new ImapPollingService())->poll(1, $reader);
        $this->assertSame(['fetched' => 0, 'ingested' => 0, 'created' => 0], $res);
        $this->assertSame([], $reader->seen);
    }

    public function testUnparseableSenderIsAcknowledgedNotIngested(): void
    {
        $reader = new FakeMailboxReader([
            ['uid' => '9', 'from' => 'not-an-email', 'to' => 's@us.test', 'subject' => 'x', 'body' => 'b'],
        ]);
        $res = (new ImapPollingService())->poll(1, $reader);

        $this->assertSame(0, $res['ingested']);
        $this->assertSame(['9'], $reader->seen, 'a junk sender is still marked seen so it is not retried forever');
        $this->assertSame(0, (int) db_connect()->table('emails')->countAllResults());
    }

    public function testLimitIsPassedThrough(): void
    {
        $reader = new FakeMailboxReader([]);
        (new ImapPollingService())->poll(1, $reader, 17);
        $this->assertSame(17, $reader->lastLimit);
    }
}

/** In-memory MailboxReader for tests. */
final class FakeMailboxReader implements MailboxReader
{
    public array $seen = [];
    public int $lastLimit = 0;

    /** @param list<array> $messages */
    public function __construct(private array $messages) {}

    public function fetchUnseen(int $limit = 50): array
    {
        $this->lastLimit = $limit;
        return $this->messages;
    }

    public function markSeen(string $uid): void
    {
        $this->seen[] = $uid;
    }
}
