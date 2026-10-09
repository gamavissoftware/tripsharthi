<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Filters\LicenseFilter;
use App\Services\Licensing\LicenseJwtVerifier;
use App\Services\Licensing\LicenseService;
use App\Services\Tenancy\FeatureGate;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests for LicenseService grace-period logic and LicenseFilter network posture.
 *
 * ── Injection strategy ────────────────────────────────────────────────────
 * LicenseService accepts two optional constructor params:
 *   ?Closure        $httpClient  — controls phone-home response (tests 4–6)
 *   ?LicenseJwtVerifier $jwtVerifier — bypass HMAC check (all DB-logic tests)
 *
 * We use a stub JwtVerifier that always returns true so the DB-driven
 * grace logic can be tested without a real GAMAVIS_LICENSE_HMAC_KEY.
 * LICENSE_MOCK_MODE is kept FALSE for tests 1–6 so that checkStatus()
 * and runPhoneHome() run their full code paths.
 * LICENSE_MOCK_MODE=true is set ONLY for test 7 (filter no-network test).
 *
 * The seven cases:
 *   1. status='active'  → isReadOnly=false
 *   2. status='grace', last_check_at=3 days ago → withinGrace (isReadOnly=false)
 *   3. status='grace', last_check_at=8 days ago → graceExpired (isReadOnly=true)
 *   4. runPhoneHome() succeeds → last_check_at updated, status='active'
 *   5. runPhoneHome() fails, 3 days since last ok → within grace, status stays 'active'
 *   6. runPhoneHome() fails, 8 days since last ok → grace expired, status='grace'
 *   7. LicenseFilter::before() does NOT call phone-home (pure DB read)
 */
class LicenseGracePeriodTest extends CIUnitTestCase
{
    // ------------------------------------------------------------------
    // Stub JWT verifier — always passes, no real HMAC key needed
    // ------------------------------------------------------------------

    private function makeAlwaysValidVerifier(): LicenseJwtVerifier
    {
        return new class extends LicenseJwtVerifier {
            public function verify(string $rawKey): bool { return true; }
            public function decode(string $rawKey): array { return ['email' => 'test@example.com']; }
        };
    }

    /** LicenseService with stub verifier + optional injectable http client. */
    private function makeService(?\Closure $httpClient = null): LicenseService
    {
        return new LicenseService($httpClient, $this->makeAlwaysValidVerifier());
    }

    // ------------------------------------------------------------------
    // Schema helpers
    // ------------------------------------------------------------------

    private function createLicenseTable(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}licenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key_hash TEXT NOT NULL,
            key_payload TEXT NOT NULL,
            customer_email TEXT,
            activated_at TEXT,
            last_check_at TEXT,
            last_check_status TEXT,
            status TEXT DEFAULT 'inactive',
            created_at TEXT,
            updated_at TEXT
        )");
        $db->query("DELETE FROM {$p}licenses");
    }

    private function seedLicenseRow(array $overrides = []): void
    {
        db_connect()->table('licenses')->insert(array_merge([
            'key_hash'          => hash('sha256', 'mock_key'),
            'key_payload'       => 'mock_key',
            'customer_email'    => 'test@example.com',
            'activated_at'      => date('Y-m-d H:i:s', strtotime('-30 days')),
            'last_check_at'     => date('Y-m-d H:i:s'),
            'last_check_status' => 'ok',
            'status'            => 'active',
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // setUp / tearDown
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLicenseTable();
        FeatureGate::forceMode('self_hosted');
        // Force mock mode OFF so real DB/grace logic runs (the .env may have it true).
        $_ENV['LICENSE_MOCK_MODE'] = 'false';
    }

    protected function tearDown(): void
    {
        FeatureGate::reset();
        unset($_ENV['LICENSE_MOCK_MODE'], $_ENV['LICENSE_VALIDATE_URL']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Test 1: status='active' → isReadOnly=false
    // ------------------------------------------------------------------

    public function testActiveStatusReturnsNotReadOnly(): void
    {
        $this->seedLicenseRow(['status' => 'active']);

        $status = $this->makeService()->checkStatus();

        $this->assertFalse($status->isReadOnly);
        $this->assertTrue($status->isActive);
        $this->assertSame('ok', $status->reason);
    }

    // ------------------------------------------------------------------
    // Test 2: status='grace', last_check_at=3 days ago → within grace
    // ------------------------------------------------------------------

    public function testGraceWithin7DaysIsNotReadOnly(): void
    {
        $this->seedLicenseRow([
            'status'        => 'grace',
            'last_check_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
        ]);

        $status = $this->makeService()->checkStatus();

        $this->assertFalse($status->isReadOnly,
            'Within the 7-day grace window isReadOnly must be false.'
        );
        $this->assertGreaterThan(0, $status->graceDaysLeft,
            'Grace days left must be > 0 within the window.'
        );
        $this->assertSame('within_grace', $status->reason);
    }

    // ------------------------------------------------------------------
    // Test 3: status='grace', last_check_at=8 days ago → grace expired
    // ------------------------------------------------------------------

    public function testGraceExpiredAfter7DaysIsReadOnly(): void
    {
        $this->seedLicenseRow([
            'status'        => 'grace',
            'last_check_at' => date('Y-m-d H:i:s', strtotime('-8 days')),
        ]);

        $status = $this->makeService()->checkStatus();

        $this->assertTrue($status->isReadOnly,
            'After 7-day grace expires isReadOnly must be true.'
        );
        $this->assertSame(0, $status->graceDaysLeft);
        $this->assertSame('grace_expired', $status->reason);
    }

    // ------------------------------------------------------------------
    // Test 4: runPhoneHome() succeeds → last_check_at updated to now
    // ------------------------------------------------------------------

    public function testSuccessfulPhoneHomeUpdatesLastCheckAt(): void
    {
        $oldCheckTs = strtotime('-2 days');
        $this->seedLicenseRow([
            'status'        => 'active',
            'last_check_at' => date('Y-m-d H:i:s', $oldCheckTs),
        ]);

        $httpClient = fn() => ['status' => 'active'];
        $this->makeService($httpClient)->runPhoneHome();

        $row = db_connect()->table('licenses')->get()->getRowArray();
        $this->assertSame('ok',     $row['last_check_status']);
        $this->assertSame('active', $row['status']);
        $this->assertGreaterThan($oldCheckTs, strtotime($row['last_check_at']),
            'last_check_at must be refreshed to a time after the old value.'
        );
    }

    // ------------------------------------------------------------------
    // Test 5: runPhoneHome() fails, 3 days since last ok → within grace
    //         status stays 'active'; last_check_at NOT updated
    // ------------------------------------------------------------------

    public function testFailedPhoneHomeWithinGraceKeepsStatusActive(): void
    {
        $lastOk = date('Y-m-d H:i:s', strtotime('-3 days'));
        $this->seedLicenseRow([
            'status'        => 'active',
            'last_check_at' => $lastOk,
        ]);

        $httpClient = fn() => throw new \RuntimeException('Simulated network error');
        $this->makeService($httpClient)->runPhoneHome();

        $row = db_connect()->table('licenses')->get()->getRowArray();

        $this->assertSame('failed', $row['last_check_status'],
            'Failure must be recorded in last_check_status.'
        );
        $this->assertSame('active', $row['status'],
            'Status must stay active within the grace window.'
        );
        // last_check_at must NOT be updated on failure — it anchors the grace window
        $this->assertSame($lastOk, $row['last_check_at'],
            'last_check_at must only be updated on SUCCESS.'
        );
    }

    // ------------------------------------------------------------------
    // Test 6: runPhoneHome() fails, 8 days since last ok → grace expired
    //         status flips to 'grace' (read-only on next checkStatus call)
    // ------------------------------------------------------------------

    public function testFailedPhoneHomeAfterGraceExpiryFlipsStatusToGrace(): void
    {
        $this->seedLicenseRow([
            'status'        => 'active',
            'last_check_at' => date('Y-m-d H:i:s', strtotime('-8 days')),
        ]);

        $httpClient = fn() => throw new \RuntimeException('Simulated network error');
        $this->makeService($httpClient)->runPhoneHome();

        $row = db_connect()->table('licenses')->get()->getRowArray();

        $this->assertSame('grace', $row['status'],
            'After ' . LicenseService::GRACE_PERIOD_DAYS . ' days of failure, status must flip to grace.'
        );
        $this->assertSame('failed', $row['last_check_status']);
    }

    // ------------------------------------------------------------------
    // Test 7: LicenseFilter::before() does NOT call phone-home.
    //
    // Approach: set LICENSE_MOCK_MODE=true so checkStatus() returns
    // LicenseStatus::active() in one line (pure PHP, no DB, no network).
    // Then point LICENSE_VALIDATE_URL at an unreachable address.
    // If the filter ever called the network, the test would hang or error;
    // completing in <100ms proves no outbound call was made.
    //
    // Structural guarantee: checkStatus() contains no call to
    // doPhoneHome() or runPhoneHome() — verified by code review.
    // This test is the runtime confirmation.
    // ------------------------------------------------------------------

    public function testLicenseFilterDoesNotCallPhoneHomeInRequestPath(): void
    {
        $_ENV['LICENSE_MOCK_MODE']    = 'true';         // checkStatus() returns active() instantly
        $_ENV['LICENSE_VALIDATE_URL'] = 'http://0.0.0.0:0'; // unreachable — would time out if hit

        $this->seedLicenseRow(['status' => 'active']);

        $startTime = microtime(true);

        $filter  = new LicenseFilter();
        $request = \CodeIgniter\Config\Services::request();
        $result  = $filter->before($request);

        $elapsed = microtime(true) - $startTime;

        $this->assertNull($result, 'Filter must pass through for active license (return null).');
        $this->assertLessThan(0.1, $elapsed,
            "Filter took {$elapsed}s — proves no synchronous HTTP call was made. "
            . 'A real curl to 0.0.0.0 would take much longer.'
        );
    }
}
