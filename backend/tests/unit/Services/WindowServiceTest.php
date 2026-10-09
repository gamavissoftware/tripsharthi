<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\WindowClosedException;
use App\Models\ConversationModel;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\BlankSlateSchema;

/**
 * WindowService unit tests.
 *
 * Uses SQLite3 :memory: test database.
 * All tests inject a fixed "now" timestamp so window calculations are
 * deterministic — no flakiness from real time().
 */
class WindowServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use BlankSlateSchema;

    protected $migrate = false;
    protected $refresh = true;

    /** Fixed "now" used across all tests: 2026-01-15 12:00:00 UTC */
    private int $now;
    private ConversationModel $convModel;
    /** Counter ensures every insertConversation() call gets a unique wa_number. */
    private static int $waSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
        $this->createTestSchema();
        $this->now       = mktime(12, 0, 0, 1, 15, 2026); // 2026-01-15 12:00:00 UTC
        $this->convModel = new ConversationModel();
    }

    private function createTestSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;

        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (
            id INTEGER PRIMARY KEY,
            name TEXT, slug TEXT, plan TEXT DEFAULT 'free',
            status TEXT DEFAULT 'active', mode TEXT DEFAULT 'saas',
            settings TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            contact_id INTEGER,
            phone_number_id INTEGER,
            wa_number TEXT NOT NULL,
            contact_name TEXT,
            window_expires_at TEXT,
            last_message_at TEXT,
            last_inbound_at TEXT,
            unread_count INTEGER DEFAULT 0,
            status TEXT DEFAULT 'open',
            assigned_user_id INTEGER,
            created_at TEXT, updated_at TEXT,
            UNIQUE(tenant_id, wa_number)
        )");
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");
    }

    private function makeService(): WindowService
    {
        return new WindowService(new ConversationModel(), $this->now);
    }

    private function insertConversation(array $data): int
    {
        // Generate a unique wa_number per call to avoid UNIQUE(tenant_id, wa_number) collisions
        // across tests that share the in-memory SQLite DB.
        self::$waSeq++;
        $defaultWa = '+9199999' . str_pad((string) self::$waSeq, 5, '0', STR_PAD_LEFT);

        db_connect()->table('conversations')->insert(array_merge([
            'tenant_id'        => 1,
            'wa_number'        => $defaultWa,
            'window_expires_at'=> null,
            'unread_count'     => 0,
            'status'           => 'open',
            'created_at'       => date('Y-m-d H:i:s', $this->now),
            'updated_at'       => date('Y-m-d H:i:s', $this->now),
        ], $data));
        return (int) db_connect()->insertID();
    }

    // ------------------------------------------------------------------
    // isOpen()
    // ------------------------------------------------------------------

    public function testIsOpenReturnsFalseWhenWindowIsNull(): void
    {
        $id = $this->insertConversation(['window_expires_at' => null]);
        $this->assertFalse($this->makeService()->isOpen($id));
    }

    public function testIsOpenReturnsFalseWhenWindowExpired(): void
    {
        // Expired 1 hour ago
        $expired = date('Y-m-d H:i:s', $this->now - 3600);
        $id = $this->insertConversation(['window_expires_at' => $expired]);
        $this->assertFalse($this->makeService()->isOpen($id));
    }

    public function testIsOpenReturnsTrueWhenWindowInFuture(): void
    {
        // Expires 1 hour from now
        $future = date('Y-m-d H:i:s', $this->now + 3600);
        $id = $this->insertConversation(['window_expires_at' => $future]);
        $this->assertTrue($this->makeService()->isOpen($id));
    }

    public function testIsOpenReturnsFalseWhenExactlyExpired(): void
    {
        // Expires at exactly now — "now" is NOT within window
        $atNow = date('Y-m-d H:i:s', $this->now);
        $id = $this->insertConversation(['window_expires_at' => $atNow]);
        $this->assertFalse($this->makeService()->isOpen($id));
    }

    // ------------------------------------------------------------------
    // getSendMode()
    // ------------------------------------------------------------------

    public function testGetSendModeReturnsFreeFormWhenOpen(): void
    {
        $future = date('Y-m-d H:i:s', $this->now + 3600);
        $id = $this->insertConversation(['window_expires_at' => $future]);
        $this->assertSame('free_form', $this->makeService()->getSendMode($id));
    }

    public function testGetSendModeReturnsTemplateOnlyWhenClosed(): void
    {
        $id = $this->insertConversation(['window_expires_at' => null]);
        $this->assertSame('template_only', $this->makeService()->getSendMode($id));
    }

    // ------------------------------------------------------------------
    // secondsRemaining()
    // ------------------------------------------------------------------

    public function testSecondsRemainingIsZeroWhenNull(): void
    {
        $id = $this->insertConversation(['window_expires_at' => null]);
        $this->assertSame(0, $this->makeService()->secondsRemaining($id));
    }

    public function testSecondsRemainingIsZeroWhenExpired(): void
    {
        $expired = date('Y-m-d H:i:s', $this->now - 100);
        $id = $this->insertConversation(['window_expires_at' => $expired]);
        $this->assertSame(0, $this->makeService()->secondsRemaining($id));
    }

    public function testSecondsRemainingIsPositiveWhenOpen(): void
    {
        $future = date('Y-m-d H:i:s', $this->now + 3600);
        $id     = $this->insertConversation(['window_expires_at' => $future]);
        $secs   = $this->makeService()->secondsRemaining($id);
        $this->assertSame(3600, $secs);
    }

    // ------------------------------------------------------------------
    // refreshWindow()
    // ------------------------------------------------------------------

    public function testRefreshWindowSetsExpiryTo24HoursFromNow(): void
    {
        $id      = $this->insertConversation(['window_expires_at' => null]);
        $service = $this->makeService();
        $newExp  = $service->refreshWindow($id);

        $expectedTs = $this->now + WindowService::WINDOW_SECONDS;
        $expected   = date('Y-m-d H:i:s', $expectedTs);

        $this->assertSame($expected, $newExp);
        $this->assertTrue($service->isOpen($id));
    }

    public function testRefreshWindowPushesExistingWindowForward(): void
    {
        // Window was set 12 hours ago (still open but 12h left)
        $existing = date('Y-m-d H:i:s', $this->now + (12 * 3600));
        $id       = $this->insertConversation(['window_expires_at' => $existing]);
        $service  = $this->makeService();

        $newExp = $service->refreshWindow($id);

        // Should now be 24h from "now", not 24h from the old expiry
        $expected = date('Y-m-d H:i:s', $this->now + WindowService::WINDOW_SECONDS);
        $this->assertSame($expected, $newExp);
    }

    public function testRefreshWindowReopensClosedWindow(): void
    {
        $expired = date('Y-m-d H:i:s', $this->now - 100);
        $id      = $this->insertConversation(['window_expires_at' => $expired]);
        $service = $this->makeService();

        $this->assertFalse($service->isOpen($id));

        $service->refreshWindow($id);

        $this->assertTrue($service->isOpen($id));
    }

    public function testWindowConstantIs86400(): void
    {
        $this->assertSame(86400, WindowService::WINDOW_SECONDS);
    }

    // ------------------------------------------------------------------
    // assertFreeFormAllowed()
    // ------------------------------------------------------------------

    public function testAssertFreeFormAllowedDoesNotThrowWhenOpen(): void
    {
        $future = date('Y-m-d H:i:s', $this->now + 3600);
        $id     = $this->insertConversation(['window_expires_at' => $future]);

        // Should not throw
        $this->makeService()->assertFreeFormAllowed($id);
        $this->assertTrue(true); // reached here without exception
    }

    public function testAssertFreeFormAllowedThrowsWhenWindowNull(): void
    {
        $id = $this->insertConversation(['window_expires_at' => null]);
        $this->expectException(WindowClosedException::class);
        $this->makeService()->assertFreeFormAllowed($id);
    }

    public function testAssertFreeFormAllowedThrowsWhenExpired(): void
    {
        $expired = date('Y-m-d H:i:s', $this->now - 100);
        $id      = $this->insertConversation(['window_expires_at' => $expired]);
        $this->expectException(WindowClosedException::class);
        $this->makeService()->assertFreeFormAllowed($id);
    }

    public function testWindowClosedExceptionCarriesConversationId(): void
    {
        $id = $this->insertConversation(['window_expires_at' => null]);
        try {
            $this->makeService()->assertFreeFormAllowed($id);
            $this->fail('Expected WindowClosedException was not thrown.');
        } catch (WindowClosedException $e) {
            $this->assertSame($id, $e->getConversationId());
        }
    }
}
